<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Parser;

use BackedEnum;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ValueParser;
use DateTimeInterface;
use Stringable;
use UnitEnum;

/**
 * Metin alanlarının ayrıştırıcısı — `StringFormatter`ın ters yönü.
 *
 * Asıl işi "dizeye çevir" değil, EXCEL'İN BOZDUĞUNU ONARMAKTIR. Kullanıcı hücreye
 * `8691234567890123` yazdığında Excel bunu sayı sanar ve okuyucuya `8.6912345678901E+15`
 * float'ı olarak geri verir; düz `(string)` cast'ı bu barkodu üstel gösterimle
 * veritabanına yazar. Mevcut ERP'nin içe aktarmasında stok kodları, IBAN'lar ve
 * barkodlar tam olarak böyle bozuluyordu. Burada float HER ZAMAN üstelsiz basılır.
 *
 * Bu sınıf aynı zamanda TÜM ayrıştırıcıların ortak yardımcılarını taşır
 * (`isBlank()`, `clean()`, `describe()`): tıpkı `MoneyFormatter`ın kendi sayı
 * ayrıştırıcısını yazmak yerine `NumberFormatter::parse()` çağırması gibi. Bu paketin
 * var oluş sebebi, aynı `normalize()` metodunun sekiz sınıfa kopyalanmış olmasıydı;
 * ayrıştırıcı tarafında aynı hatayı tekrarlamıyoruz.
 *
 * Diğer ayrıştırıcılar KATIdır (okunamayan hücre `ParseException` fırlatır), bu sınıf
 * fırlatmaz: sözleşmede "notAString" diye bir hata yoktur, çünkü her skaler değerin bir
 * metin karşılığı vardır. Metne çevrilemeyen tek şey (dizi/kaynak/`__toString`siz nesne)
 * bir okuyucudan zaten gelemez; geldiğinde boş hücre sayılır.
 */
final class StringParser implements ValueParser
{
    /**
     * Görünmez boşluklar: kırılmaz boşluk aileleri + BOM.
     *
     * `trim()` bunları TANIMAZ. Elektronik tablolardan gelen hücrelerde kırılmaz boşluk
     * sıradan bir kalıntıdır; UTF-8 BOM'u ise CSV'nin ilk hücresine yapışır. Temizlenmezse
     * "boş görünen ama boş olmayan" hücreler zorunlu alan denetimini geçer ve veritabanına
     * görünmez karakter yazılır.
     */
    private const array INVISIBLE_SPACES = ["\u{00A0}", "\u{202F}", "\u{2009}", "\u{FEFF}"];

    /**
     * Ondalık kısmı basarken kullanılan azami hassasiyet.
     *
     * PHP'nin `(string)` dönüşümü `precision` ini ayarına bakar ve 14'ün üstünde üstel
     * gösterime kaçar; `%.14F` hem yerelden bağımsızdır hem de üstel üretmez.
     */
    private const int FLOAT_PRECISION = 14;

    public function supports(FieldType $type): bool
    {
        return FieldType::String === $type;
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        if (self::isBlank($raw)) {
            return null;
        }

        $text = self::clean($this->stringify($raw, $context));

        // Yalnızca görünmez karakterden ibaret hücre de boştur.
        return '' === $text ? null : $text;
    }

    /**
     * Hücre BOŞ mu? Tüm ayrıştırıcıların ortak kuralı.
     *
     * Boşluk bir HATA DEĞİLDİR: zorunluluk denetimi alanı bilen içe aktarma döngüsünde
     * yapılır, burada değil. Ayrıştırıcı yalnızca "değer yok" der.
     */
    public static function isBlank(mixed $raw): bool
    {
        if (null === $raw) {
            return true;
        }

        if ($raw instanceof Stringable) {
            $raw = (string) $raw;
        }

        return is_string($raw) && '' === self::clean($raw);
    }

    /** Görünmez boşlukları normal boşluğa indirger ve kırpar. */
    public static function clean(string $text): string
    {
        return trim(str_replace(self::INVISIBLE_SPACES, ' ', $text));
    }

    /**
     * Hata mesajlarında görünecek ham gösterim.
     *
     * `ParseException` mesajları doğrudan kullanıcıya gider ve "hangi değer" bilgisini
     * taşımak zorundadır; "geçersiz değer" diyen bir mesaj kullanıcıya hiçbir şey
     * anlatmaz. Metne dönmeyen tipler için değerin kendisi değil TİPİ yazılır — hata
     * mesajı üretirken ikinci bir hata çıkarmak kabul edilemez.
     */
    public static function describe(mixed $raw): string
    {
        if (is_string($raw)) {
            return self::clean($raw);
        }

        if (is_bool($raw)) {
            return $raw ? 'true' : 'false';
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_float($raw)) {
            return self::floatToText($raw);
        }

        if ($raw instanceof BackedEnum) {
            return (string) $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d H:i:s');
        }

        if ($raw instanceof Stringable) {
            return self::clean((string) $raw);
        }

        return get_debug_type($raw);
    }

    private function stringify(mixed $raw, ParseContext $context): string
    {
        if (is_string($raw)) {
            return $raw;
        }

        // Excel'in metin kolonunu sayıya çevirmesi: barkod/stok kodu/IBAN buradan geçer.
        if (is_float($raw)) {
            return self::floatToText($raw);
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_bool($raw)) {
            // `(string) false` boş dize üretir; metin kolonunda bu, veriyi sessizce yutmaktır.
            // Dışa aktarma da aynı ikiliyi yazdığı için gidiş-dönüş kapanır.
            $settings = $context->settings;

            return $context->trans($raw ? $settings->boolTrueKey : $settings->boolFalseKey);
        }

        // Doctrine enumType kolonları skaler projeksiyonlarda bile enum ÖRNEĞİ döndürür;
        // dizi kaynaklı yeniden içe aktarmalarda bu değerler ayrıştırıcıya kadar gelebilir.
        if ($raw instanceof BackedEnum) {
            return (string) $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        if ($raw instanceof Stringable) {
            return (string) $raw;
        }

        // Metin alanına düşen tarih şema hatasıdır ama değer kurtarılabilir; nötr ISO
        // benzeri gösterim yazılır (desen tahmin etmek bu sınıfın işi değil).
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d H:i:s');
        }

        if (is_array($raw)) {
            // Türkçe karakterler ve yollar okunur kalsın: ç / \/ kaçışları yok.
            $json = json_encode($raw, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

            return false === $json ? '' : $json;
        }

        // Buraya düşen değer bir okuyucudan gelemez (kaynak/`__toString`siz nesne).
        // Boş bırakılır; zorunluysa hatayı içe aktarma döngüsü üretir.
        return '';
    }

    /**
     * Float'ı ÜSTELSİZ basar.
     *
     * Tam sayı değerli float doğrudan basamaklarıyla yazılır: `8.69123456789E+12` değil
     * `8691234567890`. 2^53 üstündeki değerlerde basamakların bir kısmı zaten float'ın
     * kendi yuvarlamasıdır — ama üstel gösterim de aynı bilgiyi taşımaz, üstelik
     * veritabanına yazıldığında hiç düzeltilemez hâle gelir.
     */
    private static function floatToText(float $value): string
    {
        if (!is_finite($value)) {
            return (string) $value;
        }

        if (floor($value) === $value) {
            return number_format($value, 0, '.', '');
        }

        $text = rtrim(rtrim(sprintf('%.'.self::FLOAT_PRECISION.'F', $value), '0'), '.');

        return '' === $text ? '0' : $text;
    }
}
