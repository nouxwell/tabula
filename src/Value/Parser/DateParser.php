<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value\Parser;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Nouxwell\Tabula\Exception\ParseException;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Value\ParseContext;
use Nouxwell\Tabula\Value\ValueParser;
use Stringable;

/**
 * The parser for date and date/time fields — it always returns a `DateTimeImmutable`.
 *
 * The order of the sources is built around where the cell came from:
 *  1. `DateTimeInterface` — if the reader handed over a ready object, it is left untouched.
 *  2. THE EXCEL SERIAL NUMBER — a date-formatted cell is a NUMBER in a spreadsheet
 *     (45292 = 2024-01-01). What `DateFormatter` wrote is undone here; the conversion is
 *     again done by hand, this class is deliberately not tied to PhpSpreadsheet.
 *  3. The field's pattern (`d.m.Y`) — the format the user SEES in the template.
 *  4. ISO/free form — canonical representations such as `2024-01-05` or
 *     `2024-01-05T09:30:00`.
 *
 * Three points where it DIFFERS from the formatter:
 *  - `DateFormatter` prints a cell it cannot resolve as RAW TEXT and moves on; here a value
 *    that cannot be resolved is an exception, and the message states the EXPECTED PATTERN
 *    ("expected format: d.m.Y"), because the user can only fix the error once they see the
 *    correct format.
 *  - The MySQL zero date (`0000-00-00`) is an empty cell on export (old records are full of
 *    them, and a report must not die because of them). On import it is REJECTED: a user who
 *    means "no date" leaves the cell empty; a zero date is the broken output of some other
 *    system and must not be carried into the database.
 *  - A numeric value counts as a UNIX TIMESTAMP in the formatter (that is how it comes out
 *    of the database), and as an EXCEL SERIAL NUMBER here (that is how it comes out of a
 *    spreadsheet). The same number means two different things in the two directions; the
 *    source is different.
 */
final class DateParser implements ValueParser
{
    /** Where the Unix epoch (1970-01-01) sits in Excel's day counter. */
    private const int EXCEL_EPOCH_OFFSET = 25569;

    private const int SECONDS_PER_DAY = 86400;

    /** 2958465 = 9999-12-31; the last serial number Excel still interprets as a date. */
    private const int MAX_EXCEL_SERIAL = 2958465;

    /**
     * The minimum serial number for a digits-only STRING (10000 = 1927-05-18).
     *
     * A numeric cell in a spreadsheet is definitely a serial number, but a "2024" arriving
     * as TEXT may be a year fragment; taken as a serial it would silently become
     * 1905-07-16. PHP's free-form parser does not rescue that string either — it reads it as
     * the time "20:24" — which is why digits-only strings outside the range are rejected
     * without being tried at all.
     */
    private const int MIN_TEXT_SERIAL = 10000;

    public function supports(FieldType $type): bool
    {
        return $type->isTemporal();
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        if (StringParser::isBlank($raw)) {
            return null;
        }

        $type = $field->getType();
        // The field's own pattern wins if it has one; otherwise the global setting
        // (date and date-time are configured separately).
        $pattern = $field->getPattern() ?? $context->settings->dates->patternFor($type);

        $date = $this->toDate($raw, $pattern);

        if (null === $date) {
            throw ParseException::notADate($field, StringParser::describe($raw), $pattern);
        }

        // No leftover time in a pure date column: the same day gets the same value on every
        // row, and `BETWEEN` queries do not miss the end of the day.
        return FieldType::Date === $type ? $date->setTime(0, 0) : $date;
    }

    private function toDate(mixed $raw, string $pattern): ?DateTimeImmutable
    {
        if ($raw instanceof DateTimeImmutable) {
            return $raw;
        }

        if ($raw instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($raw);
        }

        // A numeric cell = a serial number. The range is kept wide (1 = 1899-12-31): such a
        // cell is already formatted as a date in the spreadsheet.
        if (is_int($raw) || is_float($raw)) {
            return $this->fromExcelSerial((float) $raw, 1.0);
        }

        if ($raw instanceof Stringable) {
            $raw = (string) $raw;
        }

        if (!is_string($raw)) {
            return null;
        }

        return $this->fromString(StringParser::clean($raw), $pattern);
    }

    private function fromString(string $value, string $pattern): ?DateTimeImmutable
    {
        if ($this->isZeroDate($value)) {
            return null;
        }

        $parsed = $this->fromPattern($value, $pattern);
        if (null !== $parsed) {
            return $parsed;
        }

        // Digits only: it may be a serial number that landed in the CSV unformatted. A
        // digits-only string outside the range is not tried at all (see MIN_TEXT_SERIAL).
        if (ctype_digit($value)) {
            return $this->fromExcelSerial((float) $value, (float) self::MIN_TEXT_SERIAL);
        }

        // Text without a digit in it is not a date. Without this check, PHP's free-form
        // parser would ACCEPT strings such as "now", "tomorrow" or "next monday" and write
        // the date of the import run itself into the database.
        if (1 !== preg_match('/\d/', $value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function fromPattern(string $value, string $pattern): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat($this->resetPattern($pattern), $value);

        if (false === $parsed) {
            return null;
        }

        // Warnings count as errors too: input such as "32.13.2024" silently overflows into
        // the next month in `createFromFormat`, and the user would never notice the wrong
        // date. A partial match is not accepted either ("15.01.2024 at 9" does not fit the
        // pattern).
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (0 < $errors['warning_count'] || 0 < $errors['error_count'])) {
            return null;
        }

        return $parsed;
    }

    /**
     * Prefixes the pattern with `!`: fields not present in the pattern are reset instead of
     * defaulting to "now".
     *
     * Without this, a date read with `d.m.Y` would carry the RUN TIME of the import as well;
     * two files imported on the same day would produce different values for the same date.
     */
    private function resetPattern(string $pattern): string
    {
        if (str_starts_with($pattern, '!') || str_contains($pattern, '|')) {
            return $pattern;
        }

        return '!'.$pattern;
    }

    /**
     * Converts an Excel serial number to a date — the inverse of
     * `DateFormatter::toExcelSerial()`.
     *
     * The result is UTC and preserves WALL-CLOCK TIME: the formatter added the time-zone
     * offset to the serial number so that the cell would show local time; that same offset
     * is not subtracted back here, because whatever time the user sees in the file is what
     * has to go into the database. The library has no time-zone setting; if it had, a cell
     * reading 09:00 would be imported as 06:00.
     *
     * Rounding is mandatory: the 09:30 fraction is stored as 0.39583333… and a plain `(int)`
     * conversion would turn the time into 09:29:59.
     */
    private function fromExcelSerial(float $serial, float $minimum): ?DateTimeImmutable
    {
        if (!is_finite($serial) || $serial < $minimum || $serial > (float) self::MAX_EXCEL_SERIAL) {
            return null;
        }

        $seconds = (int) round(($serial - self::EXCEL_EPOCH_OFFSET) * self::SECONDS_PER_DAY);

        try {
            return new DateTimeImmutable('@'.$seconds);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * The MySQL zero date (`0000-00-00`, `0000-00-00 00:00:00`).
     *
     * PHP silently parses this as 1 BC; left unchecked, a real but nonsensical date would be
     * written into the database.
     */
    private function isZeroDate(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return 8 <= strlen($digits) && '' === trim($digits, '0');
    }
}
