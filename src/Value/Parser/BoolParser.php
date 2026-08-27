<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Parser;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ValueParser;
use Stringable;

/**
 * Boole alanlarının ayrıştırıcısı.
 *
 * ÖNCE çevrilmiş "Evet"/"Hayır" denenir, çünkü şablonun açılır listesine yazılan metin
 * tam olarak odur (`BoolFormatter`, `TabulaSettings::$boolTrueKey/$boolFalseKey` ikilisini
 * kullanır — ayrıştırıcı da aynı ikiliyi okur). Mevcut ERP'de hücre metnini bir çeviri
 * ailesi, doğrulama listesini başka bir aile üretiyordu; kendi şablonunun yazdığı değer
 * kendi listesinde bulunmuyordu. Burada tek kaynak vardır ve gidiş-dönüş kapanır.
 *
 * Ardından her yerden gelebilecek olağan gösterimler tanınır (`1/0`, `true/false`,
 * `yes/no`, `evet/hayır`, gerçek boole ve tinyint). Girdi tarafı bilerek geniştir;
 * kullanıcı dosyayı kendi alışkanlığıyla doldurur.
 *
 * Biçimlendiriciden AYRILDIĞI nokta: `BoolFormatter` tanımadığı değeri boş hücreye
 * çevirip geçer. Ayrıştırıcı bunu yapamaz — "belirsiz"i uydurma bir `false`a çevirmek
 * (ya da alanı boş geçmek) veritabanına yanlış veri yazmaktır. Tanınmayan değer istisna
 * fırlatır ve mesaj KABUL EDİLEN biçimleri sayar; kullanıcı hatayı ancak neyi
 * yazabileceğini görürse düzeltebilir.
 */
final class BoolParser implements ValueParser
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Bool === $type;
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        if (StringParser::isBlank($raw)) {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        // Doctrine tinyint(1), SQL COUNT'ları ve JSON API'leri 0/1 üretir; Excel'in sayısal
        // hücresi de float döner.
        if (is_int($raw)) {
            return 0 !== $raw;
        }

        if (is_float($raw)) {
            return 0.0 !== $raw;
        }

        if ($raw instanceof Stringable) {
            $raw = (string) $raw;
        }

        if (!is_string($raw)) {
            throw ParseException::notABoolean($field, StringParser::describe($raw), $this->accepted($context));
        }

        $needle = $this->fold($raw);
        $settings = $context->settings;

        // Şablonun kendi yazdığı metin her şeyden önce gelir.
        if ($needle === $this->fold($context->trans($settings->boolTrueKey))) {
            return true;
        }

        if ($needle === $this->fold($context->trans($settings->boolFalseKey))) {
            return false;
        }

        return match ($needle) {
            '1', 'true', 'yes', 'on', 'y', 't', 'evet', 'e' => true,
            '0', 'false', 'no', 'off', 'n', 'f', 'hayır', 'hayir', 'h' => false,
            default => throw ParseException::notABoolean($field, StringParser::describe($raw), $this->accepted($context)),
        };
    }

    /**
     * Hata mesajında sayılacak kabul listesi.
     *
     * Başta ÇEVRİLMİŞ ikili durur: kullanıcının şablonda gördüğü ve yazması beklenen
     * metin odur. Ardından dosyayı elle dolduranların sık kullandığı gösterimler gelir.
     * Liste, çeviri zaten "Evet/Hayır" olduğunda kendini tekrarlamasın diye
     * büyük/küçük harf duyarsız olarak tekilleştirilir.
     *
     * @return list<string>
     */
    private function accepted(ParseContext $context): array
    {
        $settings = $context->settings;

        $accepted = [
            $context->trans($settings->boolTrueKey),
            $context->trans($settings->boolFalseKey),
            'Evet',
            'Hayır',
            '1',
            '0',
            'true',
            'false',
        ];

        $unique = [];
        foreach ($accepted as $value) {
            $key = $this->fold($value);
            if ('' !== $key && !array_key_exists($key, $unique)) {
                $unique[$key] = $value;
            }
        }

        return array_values($unique);
    }

    /** Karşılaştırmalar kırpılmış ve küçük harfe indirgenmiş metin üzerinden yapılır. */
    private function fold(string $value): string
    {
        return mb_strtolower(StringParser::clean($value), 'UTF-8');
    }
}
