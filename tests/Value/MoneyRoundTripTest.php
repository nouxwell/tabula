<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Value;

use Balin\Tabula\Format;
use Balin\Tabula\Port\PassthroughTranslator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\SymbolPosition;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\Formatter\MoneyFormatter;
use Balin\Tabula\Value\Formatter\NumberFormatter;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\Parser\MoneyParser;
use Balin\Tabula\Value\Parser\NumberParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Biçimlendirici ile ayrıştırıcı GERÇEKTEN birbirinin tersi mi?
 *
 * Bu dosya bir hatadan doğdu: `Field::money()` Türkçe ayarlarla 1234 değerini "1.234 ₺" diye
 * yazıyor, ayrıştırıcı ise simgeyi soyduktan sonra "1.234" metnini KANONİK okuyup 1.234
 * döndürüyordu. Yani kendi dışa aktarmamız kendi içe aktarmamızı bozuyordu — 1000 katlık
 * sapma, üstelik geçerli bir float üretildiği için hiçbir `RowError` çıkmıyor, hiçbir yerde
 * uyarı görünmüyordu. Sadece rakam yanlıştı.
 *
 * Sinsi olan yanı, yalnızca ONDALIK KISMI GÖRÜNMEYEN ve ayıraçtan sonra tam üç hane olan
 * tutarları vurmasıydı: "1.234,56 ₺" doğru okunuyordu, çünkü ",56" belirsizliği çözüyordu.
 */
final class MoneyRoundTripTest extends TestCase
{
    private function settings(): TabulaSettings
    {
        return new TabulaSettings(
            numbers: new NumberSettings(
                decimalSeparator: ',',
                thousandSeparator: '.',
                currencySymbols: ['TRY' => '₺'],
                symbolPosition: SymbolPosition::After,
            ),
        );
    }

    private function formatContext(): FormatContext
    {
        return new FormatContext('tr', new PassthroughTranslator(), $this->settings(), Format::Csv);
    }

    private function parseContext(): ParseContext
    {
        return new ParseContext('tr', new PassthroughTranslator(), $this->settings());
    }

    /** @return iterable<string, array{float, int}> */
    public static function amounts(): iterable
    {
        // Kritik olanlar 0 ondalıklı ve ayıraçtan sonra TAM ÜÇ hane olanlar.
        yield 'binlik, ondalıksız' => [1234.0, 0];
        yield 'on binlik, ondalıksız' => [12345.0, 0];
        yield 'yüz binlik, ondalıksız' => [123456.0, 0];
        yield 'yuvarlanan kesir' => [1234.56, 0];
        yield 'iki ondalıklı' => [1234.56, 2];
        yield 'negatif' => [-1234.0, 0];
        yield 'binliksiz küçük' => [12.5, 2];
        yield 'sıfır' => [0.0, 2];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function aMoneyValueSurvivesTheRoundTrip(float $amount, int $decimals): void
    {
        $field = Field::money('balance')->currency('TRY')->decimals($decimals);

        $text = (new MoneyFormatter())->format($amount, $field, [], $this->formatContext())->text;
        $back = (new MoneyParser())->parse($text, $field, $this->parseContext());

        // Yazılan metin neyse, geri okunan da o olmalı — biçimlendiricinin yuvarladığı
        // kadarıyla (0 ondalıkta 1234,56 "1.235" yazılır ve 1235 geri gelir).
        $expected = round($amount, $decimals);

        self::assertEqualsWithDelta(
            $expected,
            $back,
            0.0001,
            sprintf('"%s" metni %s olarak geri okunmalıydı.', $text, var_export($expected, true)),
        );
    }

    #[Test]
    public function aQuantitySurvivesTheRoundTripToo(): void
    {
        // Aynı belirsizlik para dışı sayılarda da var: 1234 miktarı "1.234" olarak yazılır.
        $field = Field::quantity('stock')->decimals(0);

        $text = (new NumberFormatter())
            ->format(1234.0, $field, [], $this->formatContext())->text;

        self::assertSame('1.234', $text);
        self::assertEqualsWithDelta(1234.0, (new NumberParser())->parse($text, $field, $this->parseContext()), 0.0001);
    }

    #[Test]
    public function theExportSideStillReadsCanonicalDatabaseStringsAsDecimals(): void
    {
        // Düzeltme yalnızca İÇE AKTARMA yönünü değiştirmeli. Dışa aktarmada değer çoğunlukla
        // veritabanı skalerinden gelir ve orada tek başına nokta ondalıktır.
        $numbers = $this->settings()->numbers;

        self::assertEqualsWithDelta(1.234, NumberFormatter::parse('1.234', $numbers), 0.0001);
        self::assertEqualsWithDelta(1234.0, NumberFormatter::parse('1.234', $numbers, preferLocalized: true), 0.0001);
    }

    #[Test]
    public function canonicalDatabaseStringsStillParseCorrectlyOnImport(): void
    {
        // Yerel okuma açıkken bile kanonik dizeler doğru okunmalı: "1234.5600" ayıraçtan
        // sonra DÖRT hane taşıdığı için binlik gruplaması sayılmaz.
        $numbers = $this->settings()->numbers;

        self::assertEqualsWithDelta(1234.56, NumberFormatter::parse('1234.5600', $numbers, preferLocalized: true), 0.0001);
        self::assertEqualsWithDelta(-0.5, NumberFormatter::parse('-0.5', $numbers, preferLocalized: true), 0.0001);
        self::assertEqualsWithDelta(1234.0, NumberFormatter::parse('1234', $numbers, preferLocalized: true), 0.0001);
    }
}
