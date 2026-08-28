<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Value;

use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Port\PassthroughTranslator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Settings\NumberSettings;
use Nouxwell\Tabula\Settings\SymbolPosition;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Value\FormatContext;
use Nouxwell\Tabula\Value\Formatter\MoneyFormatter;
use Nouxwell\Tabula\Value\Formatter\NumberFormatter;
use Nouxwell\Tabula\Value\ParseContext;
use Nouxwell\Tabula\Value\Parser\MoneyParser;
use Nouxwell\Tabula\Value\Parser\NumberParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Are the formatter and the parser REALLY the inverse of each other?
 *
 * This file was born out of a bug: with Turkish settings `Field::money()` writes the value
 * 1234 as "1.234 ₺", while the parser, after stripping the symbol, read the text "1.234" as
 * CANONICAL and returned 1.234. In other words, our own export broke our own import — a
 * factor-of-1000 deviation, and because a perfectly valid float was produced, no `RowError`
 * was raised and no warning appeared anywhere. Only the number was wrong.
 *
 * The insidious part was that it hit only those amounts whose DECIMAL PART IS NOT VISIBLE and
 * that have exactly three digits after the separator: "1.234,56 ₺" was read correctly,
 * because the ",56" resolved the ambiguity.
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
        // The critical ones are those with 0 decimals and EXACTLY THREE digits after the separator.
        yield 'thousands, no decimals' => [1234.0, 0];
        yield 'ten thousands, no decimals' => [12345.0, 0];
        yield 'hundred thousands, no decimals' => [123456.0, 0];
        yield 'fraction that gets rounded away' => [1234.56, 0];
        yield 'two decimals' => [1234.56, 2];
        yield 'negative' => [-1234.0, 0];
        yield 'small, without a thousands group' => [12.5, 2];
        yield 'zero' => [0.0, 2];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function aMoneyValueSurvivesTheRoundTrip(float $amount, int $decimals): void
    {
        $field = Field::money('balance')->currency('TRY')->decimals($decimals);

        $text = (new MoneyFormatter())->format($amount, $field, [], $this->formatContext())->text;
        $back = (new MoneyParser())->parse($text, $field, $this->parseContext());

        // Whatever text was written is what must be read back — as far as the formatter
        // rounded it (with 0 decimals, 1234.56 is written "1.235" and 1235 comes back).
        $expected = round($amount, $decimals);

        self::assertEqualsWithDelta(
            $expected,
            $back,
            0.0001,
            sprintf('The text "%s" should have been read back as %s.', $text, var_export($expected, true)),
        );
    }

    #[Test]
    public function aQuantitySurvivesTheRoundTripToo(): void
    {
        // The same ambiguity exists for non-money numbers: a quantity of 1234 is written as "1.234".
        $field = Field::quantity('stock')->decimals(0);

        $text = (new NumberFormatter())
            ->format(1234.0, $field, [], $this->formatContext())->text;

        self::assertSame('1.234', $text);
        self::assertEqualsWithDelta(1234.0, (new NumberParser())->parse($text, $field, $this->parseContext()), 0.0001);
    }

    #[Test]
    public function theExportSideStillReadsCanonicalDatabaseStringsAsDecimals(): void
    {
        // The fix must change only the IMPORT direction. On export the value mostly comes from
        // a database scalar, and there a lone dot is a decimal point.
        $numbers = $this->settings()->numbers;

        self::assertEqualsWithDelta(1.234, NumberFormatter::parse('1.234', $numbers), 0.0001);
        self::assertEqualsWithDelta(1234.0, NumberFormatter::parse('1.234', $numbers, preferLocalized: true), 0.0001);
    }

    #[Test]
    public function canonicalDatabaseStringsStillParseCorrectlyOnImport(): void
    {
        // Even with localised reading switched on, canonical strings must still be read
        // correctly: "1234.5600" carries FOUR digits after the separator, so it does not
        // count as thousands grouping.
        $numbers = $this->settings()->numbers;

        self::assertEqualsWithDelta(1234.56, NumberFormatter::parse('1234.5600', $numbers, preferLocalized: true), 0.0001);
        self::assertEqualsWithDelta(-0.5, NumberFormatter::parse('-0.5', $numbers, preferLocalized: true), 0.0001);
        self::assertEqualsWithDelta(1234.0, NumberFormatter::parse('1234', $numbers, preferLocalized: true), 0.0001);
    }
}
