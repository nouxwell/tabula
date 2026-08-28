<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Export\Writer;

use Nouxwell\Tabula\Export\Writer\CsvOptions;
use Nouxwell\Tabula\Export\Writer\DefaultWriterFactory;
use Nouxwell\Tabula\Export\Writer\XlsxOptions;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Source\ArraySource;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Proves that the options objects REALLY show up in the file.
 *
 * Every one of these tests was written by running a real export and looking at the PRODUCED
 * FILE, not by reading the value object's fields back. The reason: the `CsvOptions`/`XlsxOptions`
 * refactor was done precisely to close the "the setting exists but reaches nowhere" bug. A test
 * that verifies the `$options->boldHeader` field returns `true` would stay green on a writer that
 * forgot to wire that field to `setBold()` — that is, it would never see the bug at all. The
 * bytes of the file and the sheet PhpSpreadsheet reads back cannot lie.
 */
#[CoversClass(CsvOptions::class)]
#[CoversClass(XlsxOptions::class)]
final class WriterOptionsTest extends TestCase
{
    /**
     * A backslash standing IMMEDIATELY BEFORE a quote is the only sequence that triggers PHP's
     * non-standard escaping; with the setting on and with it off, different bytes land in the file.
     */
    private const string TRICKY = 'A\\"B';

    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    // ---------------------------------------------------------------- CSV: delimiter and BOM

    #[Test]
    public function rfc4180OptionsProduceACommaSeparatedFileWithNoBom(): void
    {
        $path = $this->writeCsv(CsvOptions::rfc4180(), 'feed.csv');
        $bytes = (string) file_get_contents($path);

        // A machine consumer takes the BOM for data: the name of the first column becomes
        // "\xEF\xBB\xBFKod" and the column mapping slips on the very first line.
        self::assertStringStartsNotWith("\xEF\xBB\xBF", $bytes);
        self::assertStringStartsWith("Kod,Ünvan\r\n", $bytes);
        self::assertStringNotContainsString(';', $bytes);
    }

    #[Test]
    public function theExcelPresetProducesSemicolonsABomAndUnbrokenTurkishCharacters(): void
    {
        $path = $this->writeCsv(CsvOptions::excel(), 'customers.csv');
        $bytes = (string) file_get_contents($path);

        // Without a BOM, Excel takes the file for cp1254 and ş/ğ/ı/İ/ö/ç/ü are already mangled in
        // the header row.
        self::assertStringStartsWith("\xEF\xBB\xBFKod;Ünvan\r\n", $bytes);

        // The Turkish letters must survive as UTF-8, byte for byte.
        self::assertStringContainsString('Çiğdem Şahin Ltd. Şti.', $bytes);
        self::assertStringContainsString('Öz Güneş A.Ş.', $bytes);
    }

    /**
     * `excel()` is not "an option", it IS the default itself.
     *
     * If a single byte differs between the named constructor and giving no options at all, the
     * default has silently drifted; both the documentation and the call sites then become wrong.
     */
    #[Test]
    public function theExcelPresetIsByteForByteWhatYouGetWithNoOptionsAtAll(): void
    {
        $named = $this->writeCsv(CsvOptions::excel(), 'adli.csv');
        $implicit = $this->writeCsv(new CsvOptions(), 'ortulu.csv');

        self::assertSame(file_get_contents($named), file_get_contents($implicit));
    }

    // ---------------------------------------------------------------- CSV: line ending

    #[Test]
    public function theLineEndingIsWhicheverTheOptionsAskFor(): void
    {
        $lf = (string) file_get_contents(
            $this->writeCsv(new CsvOptions(lineEnding: "\n"), 'unix.csv'),
        );

        // Not a single carriage return may be left: if the file is going over to the Unix side,
        // "\r" sticks to the VALUE of the last column and comparisons silently stop matching.
        self::assertStringNotContainsString("\r", $lf);
        self::assertStringContainsString("Kod;Ünvan\n", $lf);

        $crlf = (string) file_get_contents(
            $this->writeCsv(new CsvOptions(), 'windows.csv'),
        );

        // The default is CRLF: the line ending RFC 4180 requires and Windows Excel expects.
        self::assertStringContainsString("Kod;Ünvan\r\n", $crlf);
    }

    // ---------------------------------------------------------------- CSV: enclosure

    #[Test]
    public function theEnclosureIsWhicheverTheOptionsAskFor(): void
    {
        // The value carries the delimiter, so the field MUST be enclosed — which means the
        // enclosure shows up in the bytes as well.
        $path = $this->writeOneValue(
            new CsvOptions(enclosure: "'", writeBom: false),
            'sarmalayici.csv',
            'Ege; Marmara',
        );

        self::assertStringContainsString("'Ege; Marmara'", (string) file_get_contents($path));
    }

    // ---------------------------------------------------------------- CSV: escaping

    /**
     * This test's quiet second job: since PHP 8.4, calling `fputcsv` without the `$escape`
     * argument raises a "deprecated" notice. Because the suite runs with
     * `failOnDeprecation="true"`, this test (and the one below) turns red by itself the moment
     * the writer stops passing that argument; no separate warning catcher is needed.
     */
    #[Test]
    public function turningTheEscapeOffMakesTheFieldSurviveAStrictRfc4180Reader(): void
    {
        $path = $this->writeOneValue(CsvOptions::rfc4180(), 'kacissiz.csv', self::TRICKY);
        $bytes = (string) file_get_contents($path);

        // The quote is DOUBLED — that is the only escaping RFC 4180 recognises.
        self::assertStringContainsString('"A\\""B"', $bytes);
        // Not a trace of PHP's backslash escaping may be left.
        self::assertStringNotContainsString('"A\\"B"', $bytes);

        // The real proof: the parser on the other side reads the value back EXACTLY as it was.
        self::assertSame([self::TRICKY], self::readStrictly($path)[1]);
    }

    /**
     * The counter-proof: what makes the test above pass is the `escape: ''` setting, not chance.
     *
     * With PHP's escaping on, the file becomes `"A\"B"`; that is an unfolded quote in the middle
     * of a quoted field, and to an RFC 4180 parser it is corrupt data. Nothing blows up, the
     * value silently turns into something else — the most expensive kind of bug there is.
     */
    #[Test]
    public function thePhpStyleEscapeSilentlyCorruptsTheFieldForAStrictReader(): void
    {
        $path = $this->writeOneValue(
            new CsvOptions(delimiter: ',', escape: '\\', writeBom: false),
            'kacisli.csv',
            self::TRICKY,
        );

        self::assertStringContainsString('"A\\"B"', (string) file_get_contents($path));
        self::assertNotSame([self::TRICKY], self::readStrictly($path)[1]);
    }

    // ---------------------------------------------------------------- XLSX: the decoration switches

    #[Test]
    public function plainXlsxOptionsLeaveOutTheFreezePaneTheAutoFilterAndTheBoldHeader(): void
    {
        $sheet = $this->writeXlsx(XlsxOptions::plain(), 'plain.xlsx')->getActiveSheet();

        self::assertNull($sheet->getFreezePane(), 'plain() must not leave a frozen row behind.');
        self::assertSame('', $sheet->getAutoFilter()->getRange(), 'plain() must not set up a filter range.');
        self::assertNotTrue($sheet->getStyle('A1')->getFont()->getBold(), 'plain() must not embolden the header.');
    }

    /**
     * The proof that those same three things are ON by default.
     *
     * Without this counterpart, the test above would stay green on a writer that never applies
     * the three features at all (that is, never reads the setting either) — the very definition
     * of a dead switch.
     */
    #[Test]
    public function theDefaultXlsxOptionsGiveAllThreeBack(): void
    {
        $sheet = $this->writeXlsx(new XlsxOptions(), 'suslu.xlsx')->getActiveSheet();

        self::assertSame('A2', $sheet->getFreezePane(), 'The header row must stay frozen by default.');
        // The range has to cover the header + BOTH data rows; the old engine used a fixed range
        // and on long lists left the last rows outside the filter.
        self::assertSame('A1:B3', $sheet->getAutoFilter()->getRange());
        self::assertTrue($sheet->getStyle('A1')->getFont()->getBold());
    }

    // ---------------------------------------------------------------- XLSX: creator and colours

    #[Test]
    public function theCreatorAndTheHeaderColoursLandInTheWorkbook(): void
    {
        $options = new XlsxOptions(
            creator: 'Ionsis ERP',
            headerFill: 'FF102030',
            requiredHeaderFill: 'FF9900AA',
            headerBorderColor: 'FF00FF00',
        );

        $book = $this->writeXlsx($options, 'renkli.xlsx');
        $sheet = $book->getActiveSheet();

        self::assertSame('Ionsis ERP', $book->getProperties()->getCreator());

        // B = 'name', NOT required → the general header fill.
        self::assertSame('FF102030', $sheet->getStyle('B1')->getFill()->getStartColor()->getARGB());
        // A = 'code', required → its own fill overrides the general one.
        self::assertSame('FF9900AA', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());

        self::assertSame('FF00FF00', $sheet->getStyle('A1')->getBorders()->getBottom()->getColor()->getARGB());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Writer options are passed ONLY through the factory.
     *
     * Saying `->writer(new CsvWriter($options))` would make the test easier but would skip the
     * real call path: in an application the setting travels from the configuration down to the
     * factory, and from there to the writer.
     */
    private function tabula(?CsvOptions $csv = null, ?XlsxOptions $xlsx = null): Tabula
    {
        return new Tabula(
            new ArrayTranslator(['tr' => ['col.code' => 'Kod', 'col.name' => 'Ünvan']]),
            writers: new DefaultWriterFactory($csv ?? new CsvOptions(), $xlsx ?? new XlsxOptions()),
        );
    }

    /** The first column is REQUIRED, so that we can see the required-header fill is a separate setting. */
    private function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('col.code')->required(),
            Field::string('name')->label('col.name'),
        );
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        return [
            ['code' => '0042', 'name' => 'Çiğdem Şahin Ltd. Şti.'],
            ['code' => '0043', 'name' => 'Öz Güneş A.Ş.'],
        ];
    }

    private function writeCsv(CsvOptions $options, string $name): string
    {
        $path = $this->dir->file($name);

        $this->tabula(csv: $options)->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')
            ->to(Format::Csv)
            ->write($path);

        return $path;
    }

    private function writeXlsx(XlsxOptions $options, string $name): Spreadsheet
    {
        $path = $this->dir->file($name);

        $this->tabula(xlsx: $options)->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')
            ->to(Format::Xlsx)
            ->write($path);

        return IOFactory::load($path);
    }

    /** A one-column, one-row file: nothing else may interfere with the setting under examination. */
    private function writeOneValue(CsvOptions $options, string $name, string $value): string
    {
        $path = $this->dir->file($name);

        $this->tabula(csv: $options)->export(
            Schema::make('n')->fields(Field::string('name')->label('col.name')),
        )
            ->from(ArraySource::of([['name' => $value]]))
            ->locale('tr')
            ->to(Format::Csv)
            ->write($path);

        return $path;
    }

    /**
     * Reads the file by RFC 4180's rules (escaping OFF) — that is, the way the machine on the
     * other side sees it. Reading it with the escaping left on would let PHP compensate for its
     * own mistake, and the test would prove nothing.
     *
     * @return list<list<string|null>>
     */
    private static function readStrictly(string $path): array
    {
        $bytes = (string) file_get_contents($path);
        $rows = [];

        foreach (explode("\r\n", rtrim($bytes, "\r\n")) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }
}
