<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use Balin\Tabula\Import\RowError;
use RuntimeException;

/**
 * İçe aktarma AKIŞI kurulamadığında ya da dosya bütünüyle kullanılamaz olduğunda fırlatılır.
 *
 * Tek bir hücrenin reddedilmesi istisna DEĞİLDİR — o `RowError` olarak toplanır. Buradaki
 * hatalar "dosyayı hiç işleyemeyiz" sınıfındandır.
 */
final class ImportException extends RuntimeException implements TabulaException
{
    /** @var list<RowError> */
    private array $rowErrors = [];

    public static function noSource(): self
    {
        return new self('İçe aktarılacak dosya verilmedi: önce ->from(...) çağırın.');
    }

    /**
     * `->each()` verilmeden `->run()` çağrıldı.
     *
     * Sessizce "0 satır aktarıldı" dönmek en kötü seçenekti: kütüphane satırları
     * veritabanına YAZMAZ, yalnız geri çağırıma verir — geri çağırım yoksa iş fiilen
     * hiç yapılmamış demektir ve kullanıcı bunu ancak veri gelmediğinde fark ederdi.
     */
    public static function noHandler(): self
    {
        return new self(
            'Satır geri çağırımı verilmedi: önce ->each(fn (ImportedRow $row) => ...) çağırın. '
            .'Tabula satırları ayrıştırır, veritabanına yazmaz; kaydetme işi geri çağırımındır.',
        );
    }

    public static function fileNotReadable(string $path): self
    {
        return new self(sprintf('"%s" dosyası okunamıyor.', $path));
    }

    public static function unsupportedFile(string $path): self
    {
        return new self(sprintf(
            '"%s" tanınmayan bir dosya türü. Desteklenenler: .xlsx, .xls, .csv',
            $path,
        ));
    }

    public static function emptyFile(string $path): self
    {
        return new self(sprintf('"%s" boş: başlık satırı bile yok.', $path));
    }

    /**
     * Dosyadaki hiçbir başlık şemayla eşleşmedi.
     *
     * @param list<string> $found
     * @param list<string> $expected
     */
    public static function noMatchingColumns(array $found, array $expected): self
    {
        return new self(sprintf(
            'Dosyadaki hiçbir kolon şemayla eşleşmedi. Dosyada bulunanlar: %s. Beklenenler: %s. '
            .'Doğru şablonu indirdiğinizden emin olun.',
            [] === $found ? '(hiç yok)' : implode(', ', $found),
            implode(', ', $expected),
        ));
    }

    /**
     * `MatchStrategy::Key` istendi ama dosyada anahtar satırı yok.
     */
    public static function keyRowMissing(): self
    {
        return new self(
            'Anahtar satırı bulunamadı; bu dosya Tabula şablonundan üretilmemiş olabilir. '
            .'Şablonu yeniden indirin ya da etiketle eşleşmeye izin verin (MatchStrategy::Auto).',
        );
    }

    /** @param list<string> $known */
    public static function unknownRowField(string $key, array $known): self
    {
        return new self(sprintf(
            'Satırda "%s" diye bir alan yok. Bulunanlar: %s',
            $key,
            [] === $known ? '(hiç yok)' : implode(', ', $known),
        ));
    }

    /**
     * `ErrorMode::FailFast` altında ilk hata: akış durdu.
     *
     * @param list<RowError> $errors
     */
    public static function stoppedAtFirstError(array $errors): self
    {
        $first = $errors[0] ?? null;

        // `$first?->row ?? 0` yerine açık `null` denetimi: PHPStan `?->` operatörünü
        // `??`nin solunda gereksiz sayıp (nullsafe.neverNull) seviye 8'de hata veriyor,
        // `?->`yi `->` yapmak ise BOŞ listede ölümcül olurdu. Üretilen metin aynı.
        $exception = new self(sprintf(
            'İçe aktarma ilk hatada durduruldu (satır %d): %s',
            null === $first ? 0 : $first->row,
            null === $first ? 'bilinmeyen hata' : $first->message,
        ));
        $exception->rowErrors = $errors;

        return $exception;
    }

    /** @return list<RowError> */
    public function rowErrors(): array
    {
        return $this->rowErrors;
    }
}
