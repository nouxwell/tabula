<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Formatter;

use BackedEnum;
use Balin\Tabula\Contract\TranslatableEnum;
use Balin\Tabula\Exception\ValueException;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\Cell;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\ValueFormatter;
use Closure;
use Stringable;
use TypeError;
use UnitEnum;

/**
 * Enum ve sabit seçenek kümesi alanları.
 *
 * Çeviri anahtarı üç adımda çözülür — sırayla, ilk tutan kazanır:
 *  1. `TranslatableEnum::translationKey()` — yeni enum'ların tercih edilen yolu.
 *  2. `label()` metodu — mevcut ERP'nin 200'den fazla enum'unda bulunan gelenek;
 *     tek satır bile değiştirmeden çalışsınlar diye desteklenir.
 *  3. `BackedEnum::$value` / `UnitEnum::$name` — hiçbir sözleşme yoksa son çare.
 *
 * Çıkan anahtar HER durumda `trans()`'tan geçer. Anahtar katalogda yoksa
 * `ArrayTranslator` anahtarın kendisini döndürür; yani hücre asla boş kalmaz —
 * eski dışa aktarmada eksik çeviri boş sütun demekti.
 *
 * `Options` tipinde değer, alanın seçenek haritasında aranır; bulunan etiket de
 * `trans()`'tan geçirilir, böylece seçenek kümeleri çeviri anahtarı taşıyabilir.
 */
final class EnumFormatter implements ValueFormatter
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Enum === $type || FieldType::Options === $type;
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        $enumClass = $field->getEnumClass();

        // Yapılandırma hatası veriden önce patlasın: yanlış sınıf adıyla kurulmuş bir alan,
        // ilk satırda değil, ilk denemede görünmeli.
        if (null !== $enumClass && !is_a($enumClass, UnitEnum::class, true)) {
            throw ValueException::notAnEnum($field, $enumClass);
        }

        if (null === $raw) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        if (FieldType::Options === $field->getType()) {
            return $this->formatOption($raw, $field, $row, $context, $align);
        }

        $case = $this->hydrate($raw, $enumClass);

        // Enum'a oturmayan değer: uydurma bir etiket üretmek yerine ham hâli yazılır.
        if (null === $case) {
            return Cell::text($this->rawText($raw), $align);
        }

        return Cell::text($context->trans($this->translationKey($case)), $align);
    }

    /**
     * Ham değeri enum örneğine çevirir.
     *
     * Doctrine `enumType` kolonları çoğu zaman zaten örnek döndürür; ama skaler
     * projeksiyonlarda ve dizi kaynaklarında düz `value` gelir — alan bir enum sınıfı
     * bildirmişse oradan geri hidratlanır.
     *
     * @param class-string|null $enumClass
     */
    private function hydrate(mixed $raw, ?string $enumClass): ?UnitEnum
    {
        if ($raw instanceof UnitEnum) {
            return $raw;
        }

        if (null === $enumClass || !is_a($enumClass, BackedEnum::class, true)) {
            return null;
        }

        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        try {
            return $enumClass::tryFrom($raw);
        } catch (TypeError) {
            // Dize destekli enum'a int (ya da tersi) verilmiş: `strict_types` bunu
            // TypeError'a çevirir; bizim için bu sadece "eşleşme yok" demektir.
            return null;
        }
    }

    private function translationKey(UnitEnum $case): string
    {
        if ($case instanceof TranslatableEnum) {
            return $case->translationKey();
        }

        // Mevcut ERP geleneği: her enum kendi `label()` metoduyla anahtarını verir.
        if (method_exists($case, 'label')) {
            return (string) $case->label();
        }

        return $case instanceof BackedEnum ? (string) $case->value : $case->name;
    }

    private function formatOption(mixed $raw, Field $field, mixed $row, FormatContext $context, Align $align): Cell
    {
        $options = $field->getOptions();

        // Kapanış seçenekler satırdan türeyebilir (ör. şirkete göre değişen listeler).
        if ($options instanceof Closure) {
            $options = $options($row);
        }

        $key = $this->optionKey($raw);

        if (!is_array($options) || null === $key || !array_key_exists($key, $options)) {
            return Cell::text($this->rawText($raw), $align);
        }

        // Etiketin kendisi de çeviri anahtarı olabilir; düz metinse çevirmen aynen döndürür.
        return Cell::text($context->trans((string) $options[$key]), $align);
    }

    /** Seçenek haritasında aranacak anahtar; dizi anahtarı olamayan tipler için `null`. */
    private function optionKey(mixed $raw): int|string|null
    {
        if ($raw instanceof BackedEnum) {
            return $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        return is_int($raw) || is_string($raw) ? $raw : null;
    }

    private function rawText(mixed $raw): string
    {
        if ($raw instanceof BackedEnum) {
            return (string) $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        if ($raw instanceof Stringable) {
            return (string) $raw;
        }

        return is_scalar($raw) ? (string) $raw : '';
    }
}
