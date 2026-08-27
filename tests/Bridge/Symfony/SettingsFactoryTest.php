<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Bridge\Symfony;

use Balin\Tabula\Bridge\Symfony\SettingsFactory;
use Balin\Tabula\Schema\FieldType;
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
}
