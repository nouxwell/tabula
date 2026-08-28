<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value\Formatter;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Value\Cell;
use Nouxwell\Tabula\Value\FormatContext;
use Nouxwell\Tabula\Value\ValueFormatter;
use Stringable;

/**
 * Date and date/time fields.
 *
 * It produces two outputs:
 *  - A REAL date in Xlsx: the Excel serial number (days elapsed since 1899-12-30) is written
 *    into the cell together with a format code. That way the cell can be sorted, filtered
 *    and subtracted in Excel. The legacy export wrote a pre-formatted STRING; when the user
 *    sorted by date, "01.12.2024" and "02.01.2023" lined up alphabetically.
 *  - Localised text in every other format.
 *
 * The serial-number conversion is done by hand here (plain arithmetic); this class is
 * deliberately not tied to PhpSpreadsheet — on the CSV/PDF path the Excel library may not
 * even be installed.
 *
 * The parsing side is lenient and NEVER throws: old records contain `0000-00-00`,
 * half-written dates and free text; a single broken row must not bring down a 50,000-row
 * export, that cell should simply show up as it is.
 */
final class DateFormatter implements ValueFormatter
{
    /** Where the Unix epoch (1970-01-01) sits in Excel's day counter. */
    private const int EXCEL_EPOCH_OFFSET = 25569;

    private const int SECONDS_PER_DAY = 86400;

    /**
     * The minimum length for a digits-only string.
     *
     * Such a string only counts as a timestamp once it is at least this long; otherwise year
     * fragments like `2024` would turn into the first hours of 1970.
     */
    private const int TIMESTAMP_MIN_DIGITS = 9;

    public function supports(FieldType $type): bool
    {
        return $type->isTemporal();
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        if ($this->isBlank($raw)) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        $type = $field->getType();
        // The field's own pattern wins if it has one; otherwise the global setting
        // (date and date-time are configured separately).
        $pattern = $field->getPattern() ?? $context->settings->dates->patternFor($type);

        $date = $this->toDate($raw, $pattern);

        if (null === $date) {
            // Could not be parsed: rather than lose the data, show it raw.
            return Cell::text($this->rawText($raw), $align);
        }

        $text = $date->format($pattern);

        // The cell is produced INDEPENDENTLY OF THE FORMAT: `value` is always the Excel
        // serial number and `text` is always the localised text; the WRITER decides which
        // one gets used (CSV/PDF only read `text`). Branching on `$context->format` here
        // made dates be written to Excel as text whenever `to()` and `writer()` were given
        // different values — that is, precisely the "dates sort alphabetically" bug this
        // class exists to prevent.
        return Cell::number(
            $this->toExcelSerial($date, $type),
            $text,
            $context->settings->dates->excelFormatFor($type),
            $align,
        );
    }

    private function isBlank(mixed $raw): bool
    {
        if (null === $raw) {
            return true;
        }

        if (!is_string($raw)) {
            return false;
        }

        $text = trim($raw);
        if ('' === $text) {
            return true;
        }

        // The MySQL zero date (`0000-00-00`, `0000-00-00 00:00:00`): PHP silently parses
        // this as 1 BC, and in Excel a negative serial number means `#######`. Since it
        // means "no date", the cell should stay empty too.
        $digits = preg_replace('/\D/', '', $text) ?? '';

        return 8 <= strlen($digits) && '' === trim($digits, '0');
    }

    /**
     * The Excel serial number: days elapsed since 1899-12-30 plus the fraction of the day.
     *
     * The offset is added to the timestamp because Excel has no notion of a time zone; the
     * cell has to show wall-clock time. Otherwise a 09:00 stored in Europe/Istanbul would
     * open as 06:00 in Excel.
     *
     * Known limit: Excel believes 1900 was a leap year, so for dates BEFORE 1900-03-01 this
     * formula is one day off from Excel. There are no such dates in ERP data; adding the
     * correction that matches the bug would make the formula unreadable.
     */
    private function toExcelSerial(DateTimeImmutable $date, FieldType $type): float
    {
        $seconds = $date->getTimestamp() + $date->getOffset();
        $serial = $seconds / self::SECONDS_PER_DAY + self::EXCEL_EPOCH_OFFSET;

        // No leftover time in a pure date column: the same day gets the same serial on
        // every row.
        return FieldType::Date === $type ? floor($serial) : $serial;
    }

    private function toDate(mixed $raw, string $pattern): ?DateTimeImmutable
    {
        if ($raw instanceof DateTimeImmutable) {
            return $raw;
        }

        if ($raw instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($raw);
        }

        if (is_int($raw)) {
            return $this->fromTimestamp($raw);
        }

        if (is_float($raw)) {
            return $this->fromTimestamp((int) $raw);
        }

        if ($raw instanceof Stringable) {
            $raw = (string) $raw;
        }

        return is_string($raw) ? $this->fromString(trim($raw), $pattern) : null;
    }

    /** Timestamps are taken as UTC; the library has no time-zone setting. */
    private function fromTimestamp(int $timestamp): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable('@'.$timestamp);
        } catch (Exception) {
            return null;
        }
    }

    private function fromString(string $value, string $pattern): ?DateTimeImmutable
    {
        $parsed = $this->fromPattern($value, $pattern);
        if (null !== $parsed) {
            return $parsed;
        }

        // Digits only: it may be a Unix timestamp written out as a string.
        if (ctype_digit($value) && strlen($value) >= self::TIMESTAMP_MIN_DIGITS) {
            return $this->fromTimestamp((int) $value);
        }

        // Last resort: PHP's free-form parser (ISO 8601, `2024-01-05 09:30:00` and so on).
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
        // the next month in `createFromFormat`. We do not accept a partial match.
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
     * Without this, a date parsed with `d.m.Y` would carry the time of the run as well, and
     * the same day could produce two different Excel serial numbers.
     */
    private function resetPattern(string $pattern): string
    {
        if (str_starts_with($pattern, '!') || str_contains($pattern, '|')) {
            return $pattern;
        }

        return '!'.$pattern;
    }

    private function rawText(mixed $raw): string
    {
        if (is_string($raw)) {
            return trim($raw);
        }

        if ($raw instanceof Stringable) {
            return (string) $raw;
        }

        return is_scalar($raw) ? (string) $raw : '';
    }
}
