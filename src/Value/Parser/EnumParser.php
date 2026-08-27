<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Parser;

use BackedEnum;
use Balin\Tabula\Contract\TranslatableEnum;
use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Exception\ValueException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ValueParser;
use Closure;
use Stringable;
use UnitEnum;

/**
 * Enum ve sabit seçenek kümesi alanlarının ayrıştırıcısı.
 *
 * Kullanıcı hücrede ÇEVRİLMİŞ ETİKETİ görür ("Açık"), veritabanı ise enum'un kendisini
 * bekler (`Status::Open`). Bu sınıf çeviriyi geriye çevirir; eşleştirme sırası
 * `EnumFormatter`ın yazdığı değerden başlar:
 *  1. Her case'in çevrilmiş etiketi — şablonun açılır listesine yazdığımız metin budur,
 *     dolayısıyla gidiş-dönüşün kapandığı yer burasıdır. Anahtar çözümü de aynı zinciri
 *     izler: `TranslatableEnum::translationKey()` → `label()` → `value`/`name`.
 *  2. Enum'un `value` değeri — dosyayı bir geliştirici ya da başka bir sistem doldurmuşsa.
 *  3. Case adı — son çare.
 * Üç adım da AYRI TURDUR: bir case'in etiketi başka bir case'in `value` değeriyle
 * çakışırsa etiket kazanır, çünkü kullanıcının gördüğü metin odur.
 *
 * Karşılaştırma kırpılmış ve büyük/küçük harf duyarsızdır ("AÇIK", "açık " de tutar);
 * bunun dışında hoşgörü yoktur. `EnumFormatter` tanımadığı değeri ham hâliyle basıp
 * geçer — bir dışa aktarmada bu yalnızca çirkin bir hücredir. Burada aynı davranış,
 * listede olmayan bir durumun veritabanına sızması demektir; eşleşmeyen değer istisna
 * fırlatır ve mesaj GEÇERLİ SEÇENEKLERİ sayar.
 */
final class EnumParser implements ValueParser
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Enum === $type || FieldType::Options === $type;
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        $enumClass = $field->getEnumClass();

        // Yapılandırma hatası veriden önce patlasın: yanlış sınıf adıyla kurulmuş bir alan
        // satır hatası değil, kurulum hatasıdır — 5000 satırda 5000 kez tekrarlanmamalı.
        if (null !== $enumClass && !is_a($enumClass, UnitEnum::class, true)) {
            throw ValueException::notAnEnum($field, $enumClass);
        }

        if (StringParser::isBlank($raw)) {
            return null;
        }

        return FieldType::Options === $field->getType()
            ? $this->parseOption($raw, $field, $context)
            : $this->parseEnum($raw, $field, $enumClass, $context);
    }

    /** @param class-string|null $enumClass */
    private function parseEnum(mixed $raw, Field $field, ?string $enumClass, ParseContext $context): UnitEnum
    {
        // Dizi kaynaklı yeniden içe aktarmalarda değer zaten örnek olabilir.
        if ($raw instanceof UnitEnum) {
            return $raw;
        }

        $cases = $this->cases($enumClass);
        $needle = $this->needle($raw);

        if (null !== $needle) {
            foreach ($cases as $case) {
                if ($needle === $this->fold($context->trans($this->translationKey($case)))) {
                    return $case;
                }
            }

            foreach ($cases as $case) {
                if ($case instanceof BackedEnum && $needle === $this->fold((string) $case->value)) {
                    return $case;
                }
            }

            foreach ($cases as $case) {
                if ($needle === $this->fold($case->name)) {
                    return $case;
                }
            }
        }

        throw ParseException::notAnOption($field, StringParser::describe($raw), $this->enumLabels($cases, $context));
    }

    /**
     * Seçenek kümesinde ETİKETTEN ANAHTARA döner; veritabanına yazılan değer anahtardır.
     *
     * Sınır: seçenekler kapanışla verilmişse (`Field::options($key, fn ($row) => …)`)
     * kapanış SATIRI bekler, ama içe aktarma sırasında henüz satır YOKTUR — kapanış
     * `null` ile çağrılır. Satırdan türeyen seçenek listeleri bu yüzden içe aktarma
     * tarafında kullanılamaz; kapanış `null` satırla çalışamıyorsa `TypeError` fırlatır
     * ve bu KASITLI olarak yakalanmaz: yapılandırma hatası, her satırda tekrarlanan
     * anlamsız bir "listede yok" mesajı olarak değil, kurulum hatası olarak görünmelidir.
     */
    private function parseOption(mixed $raw, Field $field, ParseContext $context): int|string
    {
        $options = $field->getOptions();

        if ($options instanceof Closure) {
            $options = $options(null);
        }

        $options = is_array($options) ? $options : [];
        $needle = $this->needle($raw);

        if (null !== $needle) {
            // 1. Çevrilmiş etiket: şablonun açılır listesine yazılan metin.
            foreach ($options as $key => $label) {
                if ($needle === $this->fold($context->trans($label))) {
                    return $key;
                }
            }

            // 2. Ham etiket: çeviri anahtarının kendisi ya da düz metin (katalogda karşılığı
            //    olmayan bir anahtar `trans()`tan aynen geçer, yine de açıkça denenir).
            foreach ($options as $key => $label) {
                if ($needle === $this->fold($label)) {
                    return $key;
                }
            }

            // 3. Anahtarın kendisi: dosyayı elle dolduranlar çoğu zaman kodu yazar.
            //    Enum tarafındaki `value` adımının seçenek kümesindeki karşılığıdır.
            foreach ($options as $key => $label) {
                if ($needle === $this->fold((string) $key)) {
                    return $key;
                }
            }
        }

        throw ParseException::notAnOption($field, StringParser::describe($raw), $this->optionLabels($options, $context));
    }

    /**
     * @param class-string|null $enumClass
     *
     * @return list<UnitEnum>
     */
    private function cases(?string $enumClass): array
    {
        if (null === $enumClass || !is_a($enumClass, UnitEnum::class, true)) {
            return [];
        }

        return array_values($enumClass::cases());
    }

    /** `EnumFormatter` ile AYNI zincir; iki taraf ayrışırsa gidiş-dönüş kapanmaz. */
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

    /**
     * @param list<UnitEnum> $cases
     *
     * @return list<string>
     */
    private function enumLabels(array $cases, ParseContext $context): array
    {
        return array_values(array_unique(array_map(
            fn (UnitEnum $case): string => $context->trans($this->translationKey($case)),
            $cases,
        )));
    }

    /**
     * @param array<int|string, string> $options
     *
     * @return list<string>
     */
    private function optionLabels(array $options, ParseContext $context): array
    {
        return array_values(array_unique(array_map(
            static fn (string $label): string => $context->trans($label),
            $options,
        )));
    }

    /**
     * Aranacak metin; karşılaştırılabilir bir gösterimi olmayan tipler için `null`.
     *
     * Tam sayı destekli enum'larda hücre SAYI olarak gelir (Excel `3`ü metin yapmaz),
     * bu yüzden sayısal değerler de metne indirgenip aranır.
     */
    private function needle(mixed $raw): ?string
    {
        if (is_string($raw) || is_int($raw) || is_float($raw) || $raw instanceof Stringable) {
            return $this->fold(StringParser::describe($raw));
        }

        return null;
    }

    private function fold(string $value): string
    {
        return mb_strtolower(StringParser::clean($value), 'UTF-8');
    }
}
