<?php

declare(strict_types=1);

namespace Balin\Tabula\Bridge\Symfony;

use Balin\Tabula\Settings\DateSettings;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\SymbolPosition;
use Balin\Tabula\Settings\TabulaSettings;

/**
 * Yapılandırma dizisinden ayar nesnelerini kurar.
 *
 * Neden ayrı bir fabrika: çekirdek ayar sınıfları `SymbolPosition` gibi enum'lar taşır ve
 * kapsayıcıya (DI) enum örneği geçirmek sürüm sürüm değişen bir alan. Fabrika sayesinde
 * kapsayıcı yalnızca DÜZ DİZİ görür, dönüşüm PHP tarafında bir kez yapılır — ve çekirdek
 * ayar sınıfları Symfony'den habersiz kalır.
 *
 * @phpstan-type NumberConfig array{decimal_separator: string, thousand_separator: string, decimal_digits: int, quantity_digits: int, money_digits: int, symbol_position: string, currency_symbols: array<string, string>}
 * @phpstan-type DateConfig array{date_pattern: string, datetime_pattern: string, excel_date_format: string, excel_datetime_format: string}
 */
final class SettingsFactory
{
    /**
     * @param NumberConfig $config
     */
    public static function numbers(array $config): NumberSettings
    {
        return new NumberSettings(
            decimalSeparator: $config['decimal_separator'],
            thousandSeparator: $config['thousand_separator'],
            decimalDigits: $config['decimal_digits'],
            quantityDigits: $config['quantity_digits'],
            moneyDigits: $config['money_digits'],
            currencySymbols: $config['currency_symbols'],
            symbolPosition: SymbolPosition::from($config['symbol_position']),
        );
    }

    /**
     * @param DateConfig $config
     */
    public static function dates(array $config): DateSettings
    {
        return new DateSettings(
            datePattern: $config['date_pattern'],
            dateTimePattern: $config['datetime_pattern'],
            excelDateFormat: $config['excel_date_format'],
            excelDateTimeFormat: $config['excel_datetime_format'],
        );
    }

    /**
     * @param array{default_locale: string, empty_text: string, bool_true_key: string, bool_false_key: string, max_rows_per_sheet: int} $config
     */
    public static function settings(array $config, NumberSettings $numbers, DateSettings $dates): TabulaSettings
    {
        return new TabulaSettings(
            numbers: $numbers,
            dates: $dates,
            defaultLocale: $config['default_locale'],
            boolTrueKey: $config['bool_true_key'],
            boolFalseKey: $config['bool_false_key'],
            emptyText: $config['empty_text'],
            maxRowsPerSheet: $config['max_rows_per_sheet'],
        );
    }
}
