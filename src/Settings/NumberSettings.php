<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Settings;

use Nouxwell\Tabula\Schema\FieldType;

/**
 * Number and currency formatting settings.
 *
 * The default digit counts follow the FIELD TYPE, not the field NAME.
 * (In the system this replaces, a 173-line global `NumericFieldMap` looked at the field name,
 * so two modules using the same name were forced to share each other's format.)
 * If the field supplies its own `decimals()` value, that one wins.
 */
final readonly class NumberSettings
{
    /**
     * @param array<string, string> $currencySymbols currency code => symbol, e.g. ['TRY' => '₺']
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

    /** The default for the type, used when the field did not supply its own digit count. */
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

    /** Attaches the symbol to the number. */
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

    /** A number format code Excel understands, e.g. `#,##0.000`. */
    public function excelFormatCode(int $digits): string
    {
        return $digits > 0
            ? '#,##0.'.str_repeat('0', $digits)
            : '#,##0';
    }
}
