<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Formatter;

use BackedEnum;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\Cell;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\ValueFormatter;
use DateTimeInterface;
use Stringable;
use UnitEnum;

/**
 * Metin alanları.
 *
 * "Sadece dizeye çevir" gibi görünür ama mevcut ERP'de kırılan yer tam burasıydı:
 * Doctrine'in `enumType` tanımlı kolonları skaler DQL projeksiyonlarında bile ENUM
 * ÖRNEĞİ döndürür; `(string)` cast'ı "Object of class ... could not be converted to
 * string" ile patlıyor, try/catch'li sürümlerde ise hücre sessizce boş kalıyordu.
 * Bu yüzden enum örnekleri burada AÇIKÇA ele alınır (metin alanı olsa bile).
 */
final class StringFormatter implements ValueFormatter
{
    public function supports(FieldType $type): bool
    {
        return FieldType::String === $type;
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        // Alan kendi biçimlendiricisini verdiyse tartışma yok: o kazanır.
        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        if (null === $raw) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        return Cell::text($this->stringify($raw, $field, $context), $align);
    }

    private function stringify(mixed $raw, Field $field, FormatContext $context): string
    {
        if (is_string($raw)) {
            return $raw;
        }

        // Doctrine enumType kolonları: skaler select'ten dize değil, enum örneği gelir.
        if ($raw instanceof BackedEnum) {
            return (string) $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        if (is_bool($raw)) {
            // `(string) false` boş dize üretir; metin kolonunda bu, veriyi sessizce
            // yutmak demektir (eski dışa aktarmanın klasik "boş hücre" şikâyeti).
            $settings = $context->settings;

            return $context->trans($raw ? $settings->boolTrueKey : $settings->boolFalseKey);
        }

        if (is_int($raw) || is_float($raw)) {
            return (string) $raw;
        }

        if (is_array($raw)) {
            // Türkçe karakterler ve yollar okunur kalsın: ç / \/ kaçışları yok.
            $json = json_encode($raw, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

            return false === $json ? '' : $json;
        }

        if (is_object($raw) && ($raw instanceof Stringable || method_exists($raw, '__toString'))) {
            return (string) $raw;
        }

        // Metin alanına düşen bir tarih şema hatasıdır ama değer kurtarılabilir; nötr bir
        // ISO benzeri gösterimle yazılır (desen tahmin etmek bu sınıfın işi değil).
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d H:i:s');
        }

        // Buraya düşen değer için sessiz bir varsayım üretmiyoruz — ama İSTİSNA DA ATMIYORUZ:
        // 40.000 satırlık bir rapor tek bir bozuk hücre yüzünden ölmemeli. Diğer tüm
        // biçimlendiriciler (Number/Date/Enum) aynı kuralı izliyor; bu sınıf tek istisnaydı.
        // Şema hatası boş hücre olarak görünür, çöken bir dışa aktarma olarak değil.
        return '';
    }
}
