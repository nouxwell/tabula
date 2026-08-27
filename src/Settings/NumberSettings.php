<?php

declare(strict_types=1);

namespace Balin\Tabula\Settings;

use Balin\Tabula\Schema\FieldType;

/**
 * Sayı ve para biçimlendirme ayarları.
 *
 * Varsayılan basamak sayıları ALAN TİPİNE göredir, alan ADINA göre değil.
 * (Mevcut ERP'de 173 satırlık küresel `NumericFieldMap` alan adına bakıyor ve aynı adı
 * kullanan iki modül birbirinin biçimini paylaşmak zorunda kalıyordu.)
 * Alan kendi `decimals()` değerini verirse o kazanır.
 */
final readonly class NumberSettings
{
    /**
     * @param array<string, string> $currencySymbols para birimi kodu => simge, ör. ['TRY' => '₺']
     */
    public function __construct(
        public string $decimalSeparator = ',',
        public string $thousandSeparator = '.',
        public int $decimalDigits = 2,
        public int $quantityDigits = 3,
        public int $moneyDigits = 2,
        public array $currencySymbols = [],
        public SymbolPosition $symbolPosition = SymbolPosition::After,
    ) {
    }

    /** Alan kendi basamağını vermemişse tipin varsayılanı. */
    public function digitsFor(FieldType $type): int
    {
        return match ($type) {
            FieldType::Integer => 0,
            FieldType::Quantity => $this->quantityDigits,
            FieldType::Money => $this->moneyDigits,
            default => $this->decimalDigits,
        };
    }

    public function symbolFor(?string $currencyCode): ?string
    {
        if (null === $currencyCode || SymbolPosition::None === $this->symbolPosition) {
            return null;
        }

        return $this->currencySymbols[$currencyCode] ?? $currencyCode;
    }

    /** Simgeyi sayıya iliştirir. */
    public function applySymbol(string $number, ?string $symbol): string
    {
        if (null === $symbol || '' === $symbol) {
            return $number;
        }

        return match ($this->symbolPosition) {
            SymbolPosition::Before => $symbol.' '.$number,
            SymbolPosition::After => $number.' '.$symbol,
            SymbolPosition::None => $number,
        };
    }

    /** Excel'in anlayacağı sayı biçim kodu, ör. `#,##0.000`. */
    public function excelFormatCode(int $digits): string
    {
        return $digits > 0
            ? '#,##0.'.str_repeat('0', $digits)
            : '#,##0';
    }
}
