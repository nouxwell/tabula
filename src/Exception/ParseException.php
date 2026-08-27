<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use Balin\Tabula\Schema\Field;
use RuntimeException;

/**
 * Tek bir hücre alanın tipine çevrilemedi.
 *
 * Bu istisna AKIŞI DURDURMAZ: içe aktarma döngüsü onu yakalar, satır ve alan bilgisiyle
 * bir `RowError`e dönüştürür ve sonraki satıra geçer (bkz. `ErrorMode`). Mesaj doğrudan
 * kullanıcıya gösterileceği için "hangi değer, ne bekleniyordu" ikilisini içermelidir —
 * "geçersiz değer" demek kullanıcıya hiçbir şey anlatmaz.
 */
final class ParseException extends RuntimeException implements TabulaException
{
    public static function notANumber(Field $field, string $raw): self
    {
        return new self(sprintf('"%s" sayı olarak okunamadı.', $raw));
    }

    public static function notAnInteger(Field $field, string $raw): self
    {
        return new self(sprintf('"%s" tam sayı değil.', $raw));
    }

    public static function notADate(Field $field, string $raw, string $expectedPattern): self
    {
        return new self(sprintf('"%s" tarih olarak okunamadı; beklenen biçim: %s', $raw, $expectedPattern));
    }

    /** @param list<string> $accepted */
    public static function notABoolean(Field $field, string $raw, array $accepted): self
    {
        return new self(sprintf(
            '"%s" Evet/Hayır değeri değil. Kabul edilenler: %s',
            $raw,
            implode(', ', $accepted),
        ));
    }

    /** @param list<string> $options */
    public static function notAnOption(Field $field, string $raw, array $options): self
    {
        return new self(sprintf(
            '"%s" listede yok. Seçenekler: %s',
            $raw,
            [] === $options ? '(tanımlı seçenek yok)' : implode(', ', $options),
        ));
    }

    public static function required(Field $field): self
    {
        return new self('Bu alan zorunlu, boş bırakılamaz.');
    }

    public static function noParser(Field $field): self
    {
        return new self(sprintf('"%s" tipi için ayrıştırıcı kayıtlı değil.', $field->getType()->value));
    }
}
