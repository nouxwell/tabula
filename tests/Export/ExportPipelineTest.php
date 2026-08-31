<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Export;

use DateTimeImmutable;
use Generator;
use Nouxwell\Tabula\Exception\ExportException;
use Nouxwell\Tabula\Export\Sheet\ChunkedSheets;
use Nouxwell\Tabula\Export\Sheet\GroupedSheets;
use Nouxwell\Tabula\Export\Writer\CsvWriter;
use Nouxwell\Tabula\Export\Writer\XlsxWriter;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\NumberSettings;
use Nouxwell\Tabula\Settings\SymbolPosition;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Source\ArraySource;
use Nouxwell\Tabula\Source\IteratorSource;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\Status;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * End-to-end pipeline tests.
 *
 * Why this file exists: the formatters, the writers and `ExportBuilder` were written in
 * parallel and had NO tests at all; a single review pass turned up seven real defects. Most
 * of the tests below are the one-to-one regression counterparts of those defects.
 */
final class ExportPipelineTest extends TestCase
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

    // ---------------------------------------------------------------- helpers

    private function tabula(?TabulaSettings $settings = null): Tabula
    {
        return new Tabula(
            new ArrayTranslator([
                'tr' => [
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
                ],
            ]),
            $settings ?? new TabulaSettings(
                numbers: new NumberSettings(
                    currencySymbols: ['TRY' => '₺'],
                    symbolPosition: SymbolPosition::After,
                ),
                // Naming the keys the catalogue above defines: the shipped defaults are the
                // plain words "Yes"/"No", precisely so an unconfigured export cannot print a
                // translation key into the cell.
                boolTrueKey: 'tabula.bool.yes',
                boolFalseKey: 'tabula.bool.no',
            ),
        );
    }

    /**
     * What an export says when nobody configured the boolean texts.
     *
     * The old defaults were translation keys, and a translator hands back whatever it cannot
     * translate — so every unconfigured export wrote the literal "tabula.bool.yes" into its
     * boolean columns. Silent nonsense, and the round trip broke with it: `BoolParser` has
     * never heard of that string, while it does accept "Yes".
     */
    #[Test]
    public function anUnconfiguredBoolColumnSaysYesNotATranslationKey(): void
    {
        $path = $this->dir->file('plain-bool.csv');

        $tabula = new Tabula(new ArrayTranslator([]), new TabulaSettings());
        $tabula->export(Schema::make('t')->fields(Field::bool('active')->label('Active')))
            ->from(ArraySource::of([['active' => true], ['active' => false]]))
            ->locale('tr')
            ->to(Format::Csv)
            ->write($path);

        $contents = (string) file_get_contents($path);

        self::assertStringNotContainsString('tabula.bool', $contents, 'A translation key must never reach the cell.');
        self::assertStringContainsString('Yes', $contents);
        self::assertStringContainsString('No', $contents);
    }

    private function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('col.code')->width(14)->required(),
            Field::string('name')->label('col.name'),
            Field::quantity('qty')->label('col.qty')->decimals(3),
            Field::money('balance')->label('col.balance')
                ->currency(static fn (array $row): string => $row['currencyCode']),
            Field::bool('isActive')->label('col.active'),
            Field::enum('status', Status::class)->label('col.status'),
            Field::date('createdAt')->label('col.created'),
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            [
                'code' => '0042',
                'name' => 'Çiğdem Şahin Ltd. Şti.',
                'qty' => 12.5,
                'balance' => 1234.56,
                'currencyCode' => 'TRY',
                'isActive' => true,
                'status' => Status::Open,
                'createdAt' => new DateTimeImmutable('2024-01-05 13:45:00'),
            ],
            [
                'code' => '0043',
                'name' => 'Öz Güneş A.Ş.',
                'qty' => 3,
                'balance' => -99.9,
                'currencyCode' => 'TRY',
                'isActive' => false,
                'status' => Status::Closed,
                'createdAt' => new DateTimeImmutable('2024-02-11 09:00:00'),
            ],
        ];
    }

    // ---------------------------------------------------------------- the smallest loop

    #[Test]
    public function itWritesAWorkbookFromAnArraySource(): void
    {
        $path = $this->dir->file('customers.xlsx');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')
            ->to(Format::Xlsx)
            ->write($path);

        self::assertFileExists($path);
        self::assertSame($path, $result->path());
        self::assertSame(2, $result->rows);
        self::assertSame(1, $result->sheets);
        self::assertFalse($result->isEmpty());

        $sheet = IOFactory::load($path)->getActiveSheet();

        // The headers are produced on the server, in the requested language.
        self::assertSame('Kod', $sheet->getCell('A1')->getValue());
        self::assertSame('Ünvan', $sheet->getCell('B1')->getValue());
        self::assertSame('Bakiye', $sheet->getCell('D1')->getValue());

        // The leading zero must survive: Excel's "turn anything that looks like a number into
        // a number" behaviour is the classic bug that eats account codes.
        self::assertSame('0042', $sheet->getCell('A2')->getValue());
        self::assertSame('Çiğdem Şahin Ltd. Şti.', $sheet->getCell('B2')->getValue());
    }

    #[Test]
    public function numericCellsStayRealNumbersSoExcelCanSumThem(): void
    {
        $path = $this->dir->file('numbers.xlsx');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        self::assertEqualsWithDelta(12.5, $sheet->getCell('C2')->getValue(), 0.0001);
        self::assertEqualsWithDelta(1234.56, $sheet->getCell('D2')->getValue(), 0.0001);
        self::assertIsFloat($sheet->getCell('D2')->getValue());

        // The symbol goes into the format code, not into the NUMBER — the cell stays summable.
        self::assertStringContainsString('₺', (string) $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());
    }

    #[Test]
    public function boolsAndEnumsAreWrittenInTheRequestedLanguage(): void
    {
        $path = $this->dir->file('translation.xlsx');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        self::assertSame('Evet', $sheet->getCell('E2')->getValue());
        self::assertSame('Hayır', $sheet->getCell('E3')->getValue());

        // The enum translation is resolved through the `label()` convention (with no TranslatableEnum).
        self::assertSame('Açık', $sheet->getCell('F2')->getValue());
        self::assertSame('Kapalı', $sheet->getCell('F3')->getValue());
    }

    // ---------------------------------------------------------------- CSV

    #[Test]
    public function csvIsWrittenWithBomAndSemicolonSoTurkishExcelReadsIt(): void
    {
        $path = $this->dir->file('customers.csv');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')->to(Format::Csv)->write($path);

        $contents = (string) file_get_contents($path);

        // Without a BOM, Excel takes the file for cp1254 and ş/ğ/ı/İ/ö/ç/ü get mangled.
        self::assertStringStartsWith("\xEF\xBB\xBF", $contents);
        self::assertStringContainsString('Çiğdem Şahin', $contents);

        // The `;` delimiter: in Turkish/European Excel `,` is the DECIMAL separator and would
        // split a number across two columns.
        self::assertStringContainsString('Kod;Ünvan;', $contents);

        // CSV writes the localised TEXT, not the raw value.
        self::assertStringContainsString('Evet', $contents);
        self::assertStringContainsString('1.234,56', $contents);
    }

    // ---------------------------------------------------------------- column selection

    #[Test]
    public function onlyWritesTheRequestedColumnsInTheRequestedOrder(): void
    {
        $path = $this->dir->file('selection.xlsx');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->only(['balance', 'code'])
            ->locale('tr')->to(Format::Xlsx)->write($path);

        self::assertSame(['balance', 'code'], $result->columns);

        $sheet = IOFactory::load($path)->getActiveSheet();
        self::assertSame('Bakiye', $sheet->getCell('A1')->getValue());
        self::assertSame('Kod', $sheet->getCell('B1')->getValue());
        self::assertNull($sheet->getCell('C1')->getValue());
    }

    // ---------------------------------------------------------------- sheets

    #[Test]
    public function chunkedStrategySplitsIntoRealSheetsInXlsxAndFilesInCsv(): void
    {
        $rows = array_map(
            static fn (int $i): array => ['code' => (string) $i],
            range(1, 5),
        );
        $schema = Schema::make('n')->fields(Field::string('code')->label('col.code'));

        $xlsx = $this->dir->file('chunked.xlsx');
        $result = $this->tabula()->export($schema)
            ->from(ArraySource::of($rows))
            ->sheets(new ChunkedSheets(2))
            ->locale('tr')->to(Format::Xlsx)->write($xlsx);

        self::assertSame(3, $result->sheets);
        self::assertCount(3, IOFactory::load($xlsx)->getSheetNames());

        $csv = $this->dir->file('chunked.csv');
        $csvResult = $this->tabula()->export($schema)
            ->from(ArraySource::of($rows))
            ->sheets(new ChunkedSheets(2))
            ->locale('tr')->to(Format::Csv)->write($csv);

        // CSV cannot carry sheets: every chunk becomes its own FILE.
        self::assertCount(3, $csvResult->paths);
        self::assertTrue($csvResult->isMultiFile());
        foreach ($csvResult->paths as $file) {
            self::assertFileExists($file);
        }
    }

    #[Test]
    public function groupedSheetsResolveFlatProjectionKeysAndKeepFalseSeparateFromNull(): void
    {
        // Doctrine projections return a flat `c.warehouse` key; the strategy has to read it
        // through ValueResolver (a hand-written copy of that logic was missing this).
        $rows = [
            ['code' => 'a', 'c.warehouse' => 'Merkez'],
            ['code' => 'b', 'c.warehouse' => 'Merkez'],
            ['code' => 'c', 'c.warehouse' => 'Şube'],
        ];

        $path = $this->dir->file('grouped.xlsx');
        $result = $this->tabula()->export(Schema::make('n')->fields(Field::string('code')->label('col.code')))
            ->from(ArraySource::of($rows))
            ->sheets(new GroupedSheets('c.warehouse'))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        self::assertSame(2, $result->sheets);
        self::assertSame(['Merkez', 'Şube'], IOFactory::load($path)->getSheetNames());
    }

    #[Test]
    public function groupedSheetsDoNotMergeFalseIntoTheNullBucket(): void
    {
        $rows = [
            ['code' => 'a', 'flag' => false],
            ['code' => 'b', 'flag' => null],
        ];

        $path = $this->dir->file('flag.xlsx');
        $result = $this->tabula()->export(Schema::make('n')->fields(Field::string('code')->label('col.code')))
            ->from(ArraySource::of($rows))
            ->sheets(new GroupedSheets('flag'))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        // Because `(string) false === ''`, if both fell through to the fallback name the "no"
        // group and the "has no value" group would merge onto the same sheet — silent data loss.
        self::assertSame(2, $result->sheets);
    }

    // ---------------------------------------------------------------- edge cases

    #[Test]
    public function anEmptyResultStillProducesAHeaderOnlySheet(): void
    {
        $path = $this->dir->file('empty.xlsx');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of([]))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        self::assertTrue($result->isEmpty());
        self::assertSame(1, $result->sheets);

        $sheet = IOFactory::load($path)->getActiveSheet();
        self::assertSame('Kod', $sheet->getCell('A1')->getValue());
        self::assertNull($sheet->getCell('A2')->getValue());
    }

    #[Test]
    public function anEmptyResultDoesNotFeedANullRowToATypedGroupingClosure(): void
    {
        // Every closure in the README is written as `fn (array $row)`; on an empty result,
        // handing the strategy a made-up `null` row turned straight into a TypeError.
        $strategy = new GroupedSheets(static fn (array $row): string => (string) $row['g']);

        $path = $this->dir->file('empty-group.xlsx');
        $result = $this->tabula()->export(Schema::make('n')->fields(Field::string('code')->label('col.code')))
            ->from(ArraySource::of([]))
            ->sheets($strategy)
            ->locale('tr')->to(Format::Xlsx)->write($path);

        self::assertSame(1, $result->sheets);
        self::assertFileExists($path);
    }

    #[Test]
    public function anEmptyTextPlaceholderIsActuallyWrittenToTheCell(): void
    {
        $settings = new TabulaSettings(emptyText: '-');

        $path = $this->dir->file('placeholder.xlsx');
        $this->tabula($settings)->export(
            Schema::make('n')->fields(
                Field::string('code')->label('col.code'),
                Field::quantity('qty')->label('col.qty'),
            ),
        )
            ->from(ArraySource::of([['code' => null, 'qty' => null]]))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        $sheet = IOFactory::load($path)->getActiveSheet();
        self::assertSame('-', $sheet->getCell('A2')->getValue());
        self::assertSame('-', $sheet->getCell('B2')->getValue());
    }

    #[Test]
    public function aSchemaLeftWithNoColumnsForTheFormatFailsLoudly(): void
    {
        $schema = Schema::make('n')->fields(
            Field::string('code')->label('col.code')->only(Format::Pdf),
        );

        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/no columns left/');

        $this->tabula()->export($schema)
            ->from(ArraySource::of([['code' => 'x']]))
            ->locale('tr')->to(Format::Xlsx)->write($this->dir->file('no-columns.xlsx'));
    }

    #[Test]
    public function aSourceThatThrowsMidExportStillReleasesTheWriter(): void
    {
        $writer = new CsvWriter();
        $path = $this->dir->file('partial.csv');

        $exploding = IteratorSource::of(static function (): Generator {
            yield ['code' => 'a'];

            throw new RuntimeException('the source blew up');
        });

        try {
            $this->tabula()->export(Schema::make('n')->fields(Field::string('code')->label('col.code')))
                ->from($exploding)
                ->writer($writer)
                ->locale('tr')->to(Format::Csv)->write($path);

            self::fail('The source exception should have propagated.');
        } catch (RuntimeException $e) {
            self::assertSame('the source blew up', $e->getMessage());
        }

        // The writer must have been released: if the same instance can be opened again, the
        // handle was closed. Had it not been, the file handle would leak, the writer would
        // refuse with "already open", and the writer the user handed in would be unusable
        // from then on.
        $reopenFailed = false;

        try {
            $writer->open($this->dir->file('reopened.csv'));
        } catch (Throwable) {
            $reopenFailed = true;
        } finally {
            $writer->close();
        }

        self::assertFalse($reopenFailed, 'The writer was not released: the second open() was refused.');
    }

    #[Test]
    public function aDateInAStringFieldDoesNotKillTheExport(): void
    {
        // A schema mistake should show up as an empty/neutral cell, not as a crashing export.
        $path = $this->dir->file('date-text.csv');

        $result = $this->tabula()->export(
            Schema::make('n')->fields(Field::string('whenever')->label('col.created')),
        )
            ->from(ArraySource::of([['whenever' => new DateTimeImmutable('2024-03-01 08:00:00')]]))
            ->locale('tr')->to(Format::Csv)->write($path);

        self::assertSame(1, $result->rows);
        self::assertStringContainsString('2024-03-01', (string) file_get_contents($path));
    }

    #[Test]
    public function theCellIsBuiltIndependentlyOfTheChosenWriter(): void
    {
        // `to()` and `writer()` can contradict each other (both are public API). If the cell is
        // not built independently of the format, dates land in Excel as TEXT and sort alphabetically.
        $path = $this->dir->file('crossed.xlsx');

        $this->tabula()->export(
            Schema::make('n')->fields(Field::date('createdAt')->label('col.created')),
        )
            ->from(ArraySource::of([['createdAt' => new DateTimeImmutable('2024-01-05')]]))
            ->to(Format::Csv)          // the format says CSV…
            ->writer(new XlsxWriter()) // …but the writer is Xlsx
            ->locale('tr')
            ->write($path);

        $cell = IOFactory::load($path)->getActiveSheet()->getCell('A2');

        self::assertIsFloat($cell->getValue());
        self::assertEqualsWithDelta(45296.0, $cell->getValue(), 0.5);
    }
}
