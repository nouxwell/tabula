<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Formatter;

use BackedEnum;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\SymbolPosition;
use Balin\Tabula\Value\Cell;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\ValueFormatter;
use Closure;
use Stringable;

/**
 * Para alanlarının biçimlendiricisi.
 *
 * Kritik kural: SİMGE yalnızca GÖRÜNEN metne girer. Excel'e yazılan değer çıplak sayı
 * kalır, simge hücrenin BİÇİM KODUNA taşınır ('#,##0.00 "₺"'). Böylece kolon hem
 * simgeli görünür hem de toplanabilir. Mevcut ERP hücreye "1.234,56 ₺" dizesini
 * yazıyordu; muhasebe kolonu Excel'de metin olduğu için toplam alınamıyor, kullanıcı
 * da her seferinde elle "sayıya çevir" yapıyordu.
 *
 * Para birimi satır başına değişebilir (`Field::currency(fn ($row) => $row['currencyCode'])`),
 * çünkü tek bir listede TRY ve USD satırları yan yana durabilir.
 */
final class MoneyFormatter implements ValueFormatter
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Money === $type;
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            // Alan biçimlendirmeyi devraldıysa simge/basamak mantığı da onun sorumluluğudur.
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        $numbers = $context->settings->numbers;

        // Ayrıştırma NumberFormatter ile ORTAK: "1.234,56" gibi yerelleştirilmiş dizeler
        // ve Doctrine'in DECIMAL kolonlarda döndürdüğü dizeler aynı kurallarla çözülür.
        $amount = NumberFormatter::parse($raw, $numbers);

        if (null === $amount) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        $digits = max(0, $field->getDecimals() ?? $numbers->digitsFor(FieldType::Money));
        $symbol = $numbers->symbolFor(self::currencyCode($field, $row));

        $plain = number_format((float) $amount, $digits, $numbers->decimalSeparator, $numbers->thousandSeparator);

        return Cell::number(
            $amount,
            $numbers->applySymbol($plain, $symbol),
            self::excelFormat($numbers, $digits, $symbol),
            $align,
        );
    }

    /**
     * Alanın para birimi kodu: sabit dize ya da SATIRI alan kapanış.
     *
     * Kapanıştan enum ya da metinleşebilir nesne dönmesi de olağandır (Currency::TRY,
     * bir değer nesnesi); tanınmayan her şey `null` sayılır — para birimi bilinmiyorsa
     * hücre simgesiz ama doğru sayıyla çıkar.
     */
    private static function currencyCode(Field $field, mixed $row): ?string
    {
        $currency = $field->getCurrency();

        if ($currency instanceof Closure) {
            $currency = $currency($row);
        }

        if ($currency instanceof BackedEnum) {
            $currency = (string) $currency->value;
        } elseif ($currency instanceof Stringable) {
            $currency = (string) $currency;
        }

        if (!is_string($currency)) {
            return null;
        }

        $code = trim($currency);

        return '' === $code ? null : $code;
    }

    /**
     * Simgeyi Excel biçim koduna gömer.
     *
     * Excel'de düz metin çift tırnak içinde taşınır; simgenin içindeki tırnak kodu
     * bozacağı için atılır (₺, $, € gibi simgelerde zaten yoktur).
     * Boşluk yerleşimi `NumberSettings::applySymbol()` ile aynıdır ki Excel'de görünen
     * hücre, CSV/PDF'teki metinle birebir örtüşsün.
     */
    private static function excelFormat(NumberSettings $numbers, int $digits, ?string $symbol): string
    {
        $code = $numbers->excelFormatCode($digits);

        if (null === $symbol || '' === $symbol) {
            return $code;
        }

        $literal = '"'.str_replace('"', '', $symbol).'"';

        return match ($numbers->symbolPosition) {
            SymbolPosition::Before => $literal.' '.$code,
            SymbolPosition::After => $code.' '.$literal,
            // symbolFor() bu konumda zaten null döner; yine de kod çıplak kalsın.
            SymbolPosition::None => $code,
        };
    }
}
