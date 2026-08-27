<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use LogicException;

/**
 * Yazıcı yaşam döngüsü sırası bozulduğunda fırlatılır.
 *
 * `\LogicException` soyundan gelir çünkü bunlar veri ya da ortam hatası değil,
 * çağıran koddaki sıra hatasıdır: open → startSheet → writeRow* → finishSheet → close.
 * Mevcut ERP'de yazıcıların böyle bir sözleşmesi yoktu; yanlış sırada çağrılan bir
 * yazıcı ya sessizce boş dosya üretiyor ya da anlaşılmaz bir "null" hatasıyla patlıyordu.
 */
final class WriterException extends LogicException implements TabulaException
{
    public static function notOpened(): self
    {
        return new self('Yazıcı açık değil: önce open(...) çağırın.');
    }

    public static function noActiveSheet(): self
    {
        return new self('Aktif sayfa yok: önce startSheet(...) çağırın.');
    }

    public static function alreadyOpen(): self
    {
        return new self('Yazıcı zaten açık: yeniden open(...) çağırmadan önce close() çağırın.');
    }

    /**
     * CSV ayraç/sarmalayıcı/kaçış karakteri tek BAYT olmalıdır.
     *
     * Doğrulama kurulum anında yapılır, yazma anında değil: `fputcsv()` bu durumda ham bir
     * `ValueError` fırlatır — `TabulaException` OLMADIĞI için dışa aktarmayı saran
     * `catch (TabulaException)` bloğu onu kaçırır — üstelik hata başlık satırında, dosya
     * zaten oluşturulduktan sonra patlar ve diskte yalnız BOM içeren bir dosya bırakır.
     *
     * En sık tetikleyici: `tabula.yaml` içinde `escape: '\\'`. YAML tek tırnaklı dizide
     * ters bölü kaçışı işlemez, yani o değer İKİ karakterdir.
     */
    public static function csvCharacterMustBeSingleByte(string $setting, string $value, bool $emptyAllowed = false): self
    {
        return new self(sprintf(
            'CSV "%s" ayarı tek bayt%s olmalı; "%s" verildi (%d bayt). '
            .'YAML\'da ters bölü yazarken dikkat: \'\\\\\' iki karakterdir.',
            $setting,
            $emptyAllowed ? ' ya da boş' : '',
            $value,
            \strlen($value),
        ));
    }

    /**
     * Excel renkleri ARGB (`FFRRGGBB`) ya da RGB (`RRGGBB`) olmalıdır.
     *
     * PhpSpreadsheet'in `setARGB()` metodu ayrıştıramadığı değeri SESSİZCE yutar; sonuç
     * kullanıcıya ancak dosyayı açınca görünür ve iki tipik hâli vardır:
     *  - CSS alışkanlığıyla yazılan `#F2F2F2` → başlık bembeyaz olur, "ayar çalışmadı" sanılır.
     *  - Boş dize → başlık satırı SİMSİYAH bir bant olarak basılır.
     */
    public static function invalidArgbColor(string $setting, string $value): self
    {
        return new self(sprintf(
            'Excel "%s" ayarı ARGB bekliyor (FFRRGGBB ya da RRGGBB); "%s" verildi. '
            .'Başındaki "#" karakterini kaldırın.',
            $setting,
            $value,
        ));
    }
}
