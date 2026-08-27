<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

/**
 * Tek bir hücrenin ya da satırın neden kabul edilmediği.
 *
 * SATIR NUMARASI BİRİNCİ SINIF ALANDIR. Mevcut ERP'de satır numarası ancak çeviri
 * metnine `%row%` yer tutucusu gömülmüşse hayatta kalıyordu; alan bazlı hatalarda
 * çoğunlukla kayboluyor ve kullanıcı "bir yerlerde hata var" mesajıyla baş başa kalıyordu.
 */
final readonly class RowError
{
    /**
     * @param int         $row     Kullanıcının Excel'de GÖRDÜĞÜ satır numarası (1 tabanlı)
     * @param string|null $field   Alan anahtarı; null ise hata satırın tamamına ait
     * @param string      $message Kullanıcıya gösterilecek, yerelleştirilmiş mesaj
     * @param string|null $value   Reddedilen ham değer — "neden" sorusunu cevaplar
     */
    public function __construct(
        public int $row,
        public ?string $field,
        public string $message,
        public ?string $value = null,
    ) {
    }

    public static function forField(int $row, string $field, string $message, ?string $value = null): self
    {
        return new self($row, $field, $message, $value);
    }

    public static function forRow(int $row, string $message): self
    {
        return new self($row, null, $message);
    }

    public function isFieldError(): bool
    {
        return null !== $this->field;
    }
}
