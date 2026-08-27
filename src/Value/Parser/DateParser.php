<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Parser;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ValueParser;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Stringable;

/**
 * Tarih ve tarih/saat alanlarının ayrıştırıcısı — daima `DateTimeImmutable` döndürür.
 *
 * Kaynak sırası, hücrenin nereden geldiğine göre kurulmuştur:
 *  1. `DateTimeInterface` — okuyucu hazır nesne verdiyse dokunulmaz.
 *  2. EXCEL SERİ NUMARASI — tarih biçimli bir hücre elektronik tabloda SAYIDIR
 *     (45292 = 2024-01-01). `DateFormatter`ın yazdığı değerin tersi burada çözülür;
 *     dönüşüm yine elle yapılır, bu sınıf bilerek PhpSpreadsheet'e bağlı değildir.
 *  3. Alanın deseni (`d.m.Y`) — kullanıcının şablonda GÖRDÜĞÜ biçim.
 *  4. ISO/serbest biçim — `2024-01-05`, `2024-01-05T09:30:00` gibi kanonik gösterimler.
 *
 * Biçimlendiriciden AYRILDIĞI üç nokta:
 *  - `DateFormatter` çözemediği hücreyi HAM METİN olarak basıp geçer; burada çözülemeyen
 *    değer istisnadır ve mesaj BEKLENEN DESENİ söyler ("beklenen biçim: d.m.Y"), çünkü
 *    kullanıcı hatayı ancak doğru biçimi görürse düzeltebilir.
 *  - MySQL sıfır tarihi (`0000-00-00`) dışa aktarmada boş hücredir (eski kayıtlarda çok
 *    sayıda var, rapor onlar yüzünden ölmemeli). İçe aktarmada ise REDDEDİLİR: "tarih yok"
 *    demek isteyen kullanıcı hücreyi boş bırakır; sıfır tarihi başka bir sistemin
 *    bozuk çıktısıdır ve veritabanına taşınmamalıdır.
 *  - Sayısal değer biçimlendiricide UNIX ZAMAN DAMGASI sayılır (veri tabanından öyle
 *    gelir), burada EXCEL SERİ NUMARASI (elektronik tablodan öyle gelir). Aynı sayının
 *    iki yönde farklı anlamı vardır; kaynak farklıdır.
 */
final class DateParser implements ValueParser
{
    /** Unix epoch'un (1970-01-01) Excel gün sayacındaki karşılığı. */
    private const int EXCEL_EPOCH_OFFSET = 25569;

    private const int SECONDS_PER_DAY = 86400;

    /** 2958465 = 9999-12-31; Excel'in tarih olarak yorumladığı son seri numarası. */
    private const int MAX_EXCEL_SERIAL = 2958465;

    /**
     * Salt rakamdan oluşan bir DİZE için asgari seri numarası (10000 = 1927-05-18).
     *
     * Sayısal hücre elektronik tabloda kesinlikle seri numarasıdır, ama METİN olarak gelen
     * "2024" bir yıl parçası olabilir; seri sayılsaydı sessizce 1905-07-16'ya dönerdi.
     * PHP'nin serbest ayrıştırıcısı da bu dizeyi kurtarmaz, "20:24" saati olarak okur —
     * bu yüzden aralığa girmeyen salt-rakam dizeler hiç denenmeden reddedilir.
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
        // Alanın kendi deseni varsa o kazanır; yoksa genel ayar (tarih/tarih-saat ayrı).
        $pattern = $field->getPattern() ?? $context->settings->dates->patternFor($type);

        $date = $this->toDate($raw, $pattern);

        if (null === $date) {
            throw ParseException::notADate($field, StringParser::describe($raw), $pattern);
        }

        // Saf tarih kolonunda saat artığı kalmaz: aynı gün her satırda aynı değeri alsın,
        // `BETWEEN` sorguları gün sonunu kaçırmasın.
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

        // Sayısal hücre = seri numarası. Aralık geniş tutulur (1 = 1899-12-31): böyle bir
        // hücre elektronik tabloda zaten tarih olarak biçimlenmiştir.
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

        // Salt rakam: CSV'ye biçimlenmeden düşmüş seri numarası olabilir. Aralığın dışında
        // kalan salt-rakam dize hiç denenmez (bkz. MIN_TEXT_SERIAL).
        if (ctype_digit($value)) {
            return $this->fromExcelSerial((float) $value, (float) self::MIN_TEXT_SERIAL);
        }

        // İçinde rakam olmayan metin tarih değildir. Bu denetim olmadan PHP'nin serbest
        // ayrıştırıcısı "now", "tomorrow", "next monday" gibi dizeleri KABUL eder ve
        // içe aktarma anının tarihini veritabanına yazardı.
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

        // Uyarılar da hata sayılır: "32.13.2024" gibi girdiler `createFromFormat`ta sessizce
        // bir sonraki aya taşar ve kullanıcı yanlış tarihi hiç fark etmezdi. Kısmi eşleşme
        // de kabul edilmez ("15.01.2024 saat 9" desene oturmaz).
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (0 < $errors['warning_count'] || 0 < $errors['error_count'])) {
            return null;
        }

        return $parsed;
    }

    /**
     * Desenin başına `!` ekler: desende bulunmayan alanlar "şu an" yerine sıfırlanır.
     *
     * Bu olmadan `d.m.Y` ile okunan bir tarih, içe aktarmanın ÇALIŞMA SAATİNİ de taşırdı;
     * aynı gün içe aktarılan iki dosya aynı tarih için farklı değerler üretirdi.
     */
    private function resetPattern(string $pattern): string
    {
        if (str_starts_with($pattern, '!') || str_contains($pattern, '|')) {
            return $pattern;
        }

        return '!'.$pattern;
    }

    /**
     * Excel seri numarasını tarihe çevirir — `DateFormatter::toExcelSerial()`ın tersi.
     *
     * Sonuç UTC'dir ve DUVAR SAATİNİ korur: biçimlendirici, hücrede yerel saat görünsün
     * diye seri numarasına saat dilimi farkını ekliyordu; burada aynı fark geri
     * çıkarılmaz, çünkü kullanıcının dosyada gördüğü saat neyse veritabanına da o
     * yazılmalıdır. Kütüphanenin saat dilimi ayarı yoktur; olsaydı 09:00 yazan bir hücre
     * 06:00 olarak içe aktarılırdı.
     *
     * Yuvarlama zorunludur: 09:30 kesri 0.39583333… olarak saklanır ve düz `(int)`
     * dönüşümü saati 09:29:59 yapardı.
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
     * MySQL sıfır tarihi (`0000-00-00`, `0000-00-00 00:00:00`).
     *
     * PHP bunu sessizce MÖ 1'e ayrıştırır; denetlenmezse veritabanına gerçek ama saçma bir
     * tarih yazılırdı.
     */
    private function isZeroDate(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return 8 <= strlen($digits) && '' === trim($digits, '0');
    }
}
