<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Formatter;

use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\Cell;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\ValueFormatter;

/**
 * Boole alanları.
 *
 * Hücreye yazılan değer ham `true/false` DEĞİL, çevrilmiş metindir (Evet/Hayır).
 * Sebebi Excel: gerçek boole yazılırsa hücrede yerel Excel diline göre TRUE/DOĞRU
 * görünür ve şablondaki açılır liste ile eşleşmez.
 *
 * Eski ERP'nin hatası tam olarak buydu: hücre metnini bir çeviri ailesinden
 * (`general.yes`), şablonun doğrulama listesini BAŞKA bir aileden (`form.true`)
 * üretiyordu; sonuçta yazılan değer kendi izin listesinde bulunmuyor, Excel dosyayı
 * "onarılması gerekiyor" diye açıyordu. Burada tek kaynak vardır:
 * `TabulaSettings::$boolTrueKey` / `$boolFalseKey` — şablon üreticisi de aynı ikiliyi okur.
 *
 * Girdi tarafı bilerek geniştir; veri tabanından `1`, CSV'den `'true'`, kullanıcının
 * doldurduğu şablondan `'Evet'` gelebilir.
 */
final class BoolFormatter implements ValueFormatter
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Bool === $type;
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        $value = $this->toBool($raw);

        // Tanınmayan değer de boş hücredir: "belirsiz"i uydurma bir Hayır'a çevirmek,
        // dışa aktarımı okuyan kişiye yanlış bilgi vermek olur.
        if (null === $value) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        $settings = $context->settings;
        $text = $context->trans($value ? $settings->boolTrueKey : $settings->boolFalseKey);

        // Cell::text → value === text: Excel'de de, CSV/PDF'te de aynı metin.
        return Cell::text($text, $align);
    }

    /** Tanınmayan ya da boş her şey için `null` (= boş hücre). */
    private function toBool(mixed $raw): ?bool
    {
        if (null === $raw) {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        // Doctrine tinyint(1), SQL COUNT'ları ve JSON API'leri 0/1 üretir.
        if (is_int($raw)) {
            return 0 !== $raw;
        }

        if (is_float($raw)) {
            return 0.0 !== $raw;
        }

        if (!is_string($raw)) {
            return null;
        }

        // İçe aktarmada kullanıcı kendi dilinde yazar; Türkçe karşılıklar da tanınır.
        return match (mb_strtolower(trim($raw), 'UTF-8')) {
            '1', 'true', 'yes', 'on', 'y', 't', 'evet', 'e' => true,
            '0', 'false', 'no', 'off', 'n', 'f', 'hayır', 'hayir', 'h' => false,
            default => null,
        };
    }
}
