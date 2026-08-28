<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value\Parser;

use BackedEnum;
use DateTimeInterface;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Value\ParseContext;
use Nouxwell\Tabula\Value\ValueParser;
use Stringable;
use UnitEnum;

/**
 * The parser for text fields — the reverse direction of `StringFormatter`.
 *
 * Its real job is not "cast it to a string" but REPAIRING WHAT EXCEL BROKE. When the user
 * types `8691234567890123` into a cell, Excel decides it is a number and hands the reader
 * back the float `8.6912345678901E+15`; a plain `(string)` cast then writes that barcode
 * into the database in exponential notation. In the import of the system this package
 * replaces, stock codes, IBANs and barcodes were broken in exactly this way. Here a float is
 * ALWAYS printed without an exponent.
 *
 * This class also carries the helpers shared by ALL parsers (`isBlank()`, `clean()`,
 * `describe()`): just like `MoneyFormatter` calling `NumberFormatter::parse()` instead of
 * writing its own number parser. This package exists because the same `normalize()` method
 * had been copied into eight classes; we are not repeating that mistake on the parser side.
 *
 * The other parsers are STRICT (an unreadable cell throws a `ParseException`), this one is
 * not: there is no "notAString" error in the contract, because every scalar value has a text
 * equivalent. The only thing that cannot be turned into text (an array, a resource, an
 * object without `__toString`) cannot come out of a reader in the first place; if it does,
 * it counts as an empty cell.
 */
final class StringParser implements ValueParser
{
    /**
     * Invisible whitespace: the non-breaking space family plus the BOM.
     *
     * `trim()` does NOT recognise these. In cells coming from spreadsheets a non-breaking
     * space is ordinary debris; the UTF-8 BOM sticks to the first cell of a CSV. Left
     * uncleaned, "cells that look empty but are not" pass the required-field check and
     * invisible characters get written into the database.
     */
    private const array INVISIBLE_SPACES = ["\u{00A0}", "\u{202F}", "\u{2009}", "\u{FEFF}"];

    /**
     * The maximum precision used when printing the decimal part.
     *
     * PHP's `(string)` conversion honours the `precision` ini setting and escapes into
     * exponential notation above 14; `%.14F` is both locale-independent and never produces
     * an exponent.
     */
    private const int FLOAT_PRECISION = 14;

    public function supports(FieldType $type): bool
    {
        return FieldType::String === $type;
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        if (self::isBlank($raw)) {
            return null;
        }

        $text = self::clean($this->stringify($raw, $context));

        // A cell consisting of nothing but invisible characters is empty as well.
        return '' === $text ? null : $text;
    }

    /**
     * Is the cell EMPTY? The rule shared by every parser.
     *
     * Emptiness is NOT AN ERROR: the required-field check happens in the import loop, which
     * knows the field — not here. The parser only says "there is no value".
     */
    public static function isBlank(mixed $raw): bool
    {
        if (null === $raw) {
            return true;
        }

        if ($raw instanceof Stringable) {
            $raw = (string) $raw;
        }

        return is_string($raw) && '' === self::clean($raw);
    }

    /** Reduces invisible whitespace to an ordinary space and trims. */
    public static function clean(string $text): string
    {
        return trim(str_replace(self::INVISIBLE_SPACES, ' ', $text));
    }

    /**
     * The raw representation shown in error messages.
     *
     * `ParseException` messages go straight to the user and are obliged to carry the "which
     * value" information; a message that only says "invalid value" tells the user nothing.
     * For types that do not turn into text, the TYPE is printed rather than the value itself
     * — producing a second error while producing an error message is unacceptable.
     */
    public static function describe(mixed $raw): string
    {
        if (is_string($raw)) {
            return self::clean($raw);
        }

        if (is_bool($raw)) {
            return $raw ? 'true' : 'false';
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_float($raw)) {
            return self::floatToText($raw);
        }

        if ($raw instanceof BackedEnum) {
            return (string) $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d H:i:s');
        }

        if ($raw instanceof Stringable) {
            return self::clean((string) $raw);
        }

        return get_debug_type($raw);
    }

    private function stringify(mixed $raw, ParseContext $context): string
    {
        if (is_string($raw)) {
            return $raw;
        }

        // Excel turning a text column into a number: barcodes/stock codes/IBANs come
        // through here.
        if (is_float($raw)) {
            return self::floatToText($raw);
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_bool($raw)) {
            // `(string) false` produces an empty string; in a text column that silently
            // swallows data. The export writes the same pair, so the round trip closes.
            $settings = $context->settings;

            return $context->trans($raw ? $settings->boolTrueKey : $settings->boolFalseKey);
        }

        // Doctrine enumType columns return an enum INSTANCE even in scalar projections; in
        // re-imports from an array source those values can reach the parser.
        if ($raw instanceof BackedEnum) {
            return (string) $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        if ($raw instanceof Stringable) {
            return (string) $raw;
        }

        // A date landing in a text field is a schema mistake, but the value can still be
        // salvaged; a neutral ISO-like representation is written (guessing a pattern is not
        // this class's job).
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d H:i:s');
        }

        if (is_array($raw)) {
            // Keep Turkish characters and paths readable: no `ç` or `\/` escaping.
            $json = json_encode($raw, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

            return false === $json ? '' : $json;
        }

        // A value that lands here cannot have come from a reader (a resource, or an object
        // without `__toString`). It is left empty; if the field is required, the import loop
        // produces the error.
        return '';
    }

    /**
     * Prints a float WITHOUT an exponent.
     *
     * A float with an integral value is written out with its digits: `8691234567890`, not
     * `8.69123456789E+12`. Above 2^53 part of those digits is already the float's own
     * rounding — but the exponential form does not carry that information either, and once
     * it has been written to the database it can never be corrected.
     */
    private static function floatToText(float $value): string
    {
        if (!is_finite($value)) {
            return (string) $value;
        }

        if (floor($value) === $value) {
            return number_format($value, 0, '.', '');
        }

        $text = rtrim(rtrim(sprintf('%.'.self::FLOAT_PRECISION.'F', $value), '0'), '.');

        return '' === $text ? '0' : $text;
    }
}
