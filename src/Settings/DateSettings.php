<?php

declare(strict_types=1);

namespace Balin\Tabula\Settings;

use Balin\Tabula\Schema\FieldType;

/** Date formatting settings. If the field supplies its own `pattern()` value, that one wins. */
final readonly class DateSettings
{
    public function __construct(
        public string $datePattern = 'd.m.Y',
        public string $dateTimePattern = 'd.m.Y H:i',
        public string $excelDateFormat = 'dd.mm.yyyy',
        public string $excelDateTimeFormat = 'dd.mm.yyyy hh:mm',
    ) {
    }

    public function patternFor(FieldType $type): string
    {
        return FieldType::DateTime === $type ? $this->dateTimePattern : $this->datePattern;
    }

    public function excelFormatFor(FieldType $type): string
    {
        return FieldType::DateTime === $type ? $this->excelDateTimeFormat : $this->excelDateFormat;
    }
}
