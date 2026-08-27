<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Value;

use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Value\Formatter\NumberFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sayı ayrıştırma testleri.
 *
 * DQL skaler projeksiyonları sayıları çoğu zaman DİZE olarak döndürür ve eski veride
 * yerelleştirilmiş, simge bulaşmış, muhasebe gösterimli değerler bulunur. Buradaki asıl
 * mesele işaret: bir borcun sessizce alacağa dönmesi kütüphanenin yapabileceği en pahalı hata.
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
        yield 'baştaki eksi' => ['-1.234,56', -1234.56];
        yield 'muhasebe parantezi' => ['(1.234,56)', -1234.56];
        yield 'sondaki eksi (SAP/ERP)' => ['1.234,56-', -1234.56];
        yield 'simge önde, eksi sayının önünde' => ['₺ -1.234,56', -1234.56];
        yield 'parantez ve simge' => ['(1.234,56 ₺)', -1234.56];
    }

    #[Test]
    #[DataProvider('negativeNotations')]
    public function itKeepsTheSignOfEveryNegativeNotation(string $raw, float $expected): void
    {
        self::assertEqualsWithDelta(
            $expected,
            NumberFormatter::parse($raw, $this->turkish()),
            0.0001,
            sprintf('"%s" işareti kaybetti — muhasebede bu sessiz bir veri bozulmasıdır.', $raw),
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
        // Doctrine/PDO "1234.5600" döndürür; buradaki nokta HER ZAMAN ondalıktır.
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
