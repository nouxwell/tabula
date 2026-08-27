<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Bridge\Symfony;

use Balin\Tabula\Bridge\Symfony\SettingsFactory;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Export\Page\Orientation;
use Balin\Tabula\Export\Page\Overflow;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Schema\Priority;
use Balin\Tabula\Settings\DateSettings;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\SymbolPosition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * Yapılandırma dizisi → ayar nesnesi eşlemesi.
 *
 * Fabrikanın içinde mantık yok; tek riski YANLIŞ ARGÜMANA bağlamaktır. `quantity_digits`
 * ile `money_digits` aynı tipte (int) olduğu için bir yer değiştirme ne PHP ne de PHPStan
 * tarafından yakalanır — hata ancak dışa aktarılan dosyada, miktar kolonu iki basamak
 * gösterdiğinde fark edilir. Bu yüzden her testte değerler BİLEREK birbirinden farklı ve
 * varsayılanlardan uzak seçilmiştir: yer değiştirme testi kırmızıya döndürür.
 *
 * @phpstan-type NumberConfig array{decimal_separator: string, thousand_separator: string, decimal_digits: int, quantity_digits: int, money_digits: int, symbol_position: string, currency_symbols: array<string, string>}
 * @phpstan-type PdfConfig array{page_size: string, orientation: string, margin_mm: float, min_column_width_mm: float, max_columns: int|null, overflow: string, font_family: string, font_size_pt: float, repeat_header: bool}
 */
#[CoversClass(SettingsFactory::class)]
final class SettingsFactoryTest extends TestCase
{
    // ---------------------------------------------------------------- sayılar

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

        // Üç basamak alanı üç FARKLI değer taşıyor; ikisi yer değiştirse burası kırılır.
        self::assertSame(4, $numbers->decimalDigits);
        self::assertSame(6, $numbers->quantityDigits);
        self::assertSame(5, $numbers->moneyDigits);

        self::assertSame(['TRY' => '₺', 'USD' => '$'], $numbers->currencySymbols);
        self::assertSame(SymbolPosition::Before, $numbers->symbolPosition);
    }

    /**
     * Aynı yer değiştirmeyi bir de DAVRANIŞ üzerinden yakalar: basamak sayıları alan tipine
     * göre okunur, dolayısıyla yanlış slota düşen bir değer burada tipiyle birlikte görünür.
     */
    #[Test]
    public function digitsLandInTheSlotTheirFieldTypeReadsFrom(): void
    {
        $numbers = SettingsFactory::numbers(self::numberConfig([
            'decimal_digits' => 4,
            'quantity_digits' => 6,
            'money_digits' => 5,
        ]));

        self::assertSame(6, $numbers->digitsFor(FieldType::Quantity), 'Miktar basamağı quantity_digits olmalı.');
        self::assertSame(5, $numbers->digitsFor(FieldType::Money), 'Para basamağı money_digits olmalı.');
        self::assertSame(4, $numbers->digitsFor(FieldType::Decimal), 'Ondalık basamağı decimal_digits olmalı.');
    }

    #[Test]
    public function separatorsAreNotTransposed(): void
    {
        // Ayraçlar aynı tipte olduğu için ters bağlanmaları da sessizdir; ayırt edici
        // (ve gerçek hayatta asla karışmayacak) simgelerle bakıyoruz.
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
        // Kapsayıcı yalnız düz dizi taşır; enum'a dönüşüm burada, bir kez yapılır.
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
        // Tek koruma bundle'ın `enumNode()`'u; çerçevesiz çağrıda hata sessiz kalmamalı.
        $this->expectException(ValueError::class);

        SettingsFactory::numbers(self::numberConfig(['symbol_position' => 'left']));
    }

    #[Test]
    public function anEmptyCurrencySymbolMapSurvivesAsAnEmptyArray(): void
    {
        $numbers = SettingsFactory::numbers(self::numberConfig(['currency_symbols' => []]));

        self::assertSame([], $numbers->currencySymbols);
        // Simge tanımlı değilse para birimi KODU simge yerine geçer.
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

    // ---------------------------------------------------------------- tarihler

    #[Test]
    public function datesMapsEveryConfigKeyToItsOwnProperty(): void
    {
        // Dört alan da string; hepsi birbirinden farklı olsun ki bir kayma görünür olsun.
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

        // Dört kenar da aynı değeri almalı; biri atlanırsa çıktı görünürde "biraz kaymış" olur.
        self::assertSame(7.5, $pdf->page->marginTopMm);
        self::assertSame(7.5, $pdf->page->marginRightMm);
        self::assertSame(7.5, $pdf->page->marginBottomMm);
        self::assertSame(7.5, $pdf->page->marginLeftMm);
    }

    /**
     * Yön SAKLANMAKLA kalmaz, ölçüye UYGULANIR.
     *
     * `Orientation` alanını doğru kurup `->landscape()` çağırmayı unutmak sessiz bir hatadır:
     * `@page` kuralı doğru yazılırdı ama kolon bütçesi dikey enden hesaplanır, yani sayfa
     * yatay basılırken kolonlar dikeye göre bölünürdü.
     */
    #[Test]
    public function theOrientationIsAppliedToTheMeasurementsNotJustStored(): void
    {
        $pdf = SettingsFactory::pdf(self::pdfConfig(['page_size' => 'a5', 'orientation' => 'landscape']));

        self::assertSame(210.0, $pdf->page->widthMm());
        self::assertSame(148.0, $pdf->page->heightMm());
    }

    /**
     * `margin_mm: 10` yazan bir yaml dosyası buraya `int(10)` olarak düşer.
     *
     * Symfony'nin `FloatNode`u tam sayıyı kabul eder ama ÇEVİRMEZ (bkz. `FloatNode::validateType`;
     * kastı yerel değişkene yapar, düğümün değerine değil). Fabrika kasti atmazsa `Page`in
     * float alanlarına int sızar.
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
     * Bütçe üç ayardan kurulur ve üçü de gerçekten okunur.
     *
     * A4 yatayda kullanılabilir en 297 − 2×10 = 277 mm; 50 mm asgari kolonla 5 kolon sığar,
     * `max_columns` onu 3'e çeker. Değer taşınmasaydı burası 5 kalırdı.
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

        // `Overflow` bütçenin İÇİNDE özel; davranışından okuyoruz: Shrink hiç bölmez, çapa
        // da tanımaz — kaç kolon olursa olsun tek grup döner.
        $columns = self::wideColumns(12);
        $groups = $pdf->budget->split($columns, $pdf->page);

        self::assertSame(
            Overflow::Shrink === $expected ? 1 : 3,
            count($groups),
            'Taşma stratejisi bütçeye ulaşmamış olabilir.',
        );
    }

    /** @return iterable<string, array{string, Overflow}> */
    public static function overflowNames(): iterable
    {
        // 12 kolon / 5 kapasite: next_page_set üç gruba böler, drop tek gruba indirir
        // (ama 5 kolon kalır), shrink hiç bölmez. `drop` da tek grup döndürdüğü için
        // ayırt edici sayı 3 ↔ 1'dir.
        yield 'next_page_set' => ['next_page_set', Overflow::NextPageSet];
        yield 'shrink' => ['shrink', Overflow::Shrink];
    }

    #[Test]
    public function anUnknownPageSizeFailsLoudly(): void
    {
        // Tek koruma bundle'ın `enumNode()`'u; çerçevesiz çağrıda hata sessiz kalmamalı.
        $this->expectException(ValueError::class);

        SettingsFactory::pdf(self::pdfConfig(['page_size' => 'a2']));
    }

    #[Test]
    public function anUnknownOverflowNameFailsLoudly(): void
    {
        $this->expectException(ValueError::class);

        SettingsFactory::pdf(self::pdfConfig(['overflow' => 'wrap']));
    }

    // ---------------------------------------------------------------- kök ayarlar

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

        // Alt ayarlar kopyalanmaz, aynı örnek taşınır.
        self::assertSame($numbers, $settings->numbers);
        self::assertSame($dates, $settings->dates);

        self::assertSame('tr', $settings->defaultLocale);
        self::assertSame('-', $settings->emptyText);
        // İki boole anahtarı yer değiştirseydi dışa aktarmada 'Evet'/'Hayır' ters çıkardı.
        self::assertSame('app.bool.evet', $settings->boolTrueKey);
        self::assertSame('app.bool.hayir', $settings->boolFalseKey);
        self::assertSame(25_000, $settings->maxRowsPerSheet);
    }

    #[Test]
    public function anEmptyEmptyTextIsKeptAsIsAndNotReplacedByADefault(): void
    {
        // Boş metin anlamlı bir seçimdir: hücre hiç yaratılmaz. Fabrika buna dokunmamalı.
        $settings = SettingsFactory::settings([
            'default_locale' => 'en',
            'empty_text' => '',
            'bool_true_key' => 'tabula.bool.yes',
            'bool_false_key' => 'tabula.bool.no',
            'max_rows_per_sheet' => 1_000_000,
        ], new NumberSettings(), new DateSettings());

        self::assertSame('', $settings->emptyText);
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * Tam bir sayı yapılandırması; testin ilgilendiği anahtarlar üzerine yazılır.
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
     * Tam bir PDF yapılandırması; bundle'ın ağacının ürettiği varsayılanlarla aynı.
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
     * Bütçeyi zorlayacak kadar çok, genişliği verilmemiş kolon.
     *
     * @return list<Column>
     */
    private static function wideColumns(int $count): array
    {
        $columns = [];

        for ($i = 0; $i < $count; ++$i) {
            $columns[] = new Column(
                key: 'k'.$i,
                label: 'Kolon '.$i,
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
