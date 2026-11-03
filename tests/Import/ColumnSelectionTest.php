<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Import;

use Nouxwell\Tabula\Exception\ImportException;
use Nouxwell\Tabula\Exception\SchemaException;
use Nouxwell\Tabula\Import\ImportBuilder;
use Nouxwell\Tabula\Import\ImportedRow;
use Nouxwell\Tabula\Import\ImportResult;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading some of a file's columns (`ImportBuilder::only()`), and some of a row's fields
 * (`ImportedRow::only()`).
 *
 * The builder's selection is not a narrowed schema, and the first test is why. A key row is
 * recognised only when every key in it belongs to the schema; a schema cut down to two fields no
 * longer recognises the template it was cut from, and refuses it as the wrong template. So the
 * file is matched against the whole schema, and only then are the other columns dropped.
 */
final class ColumnSelectionTest extends TestCase
{
    private const string TEMPLATE = "code;qty;note;countedAt\nKod;Miktar;Not;Sayım\nA-1;5;hazır;11.02.2024\n";

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

    private function builder(string $contents): ImportBuilder
    {
        $path = $this->dir->file('stock.csv');
        file_put_contents($path, $contents);

        $tabula = new Tabula(new ArrayTranslator([
            'tr' => [
                'col.code' => 'Kod',
                'col.qty' => 'Miktar',
                'col.note' => 'Not',
                'col.counted' => 'Sayım',
            ],
        ]), new TabulaSettings());

        return $tabula->import(Schema::make('stock')->fields(
            Field::string('code')->label('col.code')->required(),
            Field::quantity('qty')->label('col.qty'),
            Field::string('note')->label('col.note'),
            Field::date('countedAt')->label('col.counted'),
        ))->from($path)->locale('tr');
    }

    /** @return array{ImportResult, list<ImportedRow>} */
    private function import(ImportBuilder $builder): array
    {
        $rows = [];

        $result = $builder
            ->each(static function (ImportedRow $row) use (&$rows): void {
                $rows[] = $row;
            })
            ->run();

        return [$result, $rows];
    }

    // ---------------------------------------------------------------- the builder

    #[Test]
    public function aTemplateIsStillRecognisedWhenOnlySomeOfItsColumnsAreRead(): void
    {
        [$result, $rows] = $this->import($this->builder(self::TEMPLATE)->only(['qty', 'code'])->keepRawValues());

        self::assertCount(1, $rows, 'The label row must not come back as a record.');
        self::assertSame(['code' => 'A-1', 'qty' => 5.0], $rows[0]->toArray());
        self::assertSame(['code', 'qty'], $result->columns, 'The columns read, in file order.');
        self::assertSame(['code', 'qty'], array_keys($rows[0]->rawValues()));
    }

    #[Test]
    public function aColumnLeftOutIsNotLookedAt(): void
    {
        // The code is required and blank, the date unreadable — neither column was asked for.
        [$result, $rows] = $this->import($this->builder("Kod;Miktar;Not;Sayım\n;5;;not a date\n")->only(['qty']));

        self::assertSame([], $result->errors);
        self::assertSame(['qty' => 5.0], $rows[0]->toArray());
    }

    #[Test]
    public function aColumnLeftOutIsNotReportedAsIgnored(): void
    {
        [$result] = $this->import($this->builder("Kod;Miktar;Fazla;Not\nA-1;5;x;\n")->only(['qty']));

        self::assertSame(['Fazla'], $result->ignored, 'Ignored is for headers the schema does not know.');
    }

    #[Test]
    public function aPickedFieldTheFileDoesNotHaveIsAbsentFromTheRow(): void
    {
        [$result, $rows] = $this->import($this->builder("Kod;Miktar\nA-1;5\n")->only(['qty', 'note']));

        self::assertSame(['qty'], $result->columns);
        self::assertFalse($rows[0]->has('note'));
    }

    #[Test]
    public function aFileWithNoneOfThePickedFieldsIsRefused(): void
    {
        $builder = $this->builder("Kod;Miktar\nA-1;5\n")->only(['note', 'countedAt']);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Selected: note, countedAt. Found in the file: code, qty.');

        $this->import($builder);
    }

    #[Test]
    public function anUnknownKeyIsRefusedOnTheLineThatPicksIt(): void
    {
        $builder = $this->builder(self::TEMPLATE);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('no field called "qyt"');

        $builder->only(['qty', 'qyt']);
    }

    #[Test]
    public function anEmptySelectionIsRefused(): void
    {
        $builder = $this->builder(self::TEMPLATE);

        $this->expectException(SchemaException::class);

        $builder->only([]);
    }

    #[Test]
    public function pickingFieldsLeavesTheBaseBuilderReadingEverything(): void
    {
        $base = $this->builder(self::TEMPLATE);
        $base->only(['qty']);

        [$result] = $this->import($base);

        self::assertSame(['code', 'qty', 'note', 'countedAt'], $result->columns);
    }

    // ---------------------------------------------------------------- the row

    #[Test]
    public function aRowHandsBackThePickedFieldsInTheOrderTheyWereGiven(): void
    {
        $row = new ImportedRow(3, ['code' => 'A-1', 'qty' => 5.0, 'note' => null]);

        self::assertSame(['qty' => 5.0, 'code' => 'A-1'], $row->only(['qty', 'code']));
        self::assertSame(['note' => null], $row->only(['note']), 'A field that is there but empty is kept.');
    }

    #[Test]
    public function aRowRefusesATypoTheWayGetRefusesIt(): void
    {
        $row = new ImportedRow(3, ['code' => 'A-1', 'qty' => 5.0]);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('no field called "qyt"');

        $row->only(['code', 'qyt']);
    }

    #[Test]
    public function aRowAskedForNothingGivesAnEmptyArray(): void
    {
        self::assertSame([], (new ImportedRow(3, ['code' => 'A-1']))->only([]));
    }
}
