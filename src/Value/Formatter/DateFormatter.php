<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Formatter;

use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\Cell;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\ValueFormatter;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Stringable;

/**
 * Tarih ve tarih/saat alanları.
 *
 * İki çıktı üretir:
 *  - Xlsx'te GERÇEK tarih: hücreye Excel seri numarası (1899-12-30'dan bu yana geçen gün)
 *    yazılır ve biçim kodu verilir. Böylece hücre Excel'de sıralanabilir, filtrelenebilir,
 *    fark alınabilir. Eski dışa aktarma önceden biçimlenmiş DİZE yazıyordu; kullanıcı
 *    tarihe göre sıraladığında "01.12.2024" ile "02.01.2023" alfabetik diziliyordu.
 *  - Diğer biçimlerde yerelleştirilmiş metin.
 *
 * Seri numarası dönüşümü burada elle yapılır (düz aritmetik); bu sınıf bilerek
 * PhpSpreadsheet'e bağlı değildir — CSV/PDF yolunda Excel kütüphanesi yüklü olmayabilir.
 *
 * Ayrıştırma tarafı hoşgörülüdür ve ASLA fırlatmaz: eski kayıtlarda `0000-00-00`,
 * yarım tarihler ve serbest metin var; tek bir bozuk satır 50 bin satırlık aktarımı
 * düşürmemeli, o hücre ham hâliyle görünmeli.
 */
final class DateFormatter implements ValueFormatter
{
    /** Unix epoch'un (1970-01-01) Excel gün sayacındaki karşılığı. */
    private const int EXCEL_EPOCH_OFFSET = 25569;

    private const int SECONDS_PER_DAY = 86400;

    /**
     * Salt rakamdan oluşan dizeler için asgari uzunluk.
     *
     * Böyle bir dize ancak bu kadar uzunsa zaman damgası sayılır; aksi hâlde `2024`
     * gibi yıl parçaları 1970'in ilk saatlerine dönüşürdü.
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
        // Alanın kendi deseni varsa o kazanır; yoksa genel ayar (tarih/tarih-saat ayrı).
        $pattern = $field->getPattern() ?? $context->settings->dates->patternFor($type);

        $date = $this->toDate($raw, $pattern);

        if (null === $date) {
            // Ayrıştırılamadı: veriyi kaybetmektense ham hâliyle göster.
            return Cell::text($this->rawText($raw), $align);
        }

        $text = $date->format($pattern);

        // Hücre BİÇİMDEN BAĞIMSIZ üretilir: `value` her zaman Excel seri numarası, `text` her
        // zaman yerelleştirilmiş metindir; hangisinin kullanılacağına YAZICI karar verir
        // (CSV/PDF yalnız `text` okur). Burada `$context->format`e göre dallanmak, `to()` ile
        // `writer()` farklı verildiğinde tarihleri Excel'e metin olarak yazdırıyordu — yani
        // sınıfın önlemek için var olduğu "tarihler alfabetik sıralanıyor" hatasının ta kendisi.
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

        // MySQL sıfır tarihi (`0000-00-00`, `0000-00-00 00:00:00`): PHP bunu sessizce
        // MÖ 1'e ayrıştırır ve Excel'de eksi seri numarası = `#######` demektir.
        // Anlamı "tarih yok" olduğuna göre hücre de boş kalmalı.
        $digits = preg_replace('/\D/', '', $text) ?? '';

        return 8 <= strlen($digits) && '' === trim($digits, '0');
    }

    /**
     * Excel seri numarası: 1899-12-30'dan bu yana geçen gün + günün kesri.
     *
     * Ofset timestamp'e eklenir, çünkü Excel'in saat dilimi kavramı yoktur; hücrede
     * duvar saati görünmelidir. Aksi hâlde Europe/Istanbul'da saklanan 09:00, Excel'de
     * 06:00 olarak açılırdı.
     *
     * Bilinen sınır: Excel 1900'ü artık yıl sanır, bu yüzden 1900-03-01'den ÖNCEKİ
     * tarihlerde bu formül Excel'den bir gün sapar. ERP verisinde böyle tarih yok;
     * hataya karşılık gelen düzeltmeyi eklemek formülü okunmaz hâle getirirdi.
     */
    private function toExcelSerial(DateTimeImmutable $date, FieldType $type): float
    {
        $seconds = $date->getTimestamp() + $date->getOffset();
        $serial = $seconds / self::SECONDS_PER_DAY + self::EXCEL_EPOCH_OFFSET;

        // Saf tarih kolonunda saat artığı kalmasın: aynı gün her satırda aynı seriyi alsın.
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

    /** Zaman damgaları UTC kabul edilir; kütüphanenin saat dilimi ayarı yoktur. */
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

        // Salt rakam: Unix zaman damgası olarak dizeye yazılmış olabilir.
        if (ctype_digit($value) && strlen($value) >= self::TIMESTAMP_MIN_DIGITS) {
            return $this->fromTimestamp((int) $value);
        }

        // Son çare: PHP'nin serbest ayrıştırıcısı (ISO 8601, `2024-01-05 09:30:00` vb.).
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

        // Uyarılar da hata sayılır: "32.13.2024" gibi girdiler `createFromFormat`ta
        // sessizce bir sonraki aya taşar. Kısmi eşleşmeyi kabul etmiyoruz.
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (0 < $errors['warning_count'] || 0 < $errors['error_count'])) {
            return null;
        }

        return $parsed;
    }

    /**
     * Desenin başına `!` ekler: desende bulunmayan alanlar "şu an" yerine sıfırlanır.
     *
     * Bu olmadan `d.m.Y` ile ayrıştırılan bir tarih, çalıştırma saatini de taşır ve
     * aynı gün iki farklı Excel seri numarası üretebilirdi.
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
