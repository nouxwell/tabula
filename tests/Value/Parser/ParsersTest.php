<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Value\Parser;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Format;
use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\SymbolPosition;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Tests\Fixture\Status;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\FormatterRegistry;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ParserRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Parser tests.
 *
 * This file has a SINGLE theme: PARSERS ARE STRICT EXACTLY WHERE FORMATTERS ARE LENIENT.
 * `NumberFormatter` writes an unreadable cell out blank and moves on, because a 40,000-row
 * report must not die over a single broken cell. `NumberParser` throws an exception on that
 * same cell, because there the value is about to be written INTO THE DATABASE, and "skip it
 * blank" means writing wrong data.
 *
 * This asymmetry is the library's most easily broken contract: since both sides share the
 * very same `NumberFormatter::parse()` call, carrying one side's leniency over to the other
 * is a one-line change. The tests below put the difference side by side, EXPLICITLY.
 */
final class ParsersTest extends TestCase
{
    // ---------------------------------------------------------------- helpers

    private function translator(string $yes = 'Evet', string $no = 'Hayır'): Translator
    {
        return new ArrayTranslator([
            'tr' => [
                'tabula.bool.yes' => $yes,
                'tabula.bool.no' => $no,
                'status.open' => 'Açık',
                'status.closed' => 'Kapalı',
                'opt.b2b' => 'Kurumsal',
                'opt.b2c' => 'Bireysel',
            ],
        ]);
    }

    private function settings(?NumberSettings $numbers = null): TabulaSettings
    {
        return new TabulaSettings(
            numbers: $numbers ?? new NumberSettings(
                currencySymbols: ['TRY' => '₺'],
                symbolPosition: SymbolPosition::After,
            ),
        );
    }

    private function context(?TabulaSettings $settings = null, ?Translator $translator = null): ParseContext
    {
        return new ParseContext(
            locale: 'tr',
            translator: $translator ?? $this->translator(),
            settings: $settings ?? $this->settings(),
        );
    }

    /** The formatter side's context, built from the same settings — for measuring the asymmetry. */
    private function formatContext(?TabulaSettings $settings = null): FormatContext
    {
        return new FormatContext(
            locale: 'tr',
            translator: $this->translator(),
            settings: $settings ?? $this->settings(),
            format: Format::Xlsx,
        );
    }

    private function parse(Field $field, mixed $raw, ?ParseContext $context = null): mixed
    {
        $context ??= $this->context();

        return ParserRegistry::default()->for($field)->parse($raw, $field, $context);
    }

    /** A wrapper that narrows the type in the date tests; `format()` cannot be called on `mixed`. */
    private function parseDate(Field $field, mixed $raw, ?ParseContext $context = null): DateTimeImmutable
    {
        $value = $this->parse($field, $raw, $context);

        if (!$value instanceof DateTimeImmutable) {
            self::fail(sprintf('The parser should have returned a DateTimeImmutable, got: %s', get_debug_type($value)));
        }

        return $value;
    }

    private function parseString(Field $field, mixed $raw): string
    {
        $value = $this->parse($field, $raw);

        if (!is_string($value)) {
            self::fail(sprintf('The parser should have returned a string, got: %s', get_debug_type($value)));
        }

        return $value;
    }

    // ---------------------------------------------------------------- numbers

    /** @return iterable<string, array{string, float}> */
    public static function signedNotations(): iterable
    {
        yield 'localised' => ['1.234,56', 1234.56];
        yield 'canonical database string' => ['1234.5600', 1234.56];
        yield 'leading minus' => ['-1.234,56', -1234.56];
        yield 'accounting parentheses' => ['(1.234,56)', -1234.56];
        yield 'trailing minus (SAP/ERP)' => ['1.234,56-', -1234.56];
    }

    #[Test]
    #[DataProvider('signedNotations')]
    public function everyNotationKeepsItsSign(string $raw, float $expected): void
    {
        self::assertEqualsWithDelta(
            $expected,
            $this->parse(Field::decimal('amount'), $raw),
            0.0001,
            sprintf('"%s" was read wrongly; in accounting, a lost sign is silent data corruption.', $raw),
        );
    }

    /**
     * ★ The reason this file exists: the SAME input, two different behaviours in the two
     * directions.
     *
     * The formatter turns "N/A" into an empty cell and keeps the report standing; the parser
     * stops dead on the same value. Both are correct, because their results go to different
     * places.
     */
    #[Test]
    public function junkIsAnEmptyCellWhenExportingButAnErrorWhenImporting(): void
    {
        $field = Field::decimal('amount');

        $cell = FormatterRegistry::default()
            ->for(FieldType::Decimal)
            ->format('N/A', $field, null, $this->formatContext());

        self::assertTrue(
            $cell->isEmpty(),
            'On export an unreadable cell is written blank: one broken row must not bring down a 40,000-row report.',
        );

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('"N/A" could not be read as a number.');

        $this->parse($field, 'N/A');
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonIntegerValues(): iterable
    {
        yield 'localised decimal' => ['12,5'];
        yield 'canonical decimal' => ['12.5'];
        yield 'real float' => [12.5];
    }

    #[Test]
    #[DataProvider('nonIntegerValues')]
    public function anIntegerFieldRefusesADecimalInsteadOfFlooringIt(mixed $raw): void
    {
        // A 12,5 written into a stock-count column is a typo; rounding it to 12 would erase
        // half a box from the records. The user MUST SEE the error.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('is not a whole number');

        $this->parse(Field::integer('count'), $raw);
    }

    #[Test]
    public function numericTypesKeepTheirTypeContractAcrossRows(): void
    {
        // Integer is always int, Decimal/Quantity always float: the same column returning an
        // int on one row and a float on the next produces a silent type surprise in the
        // entity setter.
        self::assertSame(12, $this->parse(Field::integer('count'), '12'));
        self::assertSame(12, $this->parse(Field::integer('count'), 12.0));
        // If the same separator occurs more than once it is a grouping separator — no ambiguity.
        self::assertSame(1234567, $this->parse(Field::integer('count'), '1.234.567'));

        self::assertIsFloat($this->parse(Field::decimal('ratio'), '12'));
        self::assertIsFloat($this->parse(Field::quantity('qty'), 3));
        self::assertIsFloat($this->parse(Field::money('balance'), 3));
    }

    // ---------------------------------------------------------------- money

    #[Test]
    public function moneyStripsTheCurrencySymbol(): void
    {
        $field = Field::money('balance');

        self::assertEqualsWithDelta(1234.56, $this->parse($field, '1.234,56 ₺'), 0.0001);
        self::assertEqualsWithDelta(1234.56, $this->parse($field, '₺ 1.234,56'), 0.0001);
        self::assertEqualsWithDelta(-1234.56, $this->parse($field, '(1.234,56 ₺)'), 0.0001);
    }

    /**
     * Why the symbol is stripped by hand.
     *
     * A symbol that carries a dot (`S/.`) leaves "1.234,56." behind after cleaning; the
     * right-most dot then no longer counts as a decimal point and the amount is read A
     * HUNDRED TIMES too large.
     */
    #[Test]
    public function aSymbolContainingADotDoesNotMultiplyTheAmountByAHundred(): void
    {
        $settings = $this->settings(new NumberSettings(currencySymbols: ['PEN' => 'S/.']));
        $field = Field::money('balance')->currency('PEN');

        self::assertEqualsWithDelta(
            1234.56,
            $this->parse($field, '1.234,56 S/.', $this->context($settings)),
            0.0001,
            'Had the symbol not been stripped, the trailing dot would not count as a decimal point and the amount would be read as 123456.',
        );
    }

    // ---------------------------------------------------------------- booleans

    /** @return iterable<string, array{mixed, bool}> */
    public static function booleanNotations(): iterable
    {
        yield 'the yes the template writes' => ['Evet', true];
        yield 'the no the template writes' => ['Hayır', false];
        yield 'lower case' => ['evet', true];
        yield 'hayir without the dotted i' => ['hayir', false];
        yield 'one' => ['1', true];
        yield 'zero' => ['0', false];
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'tinyint(1)' => [1, true];
        yield 'real boolean' => [false, false];
        yield 'numeric Excel cell' => [0.0, false];
        yield 'in need of trimming' => ['  Evet  ', true];
    }

    #[Test]
    #[DataProvider('booleanNotations')]
    public function boolAcceptsEveryUsualNotation(mixed $raw, bool $expected): void
    {
        self::assertSame($expected, $this->parse(Field::bool('isActive'), $raw));
    }

    /**
     * The Yes/No pair is read FROM THE CATALOGUE, not from a hard-coded list.
     *
     * The text written into the template's drop-down list comes from those same two keys
     * (`TabulaSettings::$boolTrueKey/$boolFalseKey`), so the round trip closes. In the system
     * this replaces, one translation family produced the cell text while another
     * produced the validation list; the value it had written itself was not in its own list.
     */
    #[Test]
    public function boolReadsTheYesNoPairFromTheCatalogue(): void
    {
        $context = $this->context(translator: $this->translator('Aktif', 'Pasif'));

        self::assertTrue($this->parse(Field::bool('isActive'), 'Aktif', $context));
        self::assertFalse($this->parse(Field::bool('isActive'), 'Pasif', $context));
    }

    #[Test]
    public function boolGarbageThrowsAndTheMessageNamesTheAcceptedForms(): void
    {
        $field = Field::bool('isActive');
        $context = $this->context(translator: $this->translator('Aktif', 'Pasif'));

        // The asymmetry again: the formatter turns a value it does not recognise into an
        // empty cell and moves on.
        $cell = FormatterRegistry::default()
            ->for(FieldType::Bool)
            ->format('belki', $field, null, $this->formatContext());

        self::assertTrue($cell->isEmpty());

        try {
            $this->parse($field, 'belki', $context);

            self::fail('An unrecognised boolean value should have thrown; a made-up "no" cannot be written to the database.');
        } catch (ParseException $exception) {
            $message = $exception->getMessage();

            // The user can only fix the error once they can see WHAT THEY ARE ALLOWED TO WRITE.
            foreach (['Aktif', 'Pasif', 'Evet', 'Hayır', '1', '0', 'true', 'false'] as $accepted) {
                self::assertStringContainsString(
                    $accepted,
                    $message,
                    sprintf('The error message must list the accepted form "%s".', $accepted),
                );
            }
        }
    }

    // ---------------------------------------------------------------- dates

    #[Test]
    public function aReadyMadeObjectPassesThroughAndADateFieldZeroesTheTime(): void
    {
        $date = $this->parseDate(Field::date('createdAt'), new DateTimeImmutable('2024-01-05 13:45:00'));

        // No leftover time of day: the same date gets the same value on every row, so a
        // BETWEEN does not miss the end of the day.
        self::assertSame('2024-01-05 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function aDateTimeFieldKeepsTheTimeOfDay(): void
    {
        $date = $this->parseDate(Field::dateTime('createdAt'), '05.01.2024 09:30');

        self::assertSame('2024-01-05 09:30:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * ★ The serial-number conversion is hand-written in BOTH DIRECTIONS (neither
     * `DateFormatter` nor `DateParser` depends on PhpSpreadsheet). Assuming that the two
     * formulas are the inverse of each other is not enough; here it is measured.
     */
    #[Test]
    public function theExcelSerialIsTheExactInverseOfTheFormattersForwardConversion(): void
    {
        $field = Field::date('createdAt');

        $cell = FormatterRegistry::default()
            ->for(FieldType::Date)
            ->format(new DateTimeImmutable('2024-01-05 00:00:00'), $field, null, $this->formatContext());

        self::assertSame(45296.0, $cell->value, 'The formatter must write serial number 45296 for 2024-01-05.');

        $back = $this->parseDate($field, $cell->value);

        self::assertSame('2024-01-05', $back->format('Y-m-d'), 'The parser must give back the same day from that same serial number.');
    }

    #[Test]
    public function bothThePatternAndIsoTextAreAccepted(): void
    {
        $field = Field::date('createdAt');

        // The format the user SEES in the template…
        self::assertSame('2024-01-05', $this->parseDate($field, '05.01.2024')->format('Y-m-d'));
        // …and the canonical notation produced by some other system.
        self::assertSame('2024-01-05', $this->parseDate($field, '2024-01-05')->format('Y-m-d'));
    }

    /** @return iterable<string, array{string}> */
    public static function unparsableDates(): iterable
    {
        yield 'non-existent day and month' => ['32.13.2024'];
        yield 'MySQL zero date' => ['0000-00-00'];
        yield 'MySQL zero datetime' => ['0000-00-00 00:00:00'];
        yield 'free text' => ['yarın'];
    }

    #[Test]
    #[DataProvider('unparsableDates')]
    public function impossibleDatesAreRejectedAndTheMessageNamesTheExpectedPattern(string $raw): void
    {
        // "32.13.2024" would silently overflow into the next month in `createFromFormat`, and
        // the zero date would be parsed by PHP as 1 BC. Neither may reach the database.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('expected format: d.m.Y');

        $this->parse(Field::date('createdAt'), $raw);
    }

    // ---------------------------------------------------------------- enum / options

    #[Test]
    public function anEnumMapsTheTranslatedLabelBackToTheCase(): void
    {
        $field = Field::enum('status', Status::class);

        // This is the text the user sees in the cell; this is where the round trip closes.
        self::assertSame(Status::Open, $this->parse($field, 'Açık'));
        self::assertSame(Status::Closed, $this->parse($field, ' Kapalı '));
        self::assertSame(Status::Open, $this->parse($field, 'açık'));
    }

    #[Test]
    public function anEnumAlsoAcceptsTheBackingValueAndTheCaseName(): void
    {
        $field = Field::enum('status', Status::class);

        // The file may have been filled in by a developer or by another system.
        self::assertSame(Status::Open, $this->parse($field, 'open'));
        self::assertSame(Status::Closed, $this->parse($field, 'Closed'));
        self::assertSame(Status::Open, $this->parse($field, Status::Open));
    }

    #[Test]
    public function anUnknownLabelThrowsAndListsTheOptions(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('"Beklemede" is not in the list. Options: Açık, Kapalı');

        $this->parse(Field::enum('status', Status::class), 'Beklemede');
    }

    #[Test]
    public function anOptionSetMapsLabelTranslationKeyAndRawKeyBackToTheKey(): void
    {
        $field = Field::options('type', ['b2b' => 'opt.b2b', 'b2c' => 'opt.b2c']);

        self::assertSame('b2b', $this->parse($field, 'Kurumsal'));
        self::assertSame('b2b', $this->parse($field, 'opt.b2b'));
        self::assertSame('b2c', $this->parse($field, 'b2c'));
    }

    // ---------------------------------------------------------------- strings

    /**
     * ★ Barcode corruption: Excel takes the cell `8691234567890123` for a number and hands it
     * to the reader as a float. A plain `(string)` cast writes that into the database in
     * exponential notation, and the value can never be recovered again.
     */
    #[Test]
    public function anExcelFloatNeverComesBackInExponentForm(): void
    {
        $field = Field::string('barcode');

        self::assertSame('8691234567890123', $this->parseString($field, 8691234567890123.0));

        $huge = $this->parseString($field, 1.0E+25);

        self::assertMatchesRegularExpression('/^\d+$/', $huge, 'Exponential notation cannot be written to the database.');
        self::assertStringNotContainsString('E', $huge);
    }

    #[Test]
    public function aStringFieldPreservesLeadingZerosAndNeverSwallowsFalse(): void
    {
        $field = Field::string('code');

        self::assertSame('0501', $this->parseString($field, '0501'));
        // `(string) false` produces an empty string; on a text column that is silently swallowing the data.
        self::assertSame('Evet', $this->parseString($field, true));
        self::assertSame('Hayır', $this->parseString($field, false));
    }

    // ---------------------------------------------------------------- empty cell

    /** @return iterable<string, array{Field}> */
    public static function everyFieldType(): iterable
    {
        yield 'string' => [Field::string('value')];
        yield 'integer' => [Field::integer('value')];
        yield 'decimal' => [Field::decimal('value')];
        yield 'money' => [Field::money('value')];
        yield 'quantity' => [Field::quantity('value')];
        yield 'bool' => [Field::bool('value')];
        yield 'date' => [Field::date('value')];
        yield 'date/time' => [Field::dateTime('value')];
        yield 'enum' => [Field::enum('value', Status::class)];
        yield 'options' => [Field::options('value', ['b2b' => 'opt.b2b'])];
    }

    /**
     * A blank is NOT AN ERROR; it means "no value".
     *
     * Checking what is mandatory is the job of the import loop, which knows the field. If the
     * parser threw an exception here, every empty cell of a non-required field would turn
     * into a row error.
     */
    #[Test]
    #[DataProvider('everyFieldType')]
    public function anEmptyCellIsNullForEveryParser(Field $field): void
    {
        // A non-breaking space and a BOM are not cleaned up by `trim()`; in cells coming out
        // of spreadsheets both are ordinary residue.
        foreach ([null, '', '   ', "\u{00A0}", "\u{FEFF} ", "\u{202F}"] as $blank) {
            self::assertNull(
                $this->parse($field, $blank),
                sprintf('On a %s field, %s must count as blank.', $field->getType()->value, var_export($blank, true)),
            );
        }
    }
}
