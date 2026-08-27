<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Parser;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\Formatter\NumberFormatter;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ValueParser;

/**
 * The parser for integer, decimal and quantity fields.
 *
 * Localised string resolution is NOT REWRITTEN here: `NumberFormatter::parse()` already
 * resolves "1.234,56", the database's canonical "1234.5600", the accounting parentheses
 * `(1.234,56)` and the trailing minus `1.234,56-`. Writing a second parser would be the very
 * mistake this package exists to eliminate (the same logic copied into eight classes).
 *
 * Where it DIFFERS from the formatter is leniency: `NumberFormatter` prints an unreadable
 * cell empty and moves on, because a 40,000-row report must not die because of a single
 * broken cell. Here the same behaviour would mean writing WRONG DATA into the database; an
 * unreadable value throws an exception, and the import loop turns it into a `RowError`
 * carrying the row/field information.
 */
final class NumberParser implements ValueParser
{
    public function supports(FieldType $type): bool
    {
        return match ($type) {
            FieldType::Integer, FieldType::Decimal, FieldType::Quantity => true,
            default => false,
        };
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        if (StringParser::isBlank($raw)) {
            return null;
        }

        // `preferLocalized: true` — the text comes from a localised cell. The canonical
        // reading would take "1.234" for 1.234; with Turkish settings it is 1234, and the
        // difference would be a silent 1000-fold deviation.
        $number = NumberFormatter::parse($raw, $context->settings->numbers, preferLocalized: true);

        // `null` coming back from a non-empty cell means "not a number"; empty cells were
        // filtered out above.
        if (null === $number) {
            throw ParseException::notANumber($field, StringParser::describe($raw));
        }

        // The type contract is kept crisp: Integer is always `int`, Decimal/Quantity always
        // `float`. The formatter side may return a mix of `int|float` (its only consumer
        // there is `number_format`); here the value is going into an entity setter, so the
        // type must not change from row to row.
        return FieldType::Integer === $field->getType()
            ? $this->toInteger($number, $field, $raw)
            : (float) $number;
    }

    /**
     * A decimal value in an integer field is an ERROR.
     *
     * A `12,5` written into a stock-count column is a typo; silently rounding it to 12 would
     * erase half a box from the records. The user has to see the mistake — it is not for us
     * to guess.
     */
    private function toInteger(int|float $number, Field $field, mixed $raw): int
    {
        if (is_int($number)) {
            return $number;
        }

        // A value above `PHP_INT_MAX` is not an integer either: the `(int)` cast can even
        // flip the sign. The comparison has to be STRICT, because `(float) PHP_INT_MAX`
        // rounds up to 2^63 — so the limit itself does not fit into an `int`.
        if (floor($number) !== $number || abs($number) >= (float) PHP_INT_MAX) {
            throw ParseException::notAnInteger($field, StringParser::describe($raw));
        }

        return (int) $number;
    }
}
