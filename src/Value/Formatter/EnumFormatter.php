<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Formatter;

use BackedEnum;
use Balin\Tabula\Contract\TranslatableEnum;
use Balin\Tabula\Exception\ValueException;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\Cell;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\ValueFormatter;
use Closure;
use Stringable;
use TypeError;
use UnitEnum;

/**
 * Enum fields and fixed option sets.
 *
 * The translation key is resolved in three steps — in order, the first one that holds wins:
 *  1. `TranslatableEnum::translationKey()` — the preferred route for new enums.
 *  2. A `label()` method — the convention found in the 200-plus enums of the system this
 * replaces; it is supported so that they keep working without a single line
 *     being changed.
 *  3. `BackedEnum::$value` / `UnitEnum::$name` — the last resort when there is no contract
 *     at all.
 *
 * The resulting key goes through `trans()` in EVERY case. When the key is not in the
 * catalogue, `ArrayTranslator` returns the key itself, so the cell is never left empty — in
 * the legacy export a missing translation meant an empty column.
 *
 * A value of type `Options` is looked up in the field's option map; the label found there is
 * passed through `trans()` as well, so option sets can carry translation keys.
 */
final class EnumFormatter implements ValueFormatter
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Enum === $type || FieldType::Options === $type;
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        $enumClass = $field->getEnumClass();

        // A configuration mistake should blow up before the data does: a field set up with
        // the wrong class name must surface on the first attempt, not on the first row.
        if (null !== $enumClass && !is_a($enumClass, UnitEnum::class, true)) {
            throw ValueException::notAnEnum($field, $enumClass);
        }

        if (null === $raw) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        if (FieldType::Options === $field->getType()) {
            return $this->formatOption($raw, $field, $row, $context, $align);
        }

        $case = $this->hydrate($raw, $enumClass);

        // A value that does not fit the enum: instead of inventing a label, the raw form is
        // written.
        if (null === $case) {
            return Cell::text($this->rawText($raw), $align);
        }

        return Cell::text($context->trans($this->translationKey($case)), $align);
    }

    /**
     * Turns the raw value into an enum instance.
     *
     * Doctrine `enumType` columns mostly return an instance already; but in scalar
     * projections and array sources the plain `value` arrives — if the field declares an
     * enum class, it is rehydrated from there.
     *
     * @param class-string|null $enumClass
     */
    private function hydrate(mixed $raw, ?string $enumClass): ?UnitEnum
    {
        if ($raw instanceof UnitEnum) {
            return $raw;
        }

        if (null === $enumClass || !is_a($enumClass, BackedEnum::class, true)) {
            return null;
        }

        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        try {
            return $enumClass::tryFrom($raw);
        } catch (TypeError) {
            // An int was handed to a string-backed enum (or the other way round):
            // `strict_types` turns that into a TypeError; for us it simply means "no match".
            return null;
        }
    }

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

    private function formatOption(mixed $raw, Field $field, mixed $row, FormatContext $context, Align $align): Cell
    {
        $options = $field->getOptions();

        // Closure options can be derived from the row (e.g. lists that differ per company).
        if ($options instanceof Closure) {
            $options = $options($row);
        }

        $key = $this->optionKey($raw);

        if (!is_array($options) || null === $key || !array_key_exists($key, $options)) {
            return Cell::text($this->rawText($raw), $align);
        }

        // The label itself can be a translation key; if it is plain text, the translator
        // returns it unchanged.
        return Cell::text($context->trans((string) $options[$key]), $align);
    }

    /** The key to look up in the option map; `null` for types that cannot be an array key. */
    private function optionKey(mixed $raw): int|string|null
    {
        if ($raw instanceof BackedEnum) {
            return $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        return is_int($raw) || is_string($raw) ? $raw : null;
    }

    private function rawText(mixed $raw): string
    {
        if ($raw instanceof BackedEnum) {
            return (string) $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        if ($raw instanceof Stringable) {
            return (string) $raw;
        }

        return is_scalar($raw) ? (string) $raw : '';
    }
}
