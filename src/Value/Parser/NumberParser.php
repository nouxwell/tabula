<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Parser;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\Formatter\NumberFormatter;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ValueParser;

/**
 * Tam sayı, ondalık ve miktar alanlarının ayrıştırıcısı.
 *
 * Yerelleştirilmiş dize çözümlemesi burada TEKRAR YAZILMAZ: `NumberFormatter::parse()`
 * zaten "1.234,56"yı, veritabanının kanonik "1234.5600" dizesini, muhasebe parantezini
 * `(1.234,56)` ve sondaki eksiyi `1.234,56-` çözüyor. İkinci bir ayrıştırıcı yazmak, bu
 * paketin ortadan kaldırmak için var olduğu hatanın (aynı mantığın sekiz sınıfa
 * kopyalanması) aynısı olurdu.
 *
 * Biçimlendiriciden AYRILDIĞI nokta hoşgörüdür: `NumberFormatter` okunamayan hücreyi boş
 * basıp geçer, çünkü 40 bin satırlık bir rapor tek bozuk hücre yüzünden ölmemeli. Burada
 * aynı davranış veritabanına YANLIŞ VERİ yazmak demektir; okunamayan değer istisna
 * fırlatır ve içe aktarma döngüsü onu satır/alan bilgisiyle bir `RowError`e çevirir.
 */
final class NumberParser implements ValueParser
{
    public function supports(FieldType $type): bool
    {
        return match ($type) {
            FieldType::Integer, FieldType::Decimal, FieldType::Quantity => true,
            default => false,
        };
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        if (StringParser::isBlank($raw)) {
            return null;
        }

        // `preferLocalized: true` — metin yerelleştirilmiş bir hücreden geliyor. Kanonik
        // okuma "1.234"ü 1.234 sayardı; oysa Türkçe ayarlarla bu 1234'tür ve aradaki fark
        // sessizce 1000 katlık bir sapma olurdu.
        $number = NumberFormatter::parse($raw, $context->settings->numbers, preferLocalized: true);

        // Dolu bir hücreden `null` dönmesi "sayı değil" demektir; boş hücre yukarıda elendi.
        if (null === $number) {
            throw ParseException::notANumber($field, StringParser::describe($raw));
        }

        // Tip sözleşmesi net tutulur: Integer daima `int`, Decimal/Quantity daima `float`.
        // Biçimlendirici tarafı `int|float` karışığı döndürebilir (orada tek tüketici
        // `number_format`tir); burada değeri bir entity setter'ı alacak, dolayısıyla
        // tipin satırdan satıra değişmemesi gerekir.
        return FieldType::Integer === $field->getType()
            ? $this->toInteger($number, $field, $raw)
            : (float) $number;
    }

    /**
     * Tam sayı alanında ondalık değer HATADIR.
     *
     * Stok sayımı kolonuna yazılmış `12,5` bir yazım hatasıdır; sessizce 12'ye yuvarlamak
     * yarım kutuyu kayıttan silmek olur. Kullanıcı hatayı görmeli, biz tahmin etmemeliyiz.
     */
    private function toInteger(int|float $number, Field $field, mixed $raw): int
    {
        if (is_int($number)) {
            return $number;
        }

        // `PHP_INT_MAX` üstündeki değer de tam sayı değildir: `(int)` dönüşümü işareti bile
        // ters çevirebilir. Karşılaştırma KATI olmalı, çünkü `(float) PHP_INT_MAX` yukarı
        // yuvarlanarak 2^63 olur — yani sınırın kendisi `int`e sığmaz.
        if (floor($number) !== $number || abs($number) >= (float) PHP_INT_MAX) {
            throw ParseException::notAnInteger($field, StringParser::describe($raw));
        }

        return (int) $number;
    }
}
