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
 * Formatter for money fields.
 *
 * The critical rule: the SYMBOL only ever goes into the visible text. The value written to
 * Excel stays a bare number, and the symbol moves into the cell's FORMAT CODE
 * ('#,##0.00 "₺"'). That way the column both shows the symbol and stays summable. The system
 * this replaces wrote the string "1.234,56 ₺" into the cell; because the accounting
 * column was text in Excel, no total could be taken and the user had to "convert to number"
 * by hand every single time.
 *
 * The currency can change per row (`Field::currency(fn ($row) => $row['currencyCode'])`),
 * because a single listing can hold TRY and USD rows side by side.
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
            // If the field has taken formatting over, the symbol/digit logic is its
            // responsibility too.
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        $numbers = $context->settings->numbers;

        // Parsing is SHARED with NumberFormatter: localised strings such as "1.234,56" and
        // the strings Doctrine returns for DECIMAL columns are resolved by the same rules.
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
     * The field's currency code: a fixed string, or a closure that receives the ROW.
     *
     * The closure returning an enum or a stringable object is just as common (Currency::TRY,
     * a value object); anything not recognised counts as `null` — if the currency is
     * unknown, the cell comes out without a symbol but with the correct number.
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
     * Embeds the symbol into the Excel format code.
     *
     * In Excel, literal text is carried inside double quotes; a quote inside the symbol
     * would break the code, so it is dropped (symbols such as ₺, $ or € do not contain one
     * anyway). The spacing matches `NumberSettings::applySymbol()` exactly, so that the cell
     * shown in Excel lines up character for character with the text in CSV/PDF.
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
            // symbolFor() already returns null in this position; keep the code bare anyway.
            SymbolPosition::None => $code,
        };
    }
}
