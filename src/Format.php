<?php

declare(strict_types=1);

namespace Balin\Tabula;

/**
 * Çıktı biçimi.
 *
 * Bir alan `Field::only()` ile belirli biçimlere sınırlanabilir — ör. yalnız Excel'de
 * görünen teknik kimlik kolonu.
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

    /** Bu biçim gerçek sayı/biçim kodu taşıyabiliyor mu, yoksa yalnız metin mi yazılıyor? */
    public function supportsTypedValues(): bool
    {
        return self::Xlsx === $this;
    }

    /** Bu biçim tek dosyada birden çok sayfa taşıyabiliyor mu? */
    public function supportsMultipleSheets(): bool
    {
        return self::Xlsx === $this;
    }
}
