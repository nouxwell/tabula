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
 *  4. The other temporal pattern: the date-and-time pattern for a date field, the date pattern
 *     for a date-time field. "05.01.2024 09:30" in a date column is still 5 January, and
 *     "05.01.2024" in a date-time column is its midnight.
 *  5. ISO 8601 without an offset — `2024-01-05`, `2024-01-05 09:30`, `2024-01-05T09:30:00` —
 *     as other systems write dates.
 *
 * Nothing else. PHP's free-form date reader used to be the last resort, and it read too much:
 * "05/03/2026" became 3 May under Turkish settings, "31.02.2026" rolled into 3 March, and
 * neither said a word. A date this parser cannot place for certain is an error the user sees,
 * never a different date in the database.
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
     * 1905-07-16, which is why digits-only strings outside the range are rejected without
     * being tried at all.
     */
    private const int MIN_TEXT_SERIAL = 10000;

    /** ISO 8601 as other systems write it: a date, and optionally a time, with no offset. */
    private const string ISO_DATE = '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/';

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
        $otherPattern = $context->settings->dates->patternFor(
            FieldType::DateTime === $type ? FieldType::Date : FieldType::DateTime,
        );

        $date = $this->toDate($raw, $pattern, $otherPattern);

        if (null === $date) {
            throw ParseException::notADate($field, StringParser::describe($raw), $pattern);
        }

        // No leftover time in a pure date column: the same day gets the same value on every
        // row, and `BETWEEN` queries do not miss the end of the day.
        return FieldType::Date === $type ? $date->setTime(0, 0) : $date;
    }

    private function toDate(mixed $raw, string $pattern, string $otherPattern): ?DateTimeImmutable
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

        return $this->fromString(StringParser::clean($raw), $pattern, $otherPattern);
    }

    private function fromString(string $value, string $pattern, string $otherPattern): ?DateTimeImmutable
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

        return $this->fromPattern($value, $otherPattern) ?? $this->fromIso($value);
    }

    /**
     * An ISO date or date-time, through the same strict reading as a pattern: "2026-02-30" is
     * refused, not rolled into March. A trailing offset or fractions of a second are not read;
     * which wall-clock time an offset should become is not this parser's to guess.
     */
    private function fromIso(string $value): ?DateTimeImmutable
    {
        if (1 !== preg_match(self::ISO_DATE, $value, $parts)) {
            return null;
        }

        $number = static fn (int $index): int => isset($parts[$index]) && '' !== $parts[$index] ? (int) $parts[$index] : 0;

        return $this->fromPattern(
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $number(1), $number(2), $number(3), $number(4), $number(5), $number(6)),
            'Y-m-d H:i:s',
        );
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
        return self::readCleanly() ? $parsed : null;
    }

    /**
     * Whether the date just read came through without a warning or an error.
     *
     * PHP keeps going past a day or an hour that does not exist and rolls it into the next one,
     * leaving only a warning behind ("The parsed date was invalid"). Every path here reads
     * through `fromPattern()`, which asks, so none can turn a typo into a different, real date.
     */
    private static function readCleanly(): bool
    {
        $errors = DateTimeImmutable::getLastErrors();

        return !is_array($errors) || (0 === $errors['warning_count'] && 0 === $errors['error_count']);
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
