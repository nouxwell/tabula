<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Import;

use DateTimeImmutable;
use Nouxwell\Tabula\Exception\ImportException;
use Nouxwell\Tabula\Import\ImportedRow;
use Nouxwell\Tabula\Import\ImportResult;
use Nouxwell\Tabula\Import\MatchStrategy;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Port\Translator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\NumberSettings;
use Nouxwell\Tabula\Settings\SymbolPosition;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Template\TemplateBuilder;
use Nouxwell\Tabula\Template\TemplateOptions;
use Nouxwell\Tabula\Tests\Fixture\Status;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetXlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ★ THE HEADLINE TEST OF THIS PHASE: produce a template → fill it in like a user → import it
 * back.
 *
 * This is where the "one schema, three directions" claim is measured. Export, template
 * production and import are all fed from the same `Schema` object; consequently the file we
 * produce must be readable back without any conversion at all AND the values must come back
 * with the right TYPE — a boolean as a real bool, an enum as an enum INSTANCE, a date as a
 * `DateTimeImmutable`, a quantity as a float.
 *
 * The second and more important claim: THE FILE'S IDENTITY IS NOT THE TRANSLATION. In the
 * system this replaces the matching was done with the translated header text, which means a
 * single word changed in a translation file silently made every template users had on disk
 * unreadable. The tests below demonstrate this once and refute it once.
 */
final class RoundTripTest extends TestCase
{
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

    /**
     * The original catalogue — the language the template was produced in.
     *
     * @param array<string, string> $overrides
     */
    private function translator(array $overrides = []): Translator
    {
        return new ArrayTranslator([
            'tr' => array_merge([
                'sheet.customer' => 'Müşteriler',
                'col.code' => 'Kod',
                'col.name' => 'Ünvan',
                'col.qty' => 'Miktar',
                'col.balance' => 'Bakiye',
                'col.active' => 'Aktif',
                'col.status' => 'Durum',
                'col.created' => 'Oluşturma',
                'status.open' => 'Açık',
                'status.closed' => 'Kapalı',
                'tabula.bool.yes' => 'Evet',
                'tabula.bool.no' => 'Hayır',
            ], $overrides),
        ]);
    }

    /**
     * A catalogue in which EVERY column label has been rewritten.
     *
     * The value dictionaries (`status.*`, `tabula.bool.*`) are deliberately left UNTOUCHED:
     * what the key row preserves is the COLUMN IDENTITY. The text inside the cell is a
     * separate contract, and that boundary is measured separately below.
     */
    private function retranslated(): Translator
    {
        return $this->translator([
            'sheet.customer' => 'Cari Hesaplar',
            'col.code' => 'Cari Kodu',
            'col.name' => 'Cari Ünvanı',
            'col.qty' => 'Adet',
            'col.balance' => 'Güncel Bakiye',
            'col.active' => 'Kayıt Aktif mi',
            'col.status' => 'Kayıt Durumu',
            'col.created' => 'Kayıt Tarihi',
        ]);
    }

    private function settings(): TabulaSettings
    {
        return new TabulaSettings(
            numbers: new NumberSettings(
                currencySymbols: ['TRY' => '₺'],
                symbolPosition: SymbolPosition::After,
            ),
        );
    }

    private function schema(): Schema
    {
        return Schema::make('customer')->title('sheet.customer')->fields(
            Field::string('code')->label('col.code')->required(),
            Field::string('name')->label('col.name'),
            Field::quantity('qty')->label('col.qty')->decimals(3),
            Field::money('balance')->label('col.balance')->currency('TRY'),
            Field::bool('isActive')->label('col.active'),
            Field::enum('status', Status::class)->label('col.status'),
            Field::date('createdAt')->label('col.created'),
        );
    }

    /**
     * The two rows the user wrote into the template.
     *
     * The two rows DELIBERATELY come by different routes. A date cell filled in inside Excel
     * is stored as a SERIAL NUMBER (row 3); for a user who pasted the file in or filled it
     * from another system, the same cell is TEXT (row 4). Both must land on the same
     * `DateTimeImmutable`.
     *
     * @return list<list<mixed>>
     */
    private function userRows(): array
    {
        return [
            ['0042', 'Çiğdem Şahin Ltd. Şti.', 12.5, '1.234,56 ₺', 'Evet', 'Açık', 45296],
            ['0043', 'Öz Güneş A.Ş.', 3, '-99,90', 'Hayır', 'Kapalı', '11.02.2024'],
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function template(Translator $translator, string $name, bool $includeKeyRow = true): string
    {
        $builder = new TemplateBuilder(
            $translator,
            $this->settings(),
            new TemplateOptions(includeKeyRow: $includeKeyRow),
        );

        $path = $this->dir->file($name);

        return $builder->write($this->schema(), $path, 'tr');
    }

    /**
     * Fills the template in like a user: OPENS the file, writes the data rows, saves it back.
     *
     * Strings are written with an EXPLICIT type — the default binder would take "0042" for a
     * number and turn it into 42, which would break the leading-zero preservation the test
     * means to measure before it ever got measured. (The template's text column is already
     * '@' formatted in Excel; this is the programmatic counterpart of that behaviour.)
     */
    private function fill(string $path, int $firstDataRow): void
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);

        foreach ($this->userRows() as $rowIndex => $cells) {
            foreach ($cells as $columnIndex => $value) {
                $coordinate = [$columnIndex + 1, $firstDataRow + $rowIndex];

                if (is_string($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
        }

        (new SpreadsheetXlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param list<ImportedRow> $collected
     */
    private function import(
        Translator $translator,
        string $path,
        array &$collected,
        MatchStrategy $strategy = MatchStrategy::Auto,
    ): ImportResult {
        $tabula = new Tabula($translator, $this->settings());

        return $tabula->import($this->schema())
            ->from($path)
            ->locale('tr')
            ->matchBy($strategy)
            ->each(static function (ImportedRow $row) use (&$collected): void {
                $collected[] = $row;
            })
            ->run();
    }

    private function dateOf(ImportedRow $row, string $key): DateTimeImmutable
    {
        $value = $row->get($key);

        if (!$value instanceof DateTimeImmutable) {
            self::fail(sprintf('The "%s" field should have been a DateTimeImmutable, got: %s', $key, get_debug_type($value)));
        }

        return $value;
    }

    // ---------------------------------------------------------------- round trip

    #[Test]
    public function everyValueSurvivesTheRoundTripWithItsRightType(): void
    {
        $path = $this->template($this->translator(), 'template.xlsx');
        $this->fill($path, 3);

        /** @var list<ImportedRow> $rows */
        $rows = [];
        $result = $this->import($this->translator(), $path, $rows);

        self::assertTrue($result->isCompletelySuccessful(), sprintf(
            'The import should have finished without errors. Errors: %s',
            implode(' | ', array_map(static fn ($error): string => $error->row.': '.$error->message, $result->errors)),
        ));
        self::assertSame(2, $result->read);
        self::assertSame(2, $result->imported);
        self::assertSame(
            ['code', 'name', 'qty', 'balance', 'isActive', 'status', 'createdAt'],
            $result->columns,
            'The recognised columns must be reported in the order of the file and with their canonical keys.',
        );
        self::assertSame([], $result->ignored);

        self::assertCount(2, $rows);
        $first = $rows[0];
        $second = $rows[1];

        // The row number is THE NUMBER THE USER SEES: the data starts at row 3.
        self::assertSame(3, $first->row);
        self::assertSame(4, $second->row);

        // Text: the leading zero must not be eaten (the most frequently reported import bug of
        // the system this replaces).
        self::assertSame('0042', $first->get('code'));
        self::assertSame('Çiğdem Şahin Ltd. Şti.', $first->get('name'));

        // A quantity is always a float, money is always a float — a column's type does not
        // change from row to row.
        self::assertSame(12.5, $first->get('qty'));
        self::assertIsFloat($second->get('qty'));
        self::assertEqualsWithDelta(1234.56, $first->get('balance'), 0.0001);
        self::assertEqualsWithDelta(-99.9, $second->get('balance'), 0.0001);

        // A boolean is a REAL bool, not the string "Evet".
        self::assertTrue($first->get('isActive'));
        self::assertFalse($second->get('isActive'));
        self::assertIsBool($first->get('isActive'));

        // An enum INSTANCE: the calling side does not have to bother with string matching.
        self::assertSame(Status::Open, $first->get('status'));
        self::assertSame(Status::Closed, $second->get('status'));

        // Excel's serial number and the text the user typed must land on the same day.
        self::assertSame('2024-01-05 00:00:00', $this->dateOf($first, 'createdAt')->format('Y-m-d H:i:s'));
        self::assertSame('2024-02-11 00:00:00', $this->dateOf($second, 'createdAt')->format('Y-m-d H:i:s'));
    }

    /**
     * ★ THE PROOF THAT THE FATAL FLAW OF THE SYSTEM THIS REPLACES HAS BEEN REPAIRED.
     *
     * The same file is imported a second time with a catalogue in which EVERY column label has
     * been rewritten, and it goes on working — because the matching goes through the canonical
     * keys in row 1, not through the header the user reads.
     *
     * The opposite end is held in the very same test: `MatchStrategy::Label` explicitly says
     * "match me by label", and down that route the file can no longer be recognised. That was
     * the ONLY route the system this replaces had.
     */
    #[Test]
    public function theKeyRowSurvivesACompleteRetranslationWhereTheLabelWouldNot(): void
    {
        $path = $this->template($this->translator(), 'template.xlsx');
        $this->fill($path, 3);

        /** @var list<ImportedRow> $rows */
        $rows = [];
        $result = $this->import($this->retranslated(), $path, $rows);

        self::assertTrue(
            $result->isCompletelySuccessful(),
            'A template the user has on disk must NOT become unreadable just because the translation changed.',
        );
        self::assertSame(2, $result->imported);
        self::assertSame('0042', $rows[0]->get('code'));
        self::assertSame(Status::Open, $rows[0]->get('status'));

        // …and now the same file, with the same catalogue, but looking for the identity in the LABEL:
        /** @var list<ImportedRow> $labelRows */
        $labelRows = [];

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('None of the columns in the file matched the schema');

        $this->import($this->retranslated(), $path, $labelRows, MatchStrategy::Label);
    }

    /**
     * The same flaw is still fatal in a file that has NO key row.
     *
     * `TemplateOptions(includeKeyRow: false)` reduces the template to the layout of the system
     * this replaces: the file's identity is the retranslated header text. Such a file is
     * readable with the original catalogue and becomes unreadable the moment the catalogue
     * changes. This is the comparison that shows in a single test WHY the key row is the
     * default.
     */
    #[Test]
    public function aFileWithoutTheKeyRowBreaksTheMomentTheTranslationChanges(): void
    {
        $path = $this->template($this->translator(), 'etiketli.xlsx', includeKeyRow: false);
        $this->fill($path, 2);

        /** @var list<ImportedRow> $rows */
        $rows = [];
        $result = $this->import($this->translator(), $path, $rows);

        self::assertTrue($result->isCompletelySuccessful(), 'Label matching must work with the original catalogue.');
        self::assertSame(2, $result->imported);
        self::assertSame('0042', $rows[0]->get('code'));

        /** @var list<ImportedRow> $afterRename */
        $afterRename = [];

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Make sure you downloaded the right template.');

        $this->import($this->retranslated(), $path, $afterRename);
    }

    /**
     * THE BOUNDARY itself: the key row preserves the COLUMN identity, not the CELL CONTENT.
     *
     * The text written into an enum cell is the translated label ("Açık"). If that label is
     * rewritten, the value in an already filled file no longer lands on any case and the row
     * is rejected. This is the residue, on the cell side, of the flaw on the header side;
     * having it documented stops anyone from summarising the work as "a translation change can
     * no longer break anything".
     */
    #[Test]
    public function retranslatingAValueLabelStillBreaksAnAlreadyFilledFile(): void
    {
        $path = $this->template($this->translator(), 'template.xlsx');
        $this->fill($path, 3);

        /** @var list<ImportedRow> $rows */
        $rows = [];
        $result = $this->import(
            $this->translator(['status.open' => 'Yeni Kayıt', 'status.closed' => 'Kapatılmış']),
            $path,
            $rows,
        );

        self::assertSame(2, $result->read);
        self::assertSame(0, $result->imported, 'Once the value dictionary changes, the enum cells of the old file are not recognised.');

        $errors = $result->errorsByRow();

        self::assertArrayHasKey(3, $errors);
        self::assertSame('status', $errors[3][0]->field);
        self::assertStringContainsString('Options: Yeni Kayıt, Kapatılmış', $errors[3][0]->message);
        // The raw value travels alongside the error: the user must know which cell to look at.
        self::assertSame('Açık', $errors[3][0]->value);
    }
}
