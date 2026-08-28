<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value\Formatter;

use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Settings\NumberSettings;
use Nouxwell\Tabula\Value\Cell;
use Nouxwell\Tabula\Value\FormatContext;
use Nouxwell\Tabula\Value\ValueFormatter;
use Stringable;

/**
 * The formatter for integer, decimal and quantity fields.
 *
 * BOTH representations are put into the cell: a bare number plus a format code for Excel,
 * and localised text for CSV/PDF. The system this replaces wrote the number as text
 * only; because the Excel column was text, it could not be summed, sorted or filtered.
 *
 * The digit count comes from the field's own `decimals()` first, and from the TYPE's default
 * when the field has none (there is no global map keyed by field NAME — see NumberSettings).
 */
final class NumberFormatter implements ValueFormatter
{
    public function supports(FieldType $type): bool
    {
        return match ($type) {
            FieldType::Integer, FieldType::Decimal, FieldType::Quantity => true,
            default => false,
        };
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            // If the field has taken formatting over entirely, the number logic never runs:
            // the result is text and stays text in Excel too (the user's explicit choice).
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        $numbers = $context->settings->numbers;
        $number = self::parse($raw, $numbers);

        // An unreadable value empties THAT ONE cell, it does not bring the export down:
        // a 40,000-row report dying because of a single broken cell is unacceptable.
        if (null === $number) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        $digits = self::digitsFor($field, $numbers);
        $value = self::coerceToType($number, $field->getType());

        return Cell::number(
            $value,
            number_format((float) $value, $digits, $numbers->decimalSeparator, $numbers->thousandSeparator),
            $numbers->excelFormatCode($digits),
            $align,
        );
    }

    /**
     * Converts a raw value to a number; returns `null` when it cannot (it does NOT throw).
     *
     * MoneyFormatter uses this too: copying the parsing logic would be the very mistake this
     * package exists to eliminate (the same `normalize()` method pasted into eight separate
     * classes).
     *
     * String parsing is needed because Doctrine returns `DECIMAL` columns and DQL scalar
     * projections as STRINGS ("1234.5600"). On top of that, already localised strings can
     * arrive from intermediate layers ("1.234,56"); the plain `(float)` cast performed by the
     * system this replaces turned that string silently into 1.0.
     *
     * @param bool $preferLocalized `true` when the text is known to come from a LOCALISED representation.
     *
     * The same string means different things depending on which direction it came from, and
     * that distinction has to be EXPLICIT:
     *
     *  - On the export side the value mostly comes from a database scalar ("1234.5600"), and
     *    there a lone dot is ALWAYS the decimal point — the canonical reading is correct.
     *  - On the import side the text comes either from the template we produced, or from a
     *    cell the user typed into Excel in their own locale. With Turkish settings,
     *    `Field::money('balance')` writes the value 1234 as "1.234 ₺"; reading that
     *    canonically gives 1.234 — a SILENT 1000-FOLD deviation that nobody notices, because
     *    a perfectly valid float comes out of it.
     *
     * When `true` is given, the canonical shortcut is skipped and the configured separators
     * win. Canonical strings are still read correctly: "1234.5600" carries 4 digits after
     * the decimal point, so it does not count as thousands grouping. The only thing lost is
     * scientific notation ("1e3") — and that arrives from a spreadsheet as a native `float`
     * anyway, not as a string.
     */
    public static function parse(mixed $raw, NumberSettings $numbers, bool $preferLocalized = false): int|float|null
    {
        if (null === $raw) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }

        if (is_float($raw)) {
            // NAN/INF are not numbers; number_format turns them into meaningless text.
            return is_finite($raw) ? $raw : null;
        }

        // Number objects such as BCMath\Number or Brick\Math go down the string path when
        // they can be turned into text.
        if ($raw instanceof Stringable) {
            $raw = (string) $raw;
        }

        if (!is_string($raw)) {
            return null;
        }

        $text = trim($raw);
        if ('' === $text) {
            return null;
        }

        // The canonical form — "1234.5600", "-0.5", "1e3" as returned by the database.
        // A lone dot here is ALWAYS the decimal point; "1.234" = 1.234.
        //
        // On import this shortcut is SKIPPED: the text there comes from a localised
        // representation, where "1.234" means 1234 with thousands grouping
        // (see $preferLocalized).
        if (!$preferLocalized && is_numeric($text)) {
            $canonical = $text + 0;

            return is_float($canonical) && !is_finite($canonical) ? null : $canonical;
        }

        return self::parseLocalized($text, $numbers);
    }

    /**
     * Recognises the negative notations.
     *
     * Looking only at a leading minus is not enough: the cleanup step strips the parentheses
     * and the trailing minus, so these forms would be read as POSITIVE and the sign would
     * flip silently. In accounting data, turning a debit into a credit is the most expensive
     * mistake this library could make.
     *
     *  - `(1.234,56)`  accounting notation
     *  - `1.234,56-`   trailing minus, as found in SAP/ERP output
     *  - `₺ -1.234,56` symbol first, minus immediately in front of the number
     */
    private static function isNegative(string $text): bool
    {
        if (str_contains($text, '(') && str_contains($text, ')')) {
            return true;
        }

        if (str_starts_with($text, '-') || str_ends_with($text, '-')) {
            return true;
        }

        return 1 === preg_match('/^\D*-\s*\d/u', $text);
    }

    /**
     * Resolves a string that carries separators (or has picked up symbols/whitespace).
     */
    private static function parseLocalized(string $text, NumberSettings $numbers): int|float|null
    {
        // The minus sign is taken first, because the cleanup would lose it.
        $negative = self::isNegative($text);

        // Non-breaking/thin spaces are the thousands separator in some locales; they are
        // reduced to an ordinary space.
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);

        $separators = self::separators($numbers);
        $cleaned = preg_replace(
            '/[^0-9'.preg_quote(implode('', $separators), '/').']/u',
            '',
            $text,
        ) ?? '';

        if ('' === $cleaned) {
            return null;
        }

        [$lastSeparator, $lastPosition] = self::lastSeparator($cleaned, $separators);

        if (null === $lastSeparator) {
            return self::sign(self::toInteger($cleaned), $negative);
        }

        $head = substr($cleaned, 0, $lastPosition);
        $tail = substr($cleaned, $lastPosition + strlen($lastSeparator));

        if (!self::isDecimalSeparator($lastSeparator, $head, $tail, $separators, $numbers)) {
            return self::sign(self::toInteger(str_replace($separators, '', $cleaned)), $negative);
        }

        $whole = str_replace($separators, '', $head);
        if ('' === $whole) {
            $whole = '0';
        }

        if (!ctype_digit($whole) || !ctype_digit($tail)) {
            return null;
        }

        return self::sign((float) ($whole.'.'.$tail), $negative);
    }

    /**
     * Decides whether the right-most separator is the decimal point or a thousands separator.
     *
     * The ambiguity is unavoidable ("1.234" can be both one thousand two hundred and
     * thirty-four and 1.234); the rules are ordered so that the most likely reading is
     * chosen.
     *
     * @param list<string> $separators
     */
    private static function isDecimalSeparator(
        string $separator,
        string $head,
        string $tail,
        array $separators,
        NumberSettings $numbers,
    ): bool {
        // The decimal part consists of digits only.
        if ('' === $tail || !ctype_digit($tail)) {
            return false;
        }

        // (a) If a DIFFERENT separator sits to its left, the right-most one is definitely
        //     the decimal point: "1.234,56".
        foreach ($separators as $other) {
            if ($other !== $separator && str_contains($head, $other)) {
                return true;
            }
        }

        // (b) If the same separator occurs more than once it is a grouping separator: "1.234.567".
        if (str_contains($head, $separator)) {
            return false;
        }

        // (c) A single occurrence of the configured decimal separator: the setting wins.
        if ($separator === $numbers->decimalSeparator) {
            return true;
        }

        // (d) A single occurrence of the configured thousands separator: grouping when
        //     exactly three digits follow ("1.234 ₺" → 1234), decimal otherwise
        //     ("1.5 ₺" → 1.5).
        if ($separator === $numbers->thousandSeparator) {
            return 3 !== strlen($tail);
        }

        // (e) A universal candidate that is not configured: treat it as decimal, like the
        //     canonical dot/comma.
        return true;
    }

    /**
     * The right-most separator in the string, and its byte position.
     *
     * @param list<string> $separators
     *
     * @return array{0: string|null, 1: int}
     */
    private static function lastSeparator(string $cleaned, array $separators): array
    {
        $found = null;
        $at = -1;

        foreach ($separators as $separator) {
            $position = strrpos($cleaned, $separator);
            if (false !== $position && $position > $at) {
                $found = $separator;
                $at = $position;
            }
        }

        return [$found, $at];
    }

    /**
     * The candidate separators: the configured ones plus the universal dot and comma.
     *
     * The universal ones are on the list as well, so that a string in English format
     * ("1,234.56") is still recognised while running with Turkish settings.
     *
     * @return list<string>
     */
    private static function separators(NumberSettings $numbers): array
    {
        $candidates = [$numbers->decimalSeparator, $numbers->thousandSeparator, '.', ','];

        return array_values(array_unique(array_filter(
            $candidates,
            static fn (string $candidate): bool => '' !== $candidate,
        )));
    }

    /** Converts a digits-only string to a number; above PHP_INT_MAX it is promoted to a float. */
    private static function toInteger(string $digits): int|float|null
    {
        return ctype_digit($digits) ? $digits + 0 : null;
    }

    private static function sign(int|float|null $value, bool $negative): int|float|null
    {
        if (null === $value || !$negative) {
            return $value;
        }

        return -$value;
    }

    /** The type's default when the field has no digit count of its own; an integer has no decimals. */
    private static function digitsFor(Field $field, NumberSettings $numbers): int
    {
        if (FieldType::Integer === $field->getType()) {
            return 0;
        }

        return max(0, $field->getDecimals() ?? $numbers->digitsFor($field->getType()));
    }

    /**
     * An integer field really does reach Excel as an `int` — so that the cell shows "1" and
     * not "1,00", and a record/stock count is not mistaken for a decimal.
     */
    private static function coerceToType(int|float $number, FieldType $type): int|float
    {
        if (FieldType::Integer !== $type) {
            return $number;
        }

        $rounded = round((float) $number);

        // For a float outside PHP_INT_MAX the (int) cast emits a warning and flips the sign;
        // leaving it as a float is still a number as far as Excel is concerned, which beats
        // a wrong number. The comparison must be STRICT: (float) PHP_INT_MAX rounds up to
        // 2^63, so the limit itself does not fit into an int.
        return abs($rounded) < (float) PHP_INT_MAX ? (int) $rounded : $rounded;
    }
}
