<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Value;

use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Value\Formatter\NumberFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Number parsing tests.
 *
 * DQL scalar projections most often return numbers as STRINGS, and legacy data contains
 * localised values, values contaminated with a currency symbol, and values in accounting
 * notation. The real issue here is the sign: a debit silently turning into a credit is the
 * most expensive mistake this library could make.
 */
final class NumberParsingTest extends TestCase
{
    private function turkish(): NumberSettings
    {
        return new NumberSettings(decimalSeparator: ',', thousandSeparator: '.');
    }

    /** @return iterable<string, array{string, float}> */
    public static function negativeNotations(): iterable
    {
        yield 'leading minus' => ['-1.234,56', -1234.56];
        yield 'accounting parentheses' => ['(1.234,56)', -1234.56];
        yield 'trailing minus (SAP/ERP)' => ['1.234,56-', -1234.56];
        yield 'symbol first, minus in front of the number' => ['₺ -1.234,56', -1234.56];
        yield 'parentheses and symbol' => ['(1.234,56 ₺)', -1234.56];
    }

    #[Test]
    #[DataProvider('negativeNotations')]
    public function itKeepsTheSignOfEveryNegativeNotation(string $raw, float $expected): void
    {
        self::assertEqualsWithDelta(
            $expected,
            NumberFormatter::parse($raw, $this->turkish()),
            0.0001,
            sprintf('"%s" lost its sign — in accounting that is silent data corruption.', $raw),
        );
    }

    #[Test]
    public function itDoesNotInventANegativeSignForOrdinaryValues(): void
    {
        self::assertEqualsWithDelta(1234.56, NumberFormatter::parse('1.234,56', $this->turkish()), 0.0001);
        self::assertEqualsWithDelta(1234.56, NumberFormatter::parse('1.234,56 ₺', $this->turkish()), 0.0001);
        self::assertEqualsWithDelta(0.5, NumberFormatter::parse('0,5', $this->turkish()), 0.0001);
    }

    #[Test]
    public function canonicalDatabaseStringsAreNotReinterpreted(): void
    {
        // Doctrine/PDO returns "1234.5600"; the dot here is ALWAYS the decimal point.
        self::assertEqualsWithDelta(1234.56, NumberFormatter::parse('1234.5600', $this->turkish()), 0.0001);
        self::assertEqualsWithDelta(-0.5, NumberFormatter::parse('-0.5', $this->turkish()), 0.0001);
    }

    #[Test]
    public function junkBecomesNullInsteadOfKillingTheExport(): void
    {
        self::assertNull(NumberFormatter::parse('N/A', $this->turkish()));
        self::assertNull(NumberFormatter::parse('', $this->turkish()));
        self::assertNull(NumberFormatter::parse(null, $this->turkish()));
    }
}
