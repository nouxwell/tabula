<?php

declare(strict_types=1);

namespace Balin\Tabula\Bridge\Symfony;

use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Orientation;
use Balin\Tabula\Export\Page\Overflow;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Export\Page\PageSize;
use Balin\Tabula\Export\Writer\CsvOptions;
use Balin\Tabula\Export\Writer\PdfOptions;
use Balin\Tabula\Export\Writer\XlsxOptions;
use Balin\Tabula\Settings\DateSettings;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\SymbolPosition;
use Balin\Tabula\Settings\TabulaSettings;
use InvalidArgumentException;

/**
 * Builds the settings objects from a configuration array.
 *
 * Why a separate factory: the core settings classes carry enums such as `SymbolPosition`, and
 * passing an enum instance to the container (DI) is an area that changes from version to
 * version. Thanks to the factory the container only ever sees PLAIN ARRAYS, the conversion is
 * done once on the PHP side — and the core settings classes stay unaware of Symfony.
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
     * @param array{delimiter: string, enclosure: string, escape: string, write_bom: bool, line_ending: string} $config
     */
    public static function csv(array $config): CsvOptions
    {
        return new CsvOptions(
            delimiter: $config['delimiter'],
            enclosure: $config['enclosure'],
            escape: $config['escape'],
            writeBom: $config['write_bom'],
            // Because writing "\r\n" in YAML runs into the escaping rules and silently turns
            // into two literal characters, the config takes the names `crlf`/`lf` rather than
            // the raw sequence.
            //
            // `match` + `default: throw` was used DELIBERATELY in place of a ternary: this
            // method is a public static API, and if a new name is added to the config tree
            // tomorrow, a ternary would silently drop it to CRLF. An unknown name has to make
            // some noise.
            lineEnding: match ($config['line_ending']) {
                'lf' => "\n",
                'crlf' => "\r\n",
                default => throw new InvalidArgumentException(sprintf('Unknown line ending name: "%s". Expected: "crlf" or "lf".', $config['line_ending'])),
            },
        );
    }

    /**
     * @param array{creator: string, header_fill: string, required_header_fill: string, header_border_color: string, bold_header: bool, freeze_header: bool, auto_filter: bool} $config
     */
    public static function xlsx(array $config): XlsxOptions
    {
        return new XlsxOptions(
            creator: $config['creator'],
            headerFill: $config['header_fill'],
            requiredHeaderFill: $config['required_header_fill'],
            headerBorderColor: $config['header_border_color'],
            boldHeader: $config['bold_header'],
            freezeHeader: $config['freeze_header'],
            autoFilter: $config['auto_filter'],
        );
    }

    /**
     * @param array{page_size: string, orientation: string, margin_mm: float, min_column_width_mm: float, max_columns: int|null, overflow: string, font_family: string, font_size_pt: float, repeat_header: bool} $config
     */
    public static function pdf(array $config): PdfOptions
    {
        $page = Page::of(PageSize::from($config['page_size']));

        $page = match (Orientation::from($config['orientation'])) {
            Orientation::Landscape => $page->landscape(),
            Orientation::Portrait => $page->portrait(),
        };

        // The (float) cast is NOT decoration: Symfony's `FloatNode` accepts an integer but
        // DOES NOT CONVERT it — a yaml file saying `margin_mm: 10` lands here as `int(10)`.
        // If we do not cast here, an int leaks into `Page`'s float fields and any code (or
        // test) making a comparison such as `10 === $margin` is thrown off by the type.
        $page = $page->margins((float) $config['margin_mm']);

        $budget = ColumnBudget::fit()
            ->minWidth((float) $config['min_column_width_mm'])
            ->max($config['max_columns'])
            ->overflow(Overflow::from($config['overflow']));

        // `title` and `anchor()` are deliberately ABSENT from the configuration: both change
        // from one export to the next (the document title, the anchor columns that identify a
        // row) and cannot have a global default. They are given at the call site with
        // `->page()` / `->columns()`.
        return new PdfOptions(
            page: $page,
            budget: $budget,
            fontFamily: $config['font_family'],
            fontSizePt: (float) $config['font_size_pt'],
            repeatHeader: $config['repeat_header'],
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
