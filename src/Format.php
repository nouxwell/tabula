<?php

declare(strict_types=1);

namespace Balin\Tabula;

/**
 * The output format.
 *
 * A field can be restricted to certain formats with `Field::only()` — for example a technical
 * id column that is only visible in Excel.
 */
enum Format: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';
    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv',
            self::Pdf => 'application/pdf',
        };
    }

    /** Can this format carry real numbers and format codes, or is only text written out? */
    public function supportsTypedValues(): bool
    {
        return self::Xlsx === $this;
    }

    /** Can this format carry more than one sheet in a single file? */
    public function supportsMultipleSheets(): bool
    {
        return self::Xlsx === $this;
    }
}
