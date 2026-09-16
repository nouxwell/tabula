<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Import;

use DateTimeImmutable;
use Nouxwell\Tabula\Exception\ImportException;
use Nouxwell\Tabula\Import\ImportBuilder;
use Nouxwell\Tabula\Import\ImportedRow;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetXlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ImportedRow::raw()` hands back the value the reader produced, next to the parsed one.
 *
 * The parsed value answers "what does this cell mean"; it cannot answer "what was in it". A
 * quantity written as "1.234,5" comes back as 1234.5 and the text is gone — right for saving the
 * record, not enough for showing the user what they uploaded, for an audit trail, or for moving
 * code that normalises strings itself over to typed values one field at a time.
 *
 * Two things are pinned down besides the values. The raw value is the READER's value, not the
 * user's keystrokes: an xlsx stores a date as a serial number, so a serial number comes back. And
 * it is kept only on request (`keepRawValues()`), because a caller who keeps the row objects pays
 * for it in memory — a row that was not asked to keep them refuses instead of answering null.
 */
final class RawValueTest extends TestCase
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

    private function schema(): Schema
    {
        return Schema::make('stock')->fields(
            Field::string('code')->label('col.code')->required(),
            Field::quantity('qty')->label('col.qty'),
            Field::string('note')->label('col.note'),
            Field::date('countedAt')->label('col.counted'),
        );
    }

    private function builder(string $path): ImportBuilder
    {
        $tabula = new Tabula(new ArrayTranslator([
            'tr' => [
                'col.code' => 'Kod',
                'col.qty' => 'Miktar',
                'col.note' => 'Not',
                'col.counted' => 'Sayım',
            ],
        ]), new TabulaSettings());

        return $tabula->import($this->schema())->from($path)->locale('tr');
    }

    private function csv(string $contents): string
    {
        $path = $this->dir->file('stock.csv');
        file_put_contents($path, $contents);

        return $path;
    }

    /** @return list<ImportedRow> */
    private function collect(ImportBuilder $builder): array
    {
        $rows = [];

        $result = $builder
            ->each(static function (ImportedRow $row) use (&$rows): void {
                $rows[] = $row;
            })
            ->run();

        self::assertSame([], $result->errors, 'The fixture is meant to import without errors.');

        return $rows;
    }

    /** @return list<ImportedRow> */
    private function importCsv(string $contents): array
    {
        return $this->collect($this->builder($this->csv($contents))->keepRawValues());
    }

    // ---------------------------------------------------------------- csv

    #[Test]
    public function aQuantityComesBackParsedAndAsTheTextThatWasInTheFile(): void
    {
        [$row] = $this->importCsv("Kod;Miktar;Not;Sayım\nA-1;1.234,5;;11.02.2024\n");

        self::assertSame(1234.5, $row->get('qty'));
        self::assertSame('1.234,5', $row->raw('qty'), 'The text the parser read the number from.');
    }

    #[Test]
    public function theParserTrimsButTheRawValueKeepsWhatWasThere(): void
    {
        [$row] = $this->importCsv("Kod;Miktar;Not;Sayım\nA-1;5;  hazır  ;11.02.2024\n");

        self::assertSame('hazır', $row->get('note'));
        self::assertSame('  hazır  ', $row->raw('note'));
    }

    #[Test]
    public function aCellThatParsesToNothingStillShowsWhatWasInIt(): void
    {
        [$row] = $this->importCsv("Kod;Miktar;Not;Sayım\nA-1;5;   ;11.02.2024\n");

        self::assertNull($row->get('note'), 'Whitespace alone is no value.');
        self::assertSame('   ', $row->raw('note'), 'But the cell was not empty, and the raw value says so.');
    }

    #[Test]
    public function anEmptyCsvCellIsAnEmptyStringAndAMissingOneIsNull(): void
    {
        // Row 2 writes the note column empty; row 3 is short and never writes the last two cells.
        [$empty, $short] = $this->importCsv("Kod;Miktar;Not;Sayım\nA-1;5;;11.02.2024\nA-2;7\n");

        self::assertSame('', $empty->raw('note'));
        self::assertNull($short->raw('note'));
        self::assertNull($short->raw('countedAt'));
    }

    // ---------------------------------------------------------------- xlsx

    #[Test]
    public function anXlsxCellComesBackAsWhatTheWorkbookStores(): void
    {
        $path = $this->dir->file('stock.xlsx');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Kod', 'Miktar', 'Not', 'Sayım'], null, 'A1');
        $sheet->setCellValueExplicit('A2', 'A-1', DataType::TYPE_STRING);
        $sheet->setCellValue('B2', 12.5);
        $sheet->setCellValue('D2', 45296);
        (new SpreadsheetXlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        [$row] = $this->collect($this->builder($path)->keepRawValues());

        $date = $row->get('countedAt');
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2024-01-05', $date->format('Y-m-d'));
        self::assertEquals(45296, $row->raw('countedAt'), 'The serial number, not "05.01.2024".');
        self::assertIsNotString($row->raw('countedAt'));
        self::assertSame(12.5, $row->raw('qty'), 'A number cell is a number, not text.');
        self::assertNull($row->raw('note'), 'An empty xlsx cell is null.');
    }

    // ---------------------------------------------------------------- only on request

    #[Test]
    public function withoutKeepRawValuesTheRowRefusesRatherThanAnsweringNull(): void
    {
        $base = $this->builder($this->csv("Kod;Miktar;Not;Sayım\nA-1;1.234,5;;11.02.2024\n"));
        // Deriving a copy that keeps them must leave the base as it was: the builder is immutable.
        $base->keepRawValues();

        [$row] = $this->collect($base);

        self::assertSame(1234.5, $row->get('qty'), 'Parsed values are there either way.');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('keepRawValues()');

        $row->raw('qty');
    }

    #[Test]
    public function aRowBuiltByHandWithoutRawValuesRefusesToo(): void
    {
        $row = new ImportedRow(3, ['qty' => 1.0]);

        $this->expectException(ImportException::class);

        $row->rawValues();
    }

    // ---------------------------------------------------------------- contract

    #[Test]
    public function aTypoInTheKeyIsRefusedTheWayGetRefusesIt(): void
    {
        [$row] = $this->importCsv("Kod;Miktar;Not;Sayım\nA-1;5;;11.02.2024\n");

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('no field called "qyt"');

        $row->raw('qyt');
    }

    #[Test]
    public function toArrayStillCarriesOnlyTheParsedValuesAndRawValuesUseTheSameKeys(): void
    {
        [$row] = $this->importCsv("Kod;Miktar;Fazla;Not;Sayım\nA-1;1.234,5;x;;11.02.2024\n");

        self::assertSame(1234.5, $row->toArray()['qty'], 'Saving `toArray()` must not start saving text.');
        self::assertSame(array_keys($row->toArray()), array_keys($row->rawValues()));
        self::assertArrayNotHasKey('Fazla', $row->rawValues(), 'A column that matched no field has no raw value.');
    }
}
