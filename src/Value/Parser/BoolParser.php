<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value\Parser;

use Nouxwell\Tabula\Exception\ParseException;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Value\ParseContext;
use Nouxwell\Tabula\Value\ValueParser;
use Stringable;

/**
 * The parser for boolean fields.
 *
 * The translated "Yes"/"No" is tried FIRST, because that is exactly the text written into
 * the template's drop-down list (`BoolFormatter` uses the
 * `TabulaSettings::$boolTrueKey/$boolFalseKey` pair — and the parser reads the same pair).
 * In the system this replaces, one translation family produced the cell text and
 * another produced the validation list; the value its own template wrote was not in its own
 * list. Here there is a single source, and the round trip closes.
 *
 * After that, the usual notations that can arrive from anywhere are recognised (`1/0`,
 * `true/false`, `yes/no`, `evet/hayır`, real booleans and tinyints). The input side is
 * deliberately wide; the user fills in the file the way they are used to.
 *
 * Where it DIFFERS from the formatter: `BoolFormatter` turns a value it does not recognise
 * into an empty cell and moves on. The parser cannot do that — turning "unknown" into a
 * made-up `false` (or leaving the field blank) means writing wrong data into the database.
 * An unrecognised value throws an exception, and the message lists the ACCEPTED forms; the
 * user can only fix the error once they can see what they are allowed to write.
 */
final class BoolParser implements ValueParser
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Bool === $type;
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        if (StringParser::isBlank($raw)) {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        // Doctrine tinyint(1), SQL COUNTs and JSON APIs produce 0/1; a numeric Excel cell
        // comes back as a float.
        if (is_int($raw)) {
            return 0 !== $raw;
        }

        if (is_float($raw)) {
            return 0.0 !== $raw;
        }

        if ($raw instanceof Stringable) {
            $raw = (string) $raw;
        }

        if (!is_string($raw)) {
            throw ParseException::notABoolean($field, StringParser::describe($raw), $this->accepted($context));
        }

        $needle = $this->fold($raw);
        $settings = $context->settings;

        // The text the template itself wrote comes before everything else.
        if ($needle === $this->fold($context->trans($settings->boolTrueKey))) {
            return true;
        }

        if ($needle === $this->fold($context->trans($settings->boolFalseKey))) {
            return false;
        }

        return match ($needle) {
            '1', 'true', 'yes', 'on', 'y', 't', 'evet', 'e' => true,
            '0', 'false', 'no', 'off', 'n', 'f', 'hayır', 'hayir', 'h' => false,
            default => throw ParseException::notABoolean($field, StringParser::describe($raw), $this->accepted($context)),
        };
    }

    /**
     * The accept-list enumerated in the error message.
     *
     * The TRANSLATED pair comes first: that is the text the user sees in the template and is
     * expected to type. After it come the notations most often used by people filling in the
     * file by hand. The list is de-duplicated case-insensitively so that it does not repeat
     * itself when the translation already is "Evet/Hayır".
     *
     * @return list<string>
     */
    private function accepted(ParseContext $context): array
    {
        $settings = $context->settings;

        $accepted = [
            $context->trans($settings->boolTrueKey),
            $context->trans($settings->boolFalseKey),
            'Evet',
            'Hayır',
            '1',
            '0',
            'true',
            'false',
        ];

        $unique = [];
        foreach ($accepted as $value) {
            $key = $this->fold($value);
            if ('' !== $key && !array_key_exists($key, $unique)) {
                $unique[$key] = $value;
            }
        }

        return array_values($unique);
    }

    /** Comparisons are made on trimmed, lower-cased text. */
    private function fold(string $value): string
    {
        return mb_strtolower(StringParser::clean($value), 'UTF-8');
    }
}
