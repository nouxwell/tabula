<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Import;

use Nouxwell\Tabula\Exception\ImportException;
use Nouxwell\Tabula\Exception\ParseException;
use Nouxwell\Tabula\Import\ErrorMode;
use Nouxwell\Tabula\Import\ImportedRow;
use Nouxwell\Tabula\Import\RowError;
use Nouxwell\Tabula\Import\RowErrorCode;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\Status;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use Nouxwell\Tabula\Value\ParseContext;
use Nouxwell\Tabula\Value\ParserRegistry;
use Nouxwell\Tabula\Value\ValueParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every cell failure carries a `RowErrorCode` and the values behind its message.
 *
 * The message is a sentence in English. An application whose users read another language was left
 * with two bad choices: show them English, or validate every cell again in its own code to get its
 * own wording — the second copy of the rules the schema exists to hold once. What is pinned down:
 *
 *  - every failure the import can report has a code, with the params its wording needs;
 *  - the English message did not change by a byte;
 *  - the params go into a translator as they are;
 *  - a custom parser that throws without a code still produces the error, just without a code.
 */
final class RowErrorCodeTest extends TestCase
{
    private const string HEADER = "Kod;Miktar;Fiyat;Koli;Termin;Acil;Durum;Kanal\n";

    /** A row that imports cleanly; each case breaks exactly one of its cells. */
    private const array VALID = [
        'code' => 'A-1',
        'qty' => '5',
        'price' => '10',
        'boxes' => '2',
        'due' => '11.02.2024',
        'urgent' => 'Evet',
        'status' => 'Açık',
        'channel' => 'Web',
    ];

    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    // ---------------------------------------------------------------- set-up

    private function schema(): Schema
    {
        return Schema::make('order')->fields(
            Field::string('code')->label('col.code')->required(),
            Field::quantity('qty')->label('col.qty'),
            Field::money('price')->label('col.price')->currency('TRY'),
            Field::integer('boxes')->label('col.boxes'),
            Field::date('due')->label('col.due'),
            Field::bool('urgent')->label('col.urgent'),
            Field::enum('status', Status::class)->label('col.status'),
            Field::options('channel', ['web' => 'Web', 'shop' => 'Mağaza'])->label('col.channel'),
        );
    }

    private function translator(): ArrayTranslator
    {
        return new ArrayTranslator([
            'tr' => [
                'col.code' => 'Kod',
                'col.qty' => 'Miktar',
                'col.price' => 'Fiyat',
                'col.boxes' => 'Koli',
                'col.due' => 'Termin',
                'col.urgent' => 'Acil',
                'col.status' => 'Durum',
                'col.channel' => 'Kanal',
                'status.open' => 'Açık',
                'status.closed' => 'Kapalı',
                // An application's own wording, keyed by the code.
                'import.error.not_a_number' => '"%value%" bir sayı değil (%field%).',
            ],
        ]);
    }

    /** @param array<string, string> $overrides field key => cell text */
    private function csv(array $overrides): string
    {
        $path = $this->dir->file('order.csv');
        file_put_contents($path, self::HEADER.implode(';', array_merge(self::VALID, $overrides))."\n");

        return $path;
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return list<RowError>
     */
    private function errorsFor(array $overrides, ?Tabula $tabula = null): array
    {
        $result = ($tabula ?? new Tabula($this->translator(), new TabulaSettings()))
            ->import($this->schema())
            ->from($this->csv($overrides))
            ->locale('tr')
            ->each(static fn (ImportedRow $row) => null)
            ->run();

        return $result->errors;
    }

    // ---------------------------------------------------------------- every failure has a code

    /**
     * The last element is the English message, byte for byte. `{accepted}` stands for the list of
     * yes/no words, which comes from the settings and the translator rather than from this test.
     *
     * @return iterable<string, array{string, string, RowErrorCode, array<string, string>, string|null, string}>
     */
    public static function failures(): iterable
    {
        yield 'a required field left empty' => ['code', '', RowErrorCode::Required, ['field' => 'code', 'type' => 'string'], null, 'This field is required and cannot be left empty.'];
        yield 'a quantity that is not a number' => ['qty', 'N/A', RowErrorCode::NotANumber, ['field' => 'qty', 'type' => 'quantity', 'value' => 'N/A'], null, '"N/A" could not be read as a number.'];
        yield 'money that is not a number' => ['price', 'abc', RowErrorCode::NotANumber, ['field' => 'price', 'type' => 'money', 'value' => 'abc'], null, '"abc" could not be read as a number.'];
        yield 'a fraction in an integer field' => ['boxes', '2,5', RowErrorCode::NotAnInteger, ['field' => 'boxes', 'type' => 'integer', 'value' => '2,5'], null, '"2,5" is not a whole number.'];
        yield 'a date that does not exist' => ['due', '32.13.2024', RowErrorCode::NotADate, ['field' => 'due', 'type' => 'date', 'value' => '32.13.2024'], 'format', '"32.13.2024" could not be read as a date; expected format: d.m.Y'];
        yield 'a word that is not yes or no' => ['urgent', 'Belki', RowErrorCode::NotABoolean, ['field' => 'urgent', 'type' => 'bool', 'value' => 'Belki'], 'accepted', '"Belki" is not a Yes/No value. Accepted: {accepted}'];
        yield 'an enum value not in the list' => ['status', 'Beklemede', RowErrorCode::NotAnOption, ['field' => 'status', 'type' => 'enum', 'value' => 'Beklemede'], 'options', '"Beklemede" is not in the list. Options: Açık, Kapalı'];
        yield 'an option not in the list' => ['channel', 'Telefon', RowErrorCode::NotAnOption, ['field' => 'channel', 'type' => 'options', 'value' => 'Telefon'], 'options', '"Telefon" is not in the list. Options: Web, Mağaza'];
    }

    /**
     * @param array<string, string> $params  the params every failure of this kind carries
     * @param string|null           $extra   a further param the wording needs; must be present and non-empty
     * @param string                $message the English message, unchanged by the codes
     */
    #[Test]
    #[DataProvider('failures')]
    public function everyCellFailureCarriesItsCodeAndParams(string $field, string $cell, RowErrorCode $code, array $params, ?string $extra, string $message): void
    {
        $errors = $this->errorsFor([$field => $cell]);

        self::assertCount(1, $errors, 'Exactly one cell was broken.');
        self::assertSame($code, $errors[0]->code);
        self::assertSame($params, array_intersect_key($errors[0]->params, $params));
        // `accepted` is checked through the message: the param must be exactly the list the
        // message quotes, so the two cannot drift apart.
        self::assertSame(
            str_replace('{accepted}', (string) ($errors[0]->params['accepted'] ?? ''), $message),
            $errors[0]->message,
        );

        if (null === $extra) {
            self::assertSame(array_keys($params), array_keys($errors[0]->params), 'No params beyond the expected ones.');
        } else {
            self::assertArrayHasKey($extra, $errors[0]->params);
            self::assertNotSame('', $errors[0]->params[$extra]);
        }
    }

    #[Test]
    public function theParamsCarryTheListsTheMessageQuotes(): void
    {
        [$enum] = $this->errorsFor(['status' => 'Beklemede']);
        [$option] = $this->errorsFor(['channel' => 'Telefon']);
        [$date] = $this->errorsFor(['due' => '32.13.2024']);

        self::assertSame('Açık, Kapalı', $enum->params['options'], 'The translated labels, as the message shows them.');
        self::assertSame('Web, Mağaza', $option->params['options']);
        self::assertSame('d.m.Y', $date->params['format']);

        [$bool] = $this->errorsFor(['urgent' => 'Belki']);
        self::assertStringContainsString('Evet', (string) $bool->params['accepted'], 'The accepted words, not an echo of the value.');
        self::assertStringNotContainsString('Belki', (string) $bool->params['accepted']);
    }

    /**
     * A field with no options at all: the English message says "(no options defined)", but that
     * placeholder text stays out of the params — the application words that case itself.
     */
    #[Test]
    public function anOptionsFieldWithNoOptionsReportsAnEmptyList(): void
    {
        $path = $this->dir->file('empty-options.csv');
        file_put_contents($path, "Kanal\nWeb\n");

        $result = (new Tabula($this->translator(), new TabulaSettings()))
            ->import(Schema::make('order')->fields(Field::options('channel', [])->label('col.channel')))
            ->from($path)
            ->locale('tr')
            ->each(static fn (ImportedRow $row) => null)
            ->run();

        self::assertCount(1, $result->errors);
        self::assertSame(RowErrorCode::NotAnOption, $result->errors[0]->code);
        self::assertSame('', $result->errors[0]->params['options']);
        self::assertStringEndsWith('Options: (no options defined)', $result->errors[0]->message);
    }

    // ---------------------------------------------------------------- nothing else changed

    #[Test]
    public function aRequiredErrorStillCarriesNoValue(): void
    {
        [$error] = $this->errorsFor(['code' => '']);

        self::assertNull($error->value, 'There was nothing to reject; the code does not change that.');
    }

    // ---------------------------------------------------------------- using the code

    #[Test]
    public function theParamsGoIntoATranslatorAsTheyAre(): void
    {
        [$error] = $this->errorsFor(['qty' => 'N/A']);

        self::assertNotNull($error->code);
        self::assertSame(
            '"N/A" bir sayı değil (qty).',
            $this->translator()->trans('import.error.'.$error->code->value, $error->params, 'tr'),
        );
    }

    #[Test]
    public function failFastCarriesTheCodesToo(): void
    {
        $tabula = new Tabula($this->translator(), new TabulaSettings());

        try {
            $tabula->import($this->schema())
                ->from($this->csv(['qty' => 'N/A']))
                ->locale('tr')
                ->onError(ErrorMode::FailFast)
                ->each(static fn (ImportedRow $row) => null)
                ->run();

            self::fail('FailFast should have stopped at the broken row.');
        } catch (ImportException $exception) {
            self::assertSame(RowErrorCode::NotANumber, $exception->rowErrors()[0]->code);
        }
    }

    #[Test]
    public function aCustomParserThatThrowsWithoutACodeStillReportsTheCell(): void
    {
        $evenBoxes = new class implements ValueParser {
            public function supports(FieldType $type): bool
            {
                return FieldType::Integer === $type;
            }

            public function parse(mixed $raw, Field $field, ParseContext $context): mixed
            {
                throw new ParseException('Koli sayısı çift olmalı.');
            }
        };

        $tabula = new Tabula(
            $this->translator(),
            new TabulaSettings(),
            parsers: ParserRegistry::default()->with($evenBoxes),
        );

        [$error] = $this->errorsFor([], $tabula);

        self::assertSame('Koli sayısı çift olmalı.', $error->message);
        self::assertNull($error->code);
        self::assertSame([], $error->params);
    }

    /**
     * The code values are what an application keys its catalogue by: renaming one silently
     * orphans a translation. This fails first if that ever happens.
     */
    #[Test]
    public function theCodeValuesAreAStableContract(): void
    {
        self::assertSame(
            ['required', 'not_a_number', 'not_an_integer', 'not_a_date', 'not_a_boolean', 'not_an_option'],
            array_map(static fn (RowErrorCode $code): string => $code->value, RowErrorCode::cases()),
        );
    }
}
