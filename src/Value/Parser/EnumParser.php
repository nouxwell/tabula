<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value\Parser;

use BackedEnum;
use Closure;
use Nouxwell\Tabula\Contract\TranslatableEnum;
use Nouxwell\Tabula\Exception\ParseException;
use Nouxwell\Tabula\Exception\ValueException;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Value\ParseContext;
use Nouxwell\Tabula\Value\ValueParser;
use Stringable;
use UnitEnum;

/**
 * The parser for enum fields and fixed option sets.
 *
 * The user sees the TRANSLATED LABEL in the cell ("Open"), while the database expects the
 * enum itself (`Status::Open`). This class turns the translation back around; the matching
 * order starts from what `EnumFormatter` wrote:
 *  1. Each case's translated label — that is the text we write into the template's drop-down
 *     list, so this is where the round trip closes. Key resolution follows the same chain:
 *     `TranslatableEnum::translationKey()` → `label()` → `value`/`name`.
 *  2. The enum's `value` — for when the file was filled in by a developer or by another
 *     system.
 *  3. The case name — the last resort.
 * All three steps are SEPARATE PASSES: if one case's label collides with another case's
 * `value`, the label wins, because that is the text the user sees.
 *
 * Comparison is trimmed and case-insensitive ("AÇIK" and "açık " match too); beyond that
 * there is no leniency. `EnumFormatter` prints a value it does not recognise as it is and
 * moves on — in an export that is merely an ugly cell. Here the same behaviour would mean a
 * status that is not on the list leaking into the database; a value that does not match
 * throws an exception, and the message lists the VALID OPTIONS.
 */
final class EnumParser implements ValueParser
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Enum === $type || FieldType::Options === $type;
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        $enumClass = $field->getEnumClass();

        // A configuration mistake should blow up before the data does: a field set up with
        // the wrong class name is a setup error, not a row error — it must not be repeated
        // 5000 times across 5000 rows.
        if (null !== $enumClass && !is_a($enumClass, UnitEnum::class, true)) {
            throw ValueException::notAnEnum($field, $enumClass);
        }

        if (StringParser::isBlank($raw)) {
            return null;
        }

        return FieldType::Options === $field->getType()
            ? $this->parseOption($raw, $field, $context)
            : $this->parseEnum($raw, $field, $enumClass, $context);
    }

    /** @param class-string|null $enumClass */
    private function parseEnum(mixed $raw, Field $field, ?string $enumClass, ParseContext $context): UnitEnum
    {
        // In re-imports from an array source the value may already be an instance.
        if ($raw instanceof UnitEnum) {
            return $raw;
        }

        $cases = $this->cases($enumClass);
        $needle = $this->needle($raw);

        if (null !== $needle) {
            foreach ($cases as $case) {
                if ($needle === $this->fold($context->trans($this->translationKey($case)))) {
                    return $case;
                }
            }

            foreach ($cases as $case) {
                if ($case instanceof BackedEnum && $needle === $this->fold((string) $case->value)) {
                    return $case;
                }
            }

            foreach ($cases as $case) {
                if ($needle === $this->fold($case->name)) {
                    return $case;
                }
            }
        }

        throw ParseException::notAnOption($field, StringParser::describe($raw), $this->enumLabels($cases, $context));
    }

    /**
     * In an option set it maps FROM THE LABEL TO THE KEY; the value written to the database
     * is the key.
     *
     * A limit: if the options were given as a closure (`Field::options($key, fn ($row) => …)`)
     * the closure expects a ROW, but during import there is no row YET — the closure is
     * called with `null`. Option lists derived from the row can therefore not be used on the
     * import side; if the closure cannot work with a `null` row it throws a `TypeError`, and
     * that is DELIBERATELY not caught: a configuration mistake has to surface as a setup
     * error, not as a meaningless "not on the list" message repeated on every row.
     */
    private function parseOption(mixed $raw, Field $field, ParseContext $context): int|string
    {
        $options = $field->getOptions();

        if ($options instanceof Closure) {
            $options = $options(null);
        }

        $options = is_array($options) ? $options : [];
        $needle = $this->needle($raw);

        if (null !== $needle) {
            // 1. The translated label: the text written into the template's drop-down list.
            foreach ($options as $key => $label) {
                if ($needle === $this->fold($context->trans($label))) {
                    return $key;
                }
            }

            // 2. The raw label: the translation key itself, or plain text (a key with no
            //    entry in the catalogue passes through `trans()` unchanged, but it is still
            //    tried explicitly).
            foreach ($options as $key => $label) {
                if ($needle === $this->fold($label)) {
                    return $key;
                }
            }

            // 3. The key itself: people filling in the file by hand mostly write the code.
            //    This is the option-set counterpart of the `value` step on the enum side.
            foreach ($options as $key => $label) {
                if ($needle === $this->fold((string) $key)) {
                    return $key;
                }
            }
        }

        throw ParseException::notAnOption($field, StringParser::describe($raw), $this->optionLabels($options, $context));
    }

    /**
     * @param class-string|null $enumClass
     *
     * @return list<UnitEnum>
     */
    private function cases(?string $enumClass): array
    {
        if (null === $enumClass || !is_a($enumClass, UnitEnum::class, true)) {
            return [];
        }

        return array_values($enumClass::cases());
    }

    /** The SAME chain as in `EnumFormatter`; if the two sides drift apart the round trip does not close. */
    private function translationKey(UnitEnum $case): string
    {
        if ($case instanceof TranslatableEnum) {
            return $case->translationKey();
        }

        // The convention of the system this replaces: every enum hands over its key
        // through its own `label()` method.
        if (method_exists($case, 'label')) {
            return (string) $case->label();
        }

        return $case instanceof BackedEnum ? (string) $case->value : $case->name;
    }

    /**
     * @param list<UnitEnum> $cases
     *
     * @return list<string>
     */
    private function enumLabels(array $cases, ParseContext $context): array
    {
        return array_values(array_unique(array_map(
            fn (UnitEnum $case): string => $context->trans($this->translationKey($case)),
            $cases,
        )));
    }

    /**
     * @param array<int|string, string> $options
     *
     * @return list<string>
     */
    private function optionLabels(array $options, ParseContext $context): array
    {
        return array_values(array_unique(array_map(
            static fn (string $label): string => $context->trans($label),
            $options,
        )));
    }

    /**
     * The text to search for; `null` for types that have no comparable representation.
     *
     * In integer-backed enums the cell arrives as a NUMBER (Excel does not turn a `3` into
     * text), which is why numeric values are reduced to text and looked up as well.
     */
    private function needle(mixed $raw): ?string
    {
        if (is_string($raw) || is_int($raw) || is_float($raw) || $raw instanceof Stringable) {
            return $this->fold(StringParser::describe($raw));
        }

        return null;
    }

    private function fold(string $value): string
    {
        return mb_strtolower(StringParser::clean($value), 'UTF-8');
    }
}
