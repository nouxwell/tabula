<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Bridge\Symfony;

use Nouxwell\Tabula\Bridge\Symfony\SettingsFactory;
use Nouxwell\Tabula\Export\Column;
use Nouxwell\Tabula\Export\Page\Orientation;
use Nouxwell\Tabula\Export\Page\Overflow;
use Nouxwell\Tabula\Schema\Align;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Schema\Priority;
use Nouxwell\Tabula\Settings\DateSettings;
use Nouxwell\Tabula\Settings\NumberSettings;
use Nouxwell\Tabula\Settings\SymbolPosition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * The configuration array → settings object mapping.
 *
 * There is no logic inside the factory; its only risk is binding to the WRONG ARGUMENT.
 * Because `quantity_digits` and `money_digits` have the same type (int), a transposition is
 * caught neither by PHP nor by PHPStan — the mistake only shows up in the exported file, once
 * the quantity column displays two decimals. That is why in every test the values are
 * DELIBERATELY chosen to differ from one another and to sit far away from the defaults: a
 * transposition turns the test red.
 *
 * @phpstan-type NumberConfig array{decimal_separator: string, thousand_separator: string, decimal_digits: int, quantity_digits: int, money_digits: int, symbol_position: string, currency_symbols: array<string, string>}
 * @phpstan-type PdfConfig array{page_size: string, orientation: string, margin_mm: float, min_column_width_mm: float, max_columns: int|null, overflow: string, font_family: string, font_size_pt: float, repeat_header: bool}
 */
#[CoversClass(SettingsFactory::class)]
final class SettingsFactoryTest extends TestCase
{
    // ---------------------------------------------------------------- numbers

    #[Test]
    public function numbersMapsEveryConfigKeyToItsOwnProperty(): void
    {
        $numbers = SettingsFactory::numbers([
            'decimal_separator' => ',',
            'thousand_separator' => '.',
            'decimal_digits' => 4,
            'quantity_digits' => 6,
            'money_digits' => 5,
            'symbol_position' => 'before',
            'currency_symbols' => ['TRY' => '₺', 'USD' => '$'],
        ]);

        self::assertSame(',', $numbers->decimalSeparator);
        self::assertSame('.', $numbers->thousandSeparator);

        // The three digit fields carry three DIFFERENT values; if two of them were transposed,
        // this is where it would break.
        self::assertSame(4, $numbers->decimalDigits);
        self::assertSame(6, $numbers->quantityDigits);
        self::assertSame(5, $numbers->moneyDigits);

        self::assertSame(['TRY' => '₺', 'USD' => '$'], $numbers->currencySymbols);
        self::assertSame(SymbolPosition::Before, $numbers->symbolPosition);
    }

    /**
     * Catches the same transposition through BEHAVIOUR as well: the digit counts are read by
     * field type, so a value that landed in the wrong slot shows up here together with its type.
     */
    #[Test]
    public function digitsLandInTheSlotTheirFieldTypeReadsFrom(): void
    {
        $numbers = SettingsFactory::numbers(self::numberConfig([
            'decimal_digits' => 4,
            'quantity_digits' => 6,
            'money_digits' => 5,
        ]));

        self::assertSame(6, $numbers->digitsFor(FieldType::Quantity), 'The quantity digits must be quantity_digits.');
        self::assertSame(5, $numbers->digitsFor(FieldType::Money), 'The money digits must be money_digits.');
        self::assertSame(4, $numbers->digitsFor(FieldType::Decimal), 'The decimal digits must be decimal_digits.');
    }

    #[Test]
    public function separatorsAreNotTransposed(): void
    {
        // Because the separators have the same type, binding them the wrong way round is
        // silent too; we look at it with distinctive symbols (ones that would never get mixed
        // up in real life).
        $numbers = SettingsFactory::numbers(self::numberConfig([
            'decimal_separator' => '#',
            'thousand_separator' => '~',
        ]));

        self::assertSame('#', $numbers->decimalSeparator);
        self::assertSame('~', $numbers->thousandSeparator);
    }

    #[Test]
    #[DataProvider('symbolPositions')]
    public function symbolPositionStringBecomesTheEnumCase(string $configured, SymbolPosition $expected): void
    {
        // The container only carries plain arrays; the conversion to the enum happens here, once.
        $numbers = SettingsFactory::numbers(self::numberConfig(['symbol_position' => $configured]));

        self::assertSame($expected, $numbers->symbolPosition);
    }

    /**
     * @return iterable<string, array{string, SymbolPosition}>
     */
    public static function symbolPositions(): iterable
    {
        yield 'before' => ['before', SymbolPosition::Before];
        yield 'after' => ['after', SymbolPosition::After];
        yield 'none' => ['none', SymbolPosition::None];
    }

    #[Test]
    public function anUnknownSymbolPositionFailsLoudly(): void
    {
        // The only protection is the bundle's `enumNode()`; called outside the framework, the
        // error must not stay silent.
        $this->expectException(ValueError::class);

        SettingsFactory::numbers(self::numberConfig(['symbol_position' => 'left']));
    }

    #[Test]
    public function anEmptyCurrencySymbolMapSurvivesAsAnEmptyArray(): void
    {
        $numbers = SettingsFactory::numbers(self::numberConfig(['currency_symbols' => []]));

        self::assertSame([], $numbers->currencySymbols);
        // If no symbol is defined, the currency CODE stands in for the symbol.
        self::assertSame('EUR', $numbers->symbolFor('EUR'));
    }

    #[Test]
    public function currencySymbolsSurviveTheHandoverIntact(): void
    {
        $numbers = SettingsFactory::numbers(self::numberConfig([
            'currency_symbols' => ['TRY' => '₺', 'USD' => '$', 'EUR' => '€'],
            'symbol_position' => 'after',
        ]));

        self::assertSame('₺', $numbers->symbolFor('TRY'));
        self::assertSame('€', $numbers->symbolFor('EUR'));
        self::assertSame('1.234,50 ₺', $numbers->applySymbol('1.234,50', '₺'));
    }

    // ---------------------------------------------------------------- dates

    #[Test]
    public function datesMapsEveryConfigKeyToItsOwnProperty(): void
    {
        // All four fields are strings; let each one differ from the others so that a shift becomes visible.
        $dates = SettingsFactory::dates([
            'date_pattern' => 'Y-m-d',
            'datetime_pattern' => 'Y-m-d H:i:s',
            'excel_date_format' => 'yyyy-mm-dd',
            'excel_datetime_format' => 'yyyy-mm-dd hh:mm:ss',
        ]);

        self::assertSame('Y-m-d', $dates->datePattern);
        self::assertSame('Y-m-d H:i:s', $dates->dateTimePattern);
        self::assertSame('yyyy-mm-dd', $dates->excelDateFormat);
        self::assertSame('yyyy-mm-dd hh:mm:ss', $dates->excelDateTimeFormat);
    }

    #[Test]
    public function dateAndDateTimePatternsAreNotTransposed(): void
    {
        $dates = SettingsFactory::dates([
            'date_pattern' => 'd.m.Y',
            'datetime_pattern' => 'd.m.Y H:i',
            'excel_date_format' => 'dd.mm.yyyy',
            'excel_datetime_format' => 'dd.mm.yyyy hh:mm',
        ]);

        self::assertSame('d.m.Y', $dates->patternFor(FieldType::Date));
        self::assertSame('d.m.Y H:i', $dates->patternFor(FieldType::DateTime));
        self::assertSame('dd.mm.yyyy', $dates->excelFormatFor(FieldType::Date));
        self::assertSame('dd.mm.yyyy hh:mm', $dates->excelFormatFor(FieldType::DateTime));
    }

    // ---------------------------------------------------------------- pdf

    #[Test]
    public function pdfMapsEveryConfigKeyToItsOwnProperty(): void
    {
        $pdf = SettingsFactory::pdf(self::pdfConfig([
            'page_size' => 'a3',
            'orientation' => 'portrait',
            'margin_mm' => 7.5,
            'font_family' => 'Ionsis Sans',
            'font_size_pt' => 9.5,
            'repeat_header' => false,
        ]));

        self::assertSame(297.0, $pdf->page->widthMm());
        self::assertSame(420.0, $pdf->page->heightMm());
        self::assertSame(Orientation::Portrait, $pdf->page->orientation);
        self::assertSame('Ionsis Sans', $pdf->fontFamily);
        self::assertSame(9.5, $pdf->fontSizePt);
        self::assertFalse($pdf->repeatHeader);

        // All four edges must take the same value; if one is skipped, the output merely looks
        // "slightly off".
        self::assertSame(7.5, $pdf->page->marginTopMm);
        self::assertSame(7.5, $pdf->page->marginRightMm);
        self::assertSame(7.5, $pdf->page->marginBottomMm);
        self::assertSame(7.5, $pdf->page->marginLeftMm);
    }

    /**
     * The orientation is not merely STORED, it is APPLIED to the measurements.
     *
     * Setting the `Orientation` field correctly but forgetting to call `->landscape()` is a
     * silent bug: the `@page` rule would be written correctly, but the column budget is
     * computed from the portrait width, so the page would print in landscape while the columns
     * were split for portrait.
     */
    #[Test]
    public function theOrientationIsAppliedToTheMeasurementsNotJustStored(): void
    {
        $pdf = SettingsFactory::pdf(self::pdfConfig(['page_size' => 'a5', 'orientation' => 'landscape']));

        self::assertSame(210.0, $pdf->page->widthMm());
        self::assertSame(148.0, $pdf->page->heightMm());
    }

    /**
     * A yaml file that says `margin_mm: 10` lands here as `int(10)`.
     *
     * Symfony's `FloatNode` accepts a whole number but DOES NOT CONVERT it (see
     * `FloatNode::validateType`; it casts into a local variable, not into the node's value).
     * Unless the factory casts deliberately, an int leaks into `Page`'s float fields.
     */
    #[Test]
    public function aWholeNumberMarginArrivesAsAFloat(): void
    {
        $pdf = SettingsFactory::pdf(self::pdfConfig([
            'margin_mm' => 12,
            'min_column_width_mm' => 30,
            'font_size_pt' => 9,
        ]));

        self::assertSame(12.0, $pdf->page->marginTopMm);
        self::assertSame(9.0, $pdf->fontSizePt);
    }

    /**
     * The budget is built from three settings and all three really are read.
     *
     * On A4 landscape the usable width is 297 − 2×10 = 277 mm; with a 50 mm minimum column
     * width 5 columns fit, and `max_columns` pulls that down to 3. Had the value not been
     * carried, this would have stayed at 5.
     */
    #[Test]
    public function theColumnBudgetIsBuiltFromMinWidthAndMax(): void
    {
        $pdf = SettingsFactory::pdf(self::pdfConfig([
            'min_column_width_mm' => 50.0,
            'max_columns' => 3,
        ]));

        self::assertSame(3, $pdf->budget->capacity($pdf->page));
    }

    #[Test]
    public function aNullMaxColumnsMeansNoHardCap(): void
    {
        $pdf = SettingsFactory::pdf(self::pdfConfig([
            'min_column_width_mm' => 50.0,
            'max_columns' => null,
        ]));

        self::assertSame(5, $pdf->budget->capacity($pdf->page));
    }

    #[Test]
    #[DataProvider('overflowNames')]
    public function theOverflowNameBecomesTheEnumCase(string $configured, Overflow $expected): void
    {
        $pdf = SettingsFactory::pdf(self::pdfConfig(['overflow' => $configured, 'min_column_width_mm' => 50.0]));

        // `Overflow` is private INSIDE the budget; we read it from its behaviour: Shrink never
        // splits and does not recognise anchors either — however many columns there are, a
        // single group comes back.
        $columns = self::wideColumns(12);
        $groups = $pdf->budget->split($columns, $pdf->page);

        self::assertSame(
            Overflow::Shrink === $expected ? 1 : 3,
            count($groups),
            'The overflow strategy may not have reached the budget.',
        );
    }

    /** @return iterable<string, array{string, Overflow}> */
    public static function overflowNames(): iterable
    {
        // 12 columns / capacity 5: next_page_set splits into three groups, drop reduces it to a
        // single group (but 5 columns remain), shrink never splits. Because `drop` also returns
        // a single group, the distinguishing number is 3 ↔ 1.
        yield 'next_page_set' => ['next_page_set', Overflow::NextPageSet];
        yield 'shrink' => ['shrink', Overflow::Shrink];
    }

    #[Test]
    public function anUnknownPageSizeFailsLoudly(): void
    {
        // The only protection is the bundle's `enumNode()`; called outside the framework, the
        // error must not stay silent.
        $this->expectException(ValueError::class);

        SettingsFactory::pdf(self::pdfConfig(['page_size' => 'a2']));
    }

    #[Test]
    public function anUnknownOverflowNameFailsLoudly(): void
    {
        $this->expectException(ValueError::class);

        SettingsFactory::pdf(self::pdfConfig(['overflow' => 'wrap']));
    }

    // ---------------------------------------------------------------- root settings

    #[Test]
    public function settingsMapsEveryConfigKeyToItsOwnProperty(): void
    {
        $numbers = new NumberSettings(decimalDigits: 7);
        $dates = new DateSettings(datePattern: 'd/m/Y');

        $settings = SettingsFactory::settings([
            'default_locale' => 'tr',
            'empty_text' => '-',
            'bool_true_key' => 'app.bool.evet',
            'bool_false_key' => 'app.bool.hayir',
            'max_rows_per_sheet' => 25_000,
        ], $numbers, $dates);

        // The sub-settings are not copied, the very same instance is carried through.
        self::assertSame($numbers, $settings->numbers);
        self::assertSame($dates, $settings->dates);

        self::assertSame('tr', $settings->defaultLocale);
        self::assertSame('-', $settings->emptyText);
        // Had the two boolean keys been transposed, the export would have produced
        // 'Evet'/'Hayır' the wrong way round.
        self::assertSame('app.bool.evet', $settings->boolTrueKey);
        self::assertSame('app.bool.hayir', $settings->boolFalseKey);
        self::assertSame(25_000, $settings->maxRowsPerSheet);
    }

    #[Test]
    public function anEmptyEmptyTextIsKeptAsIsAndNotReplacedByADefault(): void
    {
        // An empty text is a meaningful choice: the cell is never created at all. The factory
        // must not touch it.
        $settings = SettingsFactory::settings([
            'default_locale' => 'en',
            'empty_text' => '',
            'bool_true_key' => 'tabula.bool.yes',
            'bool_false_key' => 'tabula.bool.no',
            'max_rows_per_sheet' => 1_000_000,
        ], new NumberSettings(), new DateSettings());

        self::assertSame('', $settings->emptyText);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A complete number configuration; the keys the test cares about are written over it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return NumberConfig
     */
    private static function numberConfig(array $overrides = []): array
    {
        /** @var NumberConfig $config */
        $config = array_merge([
            'decimal_separator' => ',',
            'thousand_separator' => '.',
            'decimal_digits' => 2,
            'quantity_digits' => 3,
            'money_digits' => 2,
            'symbol_position' => 'after',
            'currency_symbols' => [],
        ], $overrides);

        return $config;
    }

    /**
     * A complete PDF configuration; identical to the defaults the bundle's tree produces.
     *
     * @param array<string, mixed> $overrides
     *
     * @return PdfConfig
     */
    private static function pdfConfig(array $overrides = []): array
    {
        /** @var PdfConfig $config */
        $config = array_merge([
            'page_size' => 'a4',
            'orientation' => 'landscape',
            'margin_mm' => 10.0,
            'min_column_width_mm' => 22.0,
            'max_columns' => null,
            'overflow' => 'next_page_set',
            'font_family' => 'DejaVu Sans',
            'font_size_pt' => 8.0,
            'repeat_header' => true,
        ], $overrides);

        return $config;
    }

    /**
     * Enough columns, with no width given, to put the budget under strain.
     *
     * @return list<Column>
     */
    private static function wideColumns(int $count): array
    {
        $columns = [];

        for ($i = 0; $i < $count; ++$i) {
            $columns[] = new Column(
                key: 'k'.$i,
                label: 'Column '.$i,
                type: FieldType::String,
                align: Align::Left,
                width: null,
                required: false,
                priority: Priority::Normal,
            );
        }

        return $columns;
    }
}
