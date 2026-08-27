<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Import\Reader;

use Balin\Tabula\Exception\ImportException;
use Balin\Tabula\Import\Reader\CsvReader;
use Balin\Tabula\Import\Reader\Reader;
use Balin\Tabula\Import\Reader\ReaderRegistry;
use Balin\Tabula\Import\Reader\XlsxReader;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetXlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reader tests.
 *
 * The readers are the quietest breaking point of an import: a file that is read wrongly does
 * not raise an error, it produces WRONG DATA. Every test here corresponds to a scenario that
 * "breaks without throwing a single exception" — shifted columns, a swallowed BOM, rows
 * collapsing into one single cell.
 */
final class ReadersTest extends TestCase
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

    /** @return array<int, list<mixed>> row number => cells */
    private function collect(Reader $reader, string $path): array
    {
        $rows = [];

        foreach ($reader->rows($path) as $number => $cells) {
            $rows[$number] = $cells;
        }

        return $rows;
    }

    private function writeCsv(string $name, string $contents): string
    {
        $path = $this->dir->file($name);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Produces a real xlsx.
     *
     * Strings are written with an EXPLICIT type: the default binder takes "0042" for a number
     * and turns it into 42, which would break the very thing the test means to measure before
     * it ever got measured. A cell given `null` is NEVER created — let there really be a gap
     * in the file.
     *
     * @param list<list<mixed>> $rows
     */
    private function writeXlsx(string $name, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $cells) {
            foreach ($cells as $columnIndex => $value) {
                if (null === $value) {
                    continue;
                }

                $coordinate = [$columnIndex + 1, $rowIndex + 1];

                if (is_string($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
        }

        $path = $this->dir->file($name);
        (new SpreadsheetXlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    // ---------------------------------------------------------------- CSV

    #[Test]
    public function itReadsASemicolonFileWithOneBasedRowNumbers(): void
    {
        $path = $this->writeCsv(
            'semicolon.csv',
            "code;name\n0042;Çiğdem Şahin Ltd. Şti.\n0043;Öz Güneş A.Ş.\n",
        );

        $rows = $this->collect(new CsvReader(), $path);

        // When an error message says "row 37", the user must be able to look at row 37 in Excel.
        self::assertSame([1, 2, 3], array_keys($rows), 'Row numbers must be 1-based.');
        self::assertSame(['code', 'name'], $rows[1]);
        self::assertSame(['0042', 'Çiğdem Şahin Ltd. Şti.'], $rows[2], 'Turkish text and the leading zero must be preserved.');
        self::assertSame(['0043', 'Öz Güneş A.Ş.'], $rows[3]);
    }

    #[Test]
    public function itSniffsACommaFile(): void
    {
        // Our own writer emits ';', but the file the user obtained from another system may use
        // ','. Hard-coding the delimiter collapses the row into a SINGLE cell and the file is
        // silently rejected.
        $path = $this->writeCsv('comma.csv', "code,name\n0042,\"Ankara, Çankaya\"\n");

        $rows = $this->collect(new CsvReader(), $path);

        self::assertSame(['code', 'name'], $rows[1]);
        self::assertSame(['0042', 'Ankara, Çankaya'], $rows[2]);
    }

    #[Test]
    public function theDelimiterSniffIgnoresCommasInsideQuotes(): void
    {
        // A single quoted header cell is enough to make us take the file for a comma-separated
        // one, had the counting not been done outside the quotes, and the whole column mapping
        // would break.
        $path = $this->writeCsv('quoted.csv', "code;\"Ad, Unvan\"\n0042;Test\n");

        $rows = $this->collect(new CsvReader(), $path);

        self::assertSame(['code', 'Ad, Unvan'], $rows[1]);
        self::assertSame(['0042', 'Test'], $rows[2]);
    }

    #[Test]
    public function itStripsTheUtf8ByteOrderMark(): void
    {
        // Our own writer emits a BOM (so that Excel does not take the file for cp1254). If it
        // is not dropped, the first cell starts with three invisible bytes, lands on no schema
        // key at all, and the file is rejected with "no column recognised" — and on top of
        // that the header looks CORRECT on screen.
        $path = $this->writeCsv('bom.csv', "\xEF\xBB\xBFcode;name\n0042;Çiğdem\n");

        $rows = $this->collect(new CsvReader(), $path);

        self::assertSame('code', $rows[1][0], 'The BOM must not stick to the first cell.');
        self::assertSame(['0042', 'Çiğdem'], $rows[2]);
    }

    /**
     * Since PHP 8.4, relying on `fgetcsv()`'s escape argument produces a "deprecated" notice.
     *
     * The test suite already runs with `failOnDeprecation`, but that setting can be turned off
     * silently; here the notice is caught EXPLICITLY.
     */
    #[Test]
    public function readingEmitsNoDeprecation(): void
    {
        $path = $this->writeCsv('plain-text.csv', "code;name\n0042;Test\n");

        /** @var list<string> $deprecations */
        $deprecations = [];

        set_error_handler(
            static function (int $severity, string $message) use (&$deprecations): bool {
                $deprecations[] = $message;

                return true;
            },
            E_DEPRECATED | E_USER_DEPRECATED,
        );

        try {
            $this->collect(new CsvReader(), $path);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
    }

    // ---------------------------------------------------------------- XLSX

    /**
     * ★ THE MOST IMPORTANT TEST IN THIS FILE.
     *
     * If an empty cell is skipped, EVERY column after it shifts one to the left and the whole
     * row is mapped onto the wrong fields: the value in the "Quantity" column gets written
     * into the "Name" field, and without producing a single error at that. This is the most
     * insidious way in which an import silently writes wrong data.
     */
    #[Test]
    public function aBlankMiddleCellKeepsEveryLaterColumnInItsOwnPosition(): void
    {
        $path = $this->writeXlsx('placeholder.xlsx', [
            ['code', 'name', 'qty'],
            ['0042', null, 5],
        ]);

        $rows = $this->collect(new XlsxReader(), $path);

        self::assertCount(3, $rows[2], 'An empty cell must hold its COLUMN POSITION; if the row gets shorter, the mapping shifts.');
        self::assertSame('0042', $rows[2][0]);
        self::assertNull($rows[2][1], 'A blank middle cell must be null, not ignored.');
        self::assertEqualsWithDelta(
            5,
            $rows[2][2],
            0.0001,
            'The third value must still be at index 2 — a shift breaks the whole field mapping.',
        );
    }

    #[Test]
    public function itReadsTheFirstSheetAndPreservesTurkishText(): void
    {
        $path = $this->writeXlsx('turkish.xlsx', [
            ['code', 'name'],
            ['0042', 'Çiğdem Şahin Ltd. Şti.'],
            ['0043', 'Öz Güneş A.Ş.'],
        ]);

        $rows = $this->collect(new XlsxReader(), $path);

        self::assertSame([1, 2, 3], array_keys($rows));
        self::assertSame(['0042', 'Çiğdem Şahin Ltd. Şti.'], $rows[2]);
    }

    /**
     * A date cell arrives as a SERIAL NUMBER, not as a string.
     *
     * The reader carries the raw value; the decision to convert it to a date belongs to the
     * parser, which knows the field's type. Had the cell been turned into a string here (which
     * is what the system this replaces did), "45296" would turn into a meaningless text and
     * the date could never be recovered.
     */
    #[Test]
    public function cellsAreNotStringified(): void
    {
        $path = $this->writeXlsx('tipler.xlsx', [
            ['date', 'qty'],
            [45296, 12.5],
        ]);

        $rows = $this->collect(new XlsxReader(), $path);

        self::assertIsNumeric($rows[2][0]);
        self::assertEqualsWithDelta(45296, $rows[2][0], 0.0001);
        self::assertIsFloat($rows[2][1]);
    }

    // ---------------------------------------------------------------- common

    /** @return iterable<string, array{Reader, string}> */
    public static function readersWithMissingFiles(): iterable
    {
        yield 'csv' => [new CsvReader(), 'missing.csv'];
        yield 'xlsx' => [new XlsxReader(), 'missing.xlsx'];
    }

    #[Test]
    #[DataProvider('readersWithMissingFiles')]
    public function aMissingFileIsRejectedByBothReaders(Reader $reader, string $name): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('cannot be read');

        // A generator's body only runs once it is consumed; calling `rows()` alone is not enough.
        foreach ($reader->rows($this->dir->file($name)) as $cells) {
            self::fail(sprintf('A row arrived from a file that does not exist: %s', var_export($cells, true)));
        }
    }

    #[Test]
    public function theRegistryPicksTheReaderByExtension(): void
    {
        $registry = ReaderRegistry::default();

        // The choice is made BY EXTENSION, not by content: telling a CSV that was saved as
        // .xlsx "correct the extension" is better than silently accepting it and then saying
        // "no column matched".
        self::assertInstanceOf(CsvReader::class, $registry->for('/tmp/customers.csv'));
        self::assertInstanceOf(XlsxReader::class, $registry->for('/tmp/customers.xlsx'));
        self::assertInstanceOf(XlsxReader::class, $registry->for('/tmp/MUSTERILER.XLSX'));
        self::assertInstanceOf(XlsxReader::class, $registry->for('/tmp/eski.xls'));

        self::assertFalse($registry->supports('/tmp/musteriler.txt'));
    }

    #[Test]
    public function anUnknownExtensionIsRefusedWithTheSupportedList(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('has an unsupported file type. Supported: .xlsx, .xls, .csv');

        ReaderRegistry::default()->for('/tmp/musteriler.txt');
    }
}
