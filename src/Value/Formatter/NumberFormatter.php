<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Formatter;

use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Value\Cell;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\ValueFormatter;
use Stringable;

/**
 * Tam sayı, ondalık ve miktar alanlarının biçimlendiricisi.
 *
 * Hücreye İKİ gösterim birden konur: Excel'e çıplak sayı + biçim kodu, CSV/PDF'e
 * yerelleştirilmiş metin. Mevcut ERP sayıyı yalnız metin olarak yazıyordu; Excel
 * kolonu metin olduğu için toplanmıyor, sıralanmıyor, filtrelenmiyordu.
 *
 * Basamak sayısı önce alanın kendi `decimals()` değerinden, o yoksa TİPİN
 * varsayılanından gelir (alan ADINA bakan küresel harita yok — bkz. NumberSettings).
 */
final class NumberFormatter implements ValueFormatter
{
    public function supports(FieldType $type): bool
    {
        return match ($type) {
            FieldType::Integer, FieldType::Decimal, FieldType::Quantity => true,
            default => false,
        };
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            // Alan biçimlendirmeyi tümüyle devraldıysa sayı mantığı hiç çalışmaz:
            // sonuç metindir, Excel'de de metin kalır (kullanıcının açık tercihi).
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        $numbers = $context->settings->numbers;
        $number = self::parse($raw, $numbers);

        // Okunamayan değer TEK satırı boşaltır, dışa aktarmayı düşürmez:
        // 40 bin satırlık bir raporun tek bozuk hücre yüzünden ölmesi kabul edilemez.
        if (null === $number) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        $digits = self::digitsFor($field, $numbers);
        $value = self::coerceToType($number, $field->getType());

        return Cell::number(
            $value,
            number_format((float) $value, $digits, $numbers->decimalSeparator, $numbers->thousandSeparator),
            $numbers->excelFormatCode($digits),
            $align,
        );
    }

    /**
     * Ham değeri sayıya çevirir; çeviremezse `null` döner (istisna ATMAZ).
     *
     * MoneyFormatter da bunu kullanır: ayrıştırma mantığının kopyalanması, bu paketin
     * ortadan kaldırmak için var olduğu hatanın (aynı `normalize()` metodunun sekiz ayrı
     * sınıfa yapıştırılmış olması) aynısı olurdu.
     *
     * Dize ayrıştırması gerekiyor çünkü Doctrine `DECIMAL` kolonlarını ve DQL skaler
     * projeksiyonlarını DİZE olarak döndürür ("1234.5600"). Üstelik ara katmanlardan
     * hâlihazırda yerelleştirilmiş dizeler de gelebilir ("1.234,56"); mevcut ERP'nin
     * yaptığı düz `(float)` dönüşümü bu dizeyi sessizce 1.0 yapıyordu.
     */
    public static function parse(mixed $raw, NumberSettings $numbers): int|float|null
    {
        if (null === $raw) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }

        if (is_float($raw)) {
            // NAN/INF sayı değildir; number_format bunları anlamsız metne çevirir.
            return is_finite($raw) ? $raw : null;
        }

        // BCMath\Number, Brick\Math gibi sayı nesneleri metinleşebiliyorsa dize yolundan geçer.
        if ($raw instanceof Stringable) {
            $raw = (string) $raw;
        }

        if (!is_string($raw)) {
            return null;
        }

        $text = trim($raw);
        if ('' === $text) {
            return null;
        }

        // Kanonik hâl — veritabanının döndürdüğü "1234.5600", "-0.5", "1e3".
        // Buradaki tek başına nokta HER ZAMAN ondalıktır; "1.234" = 1.234.
        if (is_numeric($text)) {
            $canonical = $text + 0;

            return is_float($canonical) && !is_finite($canonical) ? null : $canonical;
        }

        return self::parseLocalized($text, $numbers);
    }

    /**
     * Negatif gösterimleri tanır.
     *
     * Yalnız baştaki eksiye bakmak yetmez: temizlik adımı parantezleri ve sondaki eksiyi
     * sildiği için bu biçimler POZİTİF okunur ve işaret sessizce döner. Muhasebe verisinde
     * bir borcun alacağa dönmesi, bu kütüphanenin yapabileceği en pahalı hatadır.
     *
     *  - `(1.234,56)`  muhasebe gösterimi
     *  - `1.234,56-`   SAP/ERP çıktılarında sondaki eksi
     *  - `₺ -1.234,56` simge önde, eksi sayının hemen önünde
     */
    private static function isNegative(string $text): bool
    {
        if (str_contains($text, '(') && str_contains($text, ')')) {
            return true;
        }

        if (str_starts_with($text, '-') || str_ends_with($text, '-')) {
            return true;
        }

        return 1 === preg_match('/^\D*-\s*\d/u', $text);
    }

    /**
     * Ayırıcı taşıyan (ya da simge/boşluk bulaşmış) dizeyi çözer.
     */
    private static function parseLocalized(string $text, NumberSettings $numbers): int|float|null
    {
        // Eksi işareti temizlikte kaybolacağı için önce alınır.
        $negative = self::isNegative($text);

        // Kırılmaz/ince boşluklar bazı yerelleştirmelerde binlik ayırıcıdır; normal boşluğa indirgenir.
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);

        $separators = self::separators($numbers);
        $cleaned = preg_replace(
            '/[^0-9'.preg_quote(implode('', $separators), '/').']/u',
            '',
            $text,
        ) ?? '';

        if ('' === $cleaned) {
            return null;
        }

        [$lastSeparator, $lastPosition] = self::lastSeparator($cleaned, $separators);

        if (null === $lastSeparator) {
            return self::sign(self::toInteger($cleaned), $negative);
        }

        $head = substr($cleaned, 0, $lastPosition);
        $tail = substr($cleaned, $lastPosition + strlen($lastSeparator));

        if (!self::isDecimalSeparator($lastSeparator, $head, $tail, $separators, $numbers)) {
            return self::sign(self::toInteger(str_replace($separators, '', $cleaned)), $negative);
        }

        $whole = str_replace($separators, '', $head);
        if ('' === $whole) {
            $whole = '0';
        }

        if (!ctype_digit($whole) || !ctype_digit($tail)) {
            return null;
        }

        return self::sign((float) ($whole.'.'.$tail), $negative);
    }

    /**
     * En sağdaki ayırıcının ondalık mı, binlik mi olduğuna karar verir.
     *
     * Belirsizlik kaçınılmazdır ("1.234" hem bin iki yüz otuz dört hem 1,234 olabilir);
     * kurallar en olası okumayı seçecek şekilde sıralanmıştır.
     *
     * @param list<string> $separators
     */
    private static function isDecimalSeparator(
        string $separator,
        string $head,
        string $tail,
        array $separators,
        NumberSettings $numbers,
    ): bool {
        // Ondalık kısım yalnızca rakamdan oluşur.
        if ('' === $tail || !ctype_digit($tail)) {
            return false;
        }

        // (a) Solunda FARKLI bir ayırıcı varsa en sağdaki kesinlikle ondalıktır: "1.234,56".
        foreach ($separators as $other) {
            if ($other !== $separator && str_contains($head, $other)) {
                return true;
            }
        }

        // (b) Aynı ayırıcı birden çok kez geçiyorsa gruplayıcıdır: "1.234.567".
        if (str_contains($head, $separator)) {
            return false;
        }

        // (c) Tek geçiş, yapılandırılmış ondalık ayırıcı: ayar kazanır.
        if ($separator === $numbers->decimalSeparator) {
            return true;
        }

        // (d) Tek geçiş, yapılandırılmış binlik ayırıcı: tam üç rakam izliyorsa gruplama
        //     ("1.234 ₺" → 1234), değilse ondalık ("1.5 ₺" → 1.5).
        if ($separator === $numbers->thousandSeparator) {
            return 3 !== strlen($tail);
        }

        // (e) Yapılandırılmamış evrensel aday: kanonik nokta/virgül gibi ondalık say.
        return true;
    }

    /**
     * Dizede en sağda duran ayırıcı ve bayt konumu.
     *
     * @param list<string> $separators
     *
     * @return array{0: string|null, 1: int}
     */
    private static function lastSeparator(string $cleaned, array $separators): array
    {
        $found = null;
        $at = -1;

        foreach ($separators as $separator) {
            $position = strrpos($cleaned, $separator);
            if (false !== $position && $position > $at) {
                $found = $separator;
                $at = $position;
            }
        }

        return [$found, $at];
    }

    /**
     * Aday ayırıcılar: yapılandırılanlar + evrensel nokta/virgül.
     *
     * Evrensel olanlar da listededir; Türkçe ayarla çalışırken İngilizce biçimli bir
     * dize gelirse ("1,234.56") ayırıcı yine tanınsın diye.
     *
     * @return list<string>
     */
    private static function separators(NumberSettings $numbers): array
    {
        $candidates = [$numbers->decimalSeparator, $numbers->thousandSeparator, '.', ','];

        return array_values(array_unique(array_filter(
            $candidates,
            static fn (string $candidate): bool => '' !== $candidate,
        )));
    }

    /** Yalnız rakamdan oluşan dizeyi sayıya çevirir; PHP_INT_MAX üstünde float'a yükselir. */
    private static function toInteger(string $digits): int|float|null
    {
        return ctype_digit($digits) ? $digits + 0 : null;
    }

    private static function sign(int|float|null $value, bool $negative): int|float|null
    {
        if (null === $value || !$negative) {
            return $value;
        }

        return -$value;
    }

    /** Alanın kendi basamağı yoksa tipin varsayılanı; tam sayıda ondalık yoktur. */
    private static function digitsFor(Field $field, NumberSettings $numbers): int
    {
        if (FieldType::Integer === $field->getType()) {
            return 0;
        }

        return max(0, $field->getDecimals() ?? $numbers->digitsFor($field->getType()));
    }

    /**
     * Tam sayı alanı Excel'e gerçekten `int` olarak gider — hücre "1,00" değil "1" görünsün,
     * kayıt/stok adedi ondalıklı sanılmasın diye.
     */
    private static function coerceToType(int|float $number, FieldType $type): int|float
    {
        if (FieldType::Integer !== $type) {
            return $number;
        }

        $rounded = round((float) $number);

        // PHP_INT_MAX dışındaki float'ta (int) dönüşümü uyarı verir ve işareti ters çevirir;
        // float bırakmak Excel açısından hâlâ sayıdır, yanlış sayıdan iyidir.
        // Karşılaştırma KATI olmalı: (float) PHP_INT_MAX yukarı yuvarlanarak 2^63 olur,
        // yani sınırın kendisi int'e sığmaz.
        return abs($rounded) < (float) PHP_INT_MAX ? (int) $rounded : $rounded;
    }
}
