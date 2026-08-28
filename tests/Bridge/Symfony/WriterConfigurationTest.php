<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Bridge\Symfony;

use InvalidArgumentException;
use Nouxwell\Tabula\Bridge\Symfony\SettingsFactory;
use Nouxwell\Tabula\Bridge\Symfony\TabulaBundle;
use Nouxwell\Tabula\Exception\WriterException;
use Nouxwell\Tabula\Export\Page\Page;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Source\ArraySource;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\PdfDocument;
use Nouxwell\Tabula\Tests\Fixture\StubSymfonyTranslator;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Verifies that the writer settings travel all the way from `tabula.yaml` TO THE WRITTEN FILE.
 *
 * Seeing a setting reach the value object is not enough: the reason this refactor exists was a
 * dead setting (`max_rows_per_sheet`) that was validated and carried around but that NOBODY
 * READ. That is why the assertions here write a real file with the `Tabula` that comes out of
 * the container and then look at the bytes / at the workbook.
 */
final class WriterConfigurationTest extends TestCase
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

    /**
     * @param array<string, mixed> $config
     */
    private function tabula(array $config = []): Tabula
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.default_locale', 'tr');
        $container->set(TranslatorInterface::class, new StubSymfonyTranslator([]));

        $bundle = new TabulaBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        $container->getDefinition(Tabula::class)->setPublic(true);
        $container->compile();

        $tabula = $container->get(Tabula::class);
        self::assertInstanceOf(Tabula::class, $tabula);

        return $tabula;
    }

    private function schema(): Schema
    {
        return Schema::make('t')->fields(
            Field::string('code')->label('Kod')->required(),
            Field::string('name')->label('Ünvan'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function write(array $config, Format $format, string $file): string
    {
        $path = $this->dir->file($file);

        $this->tabula($config)->export($this->schema())
            ->from(ArraySource::of([['code' => 'A1', 'name' => 'Çiğdem Şahin']]))
            ->locale('tr')
            ->to($format)
            ->write($path);

        return $path;
    }

    /**
     * A separate writing path for the PDF: six columns are needed for the paper budget to become visible.
     *
     * @param array<string, mixed> $config
     */
    private function writePdf(array $config, string $file): string
    {
        $path = $this->dir->file($file);

        $schema = Schema::make('t')->fields(
            Field::string('code')->label('Kod'),
            Field::string('name')->label('Ünvan'),
            Field::string('city')->label('Şehir'),
            Field::string('phone')->label('Telefon'),
            Field::string('email')->label('E-posta'),
            Field::string('note')->label('Not'),
        );

        $this->tabula($config)->export($schema)
            ->from(ArraySource::of([[
                'code' => 'A1',
                'name' => 'Çiğdem Şahin',
                'city' => 'İstanbul',
                'phone' => '0212 000 00 00',
                'email' => 'info@ornek.test',
                'note' => 'Şubat ayında görüşülecek',
            ]]))
            ->locale('tr')
            ->to(Format::Pdf)
            ->write($path);

        return $path;
    }

    // ---------------------------------------------------------------- CSV

    #[Test]
    public function csvDefaultsProduceASemicolonFileWithABom(): void
    {
        $contents = (string) file_get_contents($this->write([], Format::Csv, 'default.csv'));

        self::assertStringStartsWith("\xEF\xBB\xBF", $contents);
        self::assertStringContainsString('Kod;Ünvan', $contents);
        self::assertStringContainsString("\r\n", $contents);
    }

    #[Test]
    public function csvConfigurationReachesTheWrittenFile(): void
    {
        // The feed that goes to a machine: RFC 4180 — comma, no BOM, LF.
        $config = ['csv' => [
            'delimiter' => ',',
            'escape' => '',
            'write_bom' => false,
            'line_ending' => 'lf',
        ]];

        $contents = (string) file_get_contents($this->write($config, Format::Csv, 'makine.csv'));

        self::assertStringStartsNotWith("\xEF\xBB\xBF", $contents);
        self::assertStringContainsString('Kod,Ünvan', $contents);
        self::assertStringNotContainsString("\r", $contents);
    }

    #[Test]
    public function anEscapeWrittenAsNullIsReadAsDisabledRatherThanCrashing(): void
    {
        // Whoever writes `escape: ~` means "turn escaping off"; a raw null was a TypeError.
        $contents = (string) file_get_contents(
            $this->write(['csv' => ['escape' => null]], Format::Csv, 'kacissiz.csv'),
        );

        self::assertStringContainsString('Kod;Ünvan', $contents);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCsvCharacters(): iterable
    {
        // The most frequent trap: in single-quoted YAML, '\\' is TWO characters.
        yield 'two-character escape' => [['escape' => '\\\\']];
        yield 'two-character delimiter' => [['delimiter' => '||']];
        yield 'multi-byte delimiter' => [['delimiter' => 'ş']];
    }

    /**
     * @param array<string, mixed> $csv
     */
    #[Test]
    #[DataProvider('invalidCsvCharacters')]
    public function anInvalidCsvCharacterFailsAtSetupNotHalfwayThroughTheFile(array $csv): void
    {
        // If it blew up at writing time, the error would be a raw ValueError (NOT a
        // TabulaException) and it would leave a remnant on disk containing nothing but the BOM.
        $this->expectException(WriterException::class);

        $this->write(['csv' => $csv], Format::Csv, 'olmaz.csv');
    }

    // ---------------------------------------------------------------- XLSX

    #[Test]
    public function xlsxDefaultsFreezeTheHeaderAndAddAnAutoFilter(): void
    {
        $sheet = IOFactory::load($this->write([], Format::Xlsx, 'default.xlsx'))->getActiveSheet();

        self::assertSame('A2', $sheet->getFreezePane());
        self::assertNotSame('', $sheet->getAutoFilter()->getRange());
        self::assertTrue($sheet->getStyle('A1')->getFont()->getBold());
    }

    #[Test]
    public function xlsxConfigurationCanTurnTheDecorationOff(): void
    {
        $config = ['xlsx' => [
            'creator' => 'Ionsis ERP',
            'bold_header' => false,
            'freeze_header' => false,
            'auto_filter' => false,
        ]];

        $spreadsheet = IOFactory::load($this->write($config, Format::Xlsx, 'plain.xlsx'));
        $sheet = $spreadsheet->getActiveSheet();

        self::assertNull($sheet->getFreezePane());
        self::assertSame('', (string) $sheet->getAutoFilter()->getRange());
        self::assertFalse($sheet->getStyle('A1')->getFont()->getBold());
        self::assertSame('Ionsis ERP', $spreadsheet->getProperties()->getCreator());

        // Even with the decoration switched off, the column alignment must be applied:
        // had the alignment stayed INSIDE the auto-filter condition, it would have dropped
        // silently.
        self::assertNotSame('', (string) $sheet->getStyle('A2')->getAlignment()->getHorizontal());
    }

    #[Test]
    public function customHeaderColoursReachTheWorkbookAndRequiredStillWins(): void
    {
        $path = $this->write(
            ['xlsx' => ['header_fill' => 'FFDDEEFF', 'required_header_fill' => 'FFAA0000']],
            Format::Xlsx,
            'renk.xlsx',
        );
        $sheet = IOFactory::load($path)->getActiveSheet();

        // A = `code`, a REQUIRED field → the required colour overrides the general header colour.
        self::assertSame('FFAA0000', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());
        // B = `name`, not required → the general header colour.
        self::assertSame('FFDDEEFF', $sheet->getStyle('B1')->getFill()->getStartColor()->getARGB());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidColours(): iterable
    {
        // PhpSpreadsheet SILENTLY swallows a colour it cannot parse: a '#...' leaves the header
        // pure white, while an empty string prints a jet-black band. Neither may be accepted.
        yield 'css hash' => ['#F2F2F2'];
        yield 'colour name' => ['lightgray'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('invalidColours')]
    public function anInvalidColourIsRejectedInsteadOfSilentlyPaintingSomethingElse(string $colour): void
    {
        // The empty string is rejected in the config tree (cannotBeEmpty), the others in the
        // value object; both must blow up at set-up time and loudly.
        $this->expectException(Throwable::class);

        $this->write(['xlsx' => ['header_fill' => $colour]], Format::Xlsx, 'kotu-renk.xlsx');
    }

    // ---------------------------------------------------------------- PDF

    /**
     * The default A4 must be LANDSCAPE.
     *
     * The system this replaces printed every list on A4 portrait; the last columns of a
     * ten-column invoice list fell off the edge of the paper, and the user only noticed what
     * was missing by comparing it against the Excel output. Landscape paper takes the usable
     * width from 190 to 277 mm.
     */
    #[Test]
    public function pdfDefaultsProduceAnA4LandscapePage(): void
    {
        PdfDocument::at($this->writePdf([], 'default.pdf'))->assertPaper(Page::a4()->landscape());
    }

    #[Test]
    public function pdfConfigurationReachesTheWrittenFile(): void
    {
        $config = ['pdf' => ['page_size' => 'a5', 'orientation' => 'portrait']];

        PdfDocument::at($this->writePdf($config, 'a5.pdf'))->assertPaper(Page::a5());
    }

    /**
     * The column budget must come from the configuration as well.
     *
     * Six columns, at most two columns per page → three page sets, that is, three sheets of
     * paper. Had `max_columns` not been carried, three columns would have fitted on the A5 and
     * two sheets would have come out.
     */
    #[Test]
    public function thePdfColumnBudgetFromConfigurationReachesTheWrittenFile(): void
    {
        $config = ['pdf' => [
            'page_size' => 'a5',
            'orientation' => 'portrait',
            'min_column_width_mm' => 40.0,
            'max_columns' => 2,
        ]];

        PdfDocument::at($this->writePdf($config, 'split.pdf'))->assertPageCount(3);
    }

    /**
     * `max_columns: ~` means "no cap", not a reason to crash.
     *
     * Exactly the same trap as `csv.escape`: Symfony's `IntegerNode` rejects null with
     * "Expected int, but got null", whereas writing `~` is the only way to take back an
     * inherited value. That is why the node is not an `integerNode` but a `scalarNode` with
     * validation.
     */
    #[Test]
    public function aMaxColumnsWrittenAsNullIsReadAsNoCapRatherThanCrashing(): void
    {
        $config = ['pdf' => ['page_size' => 'a5', 'orientation' => 'portrait', 'max_columns' => null]];

        // On A5 portrait, 128 mm / 22 mm = 5 columns; six columns fall into two groups (5 + 1).
        PdfDocument::at($this->writePdf($config, 'tavansiz.pdf'))->assertPageCount(2);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPdfConfigurations(): iterable
    {
        yield 'unknown paper size' => [['page_size' => 'a2'], 'tabula.pdf.page_size'];
        yield 'unknown orientation' => [['orientation' => 'sideways'], 'tabula.pdf.orientation'];
        yield 'unknown overflow' => [['overflow' => 'wrap'], 'tabula.pdf.overflow'];
        // If the font family is left empty, Dompdf falls back to a Latin-1 core font and the
        // letters "ş ğ ı İ" are SILENTLY not printed.
        yield 'empty font family' => [['font_family' => ''], 'tabula.pdf.font_family'];
        yield 'null font family' => [['font_family' => null], 'tabula.pdf.font_family'];
        yield 'zero column cap' => [['max_columns' => 0], 'tabula.pdf.max_columns'];
        yield 'textual column cap' => [['max_columns' => 'all-of-them'], 'tabula.pdf.max_columns'];
    }

    /**
     * @param array<string, mixed> $pdf
     */
    #[Test]
    #[DataProvider('invalidPdfConfigurations')]
    public function theConfigurationTreeRejectsBadPdfValues(array $pdf, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($path, '/').'/');

        $this->tabula(['pdf' => $pdf]);
    }

    /**
     * The range rule lives not in the tree but IN THE VALUE OBJECT (see the note on the `pdf` node).
     *
     * But where it lives it really must work: a font size of zero does not make Dompdf throw an
     * exception, it computes a row height of zero and reduces the table to an invisible strip —
     * which comes back to the user as an "empty PDF" whose cause cannot be worked out by
     * looking at the output.
     */
    #[Test]
    public function aNonPositiveFontSizeIsRejectedByTheValueObject(): void
    {
        $this->expectException(WriterException::class);

        $this->writePdf(['pdf' => ['font_size_pt' => 0.0]], 'gorunmez.pdf');
    }

    // ---------------------------------------------------------------- config tree

    #[Test]
    public function anUnknownLineEndingNameIsRejectedByTheConfigTree(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->tabula(['csv' => ['line_ending' => 'LF']]);
    }

    #[Test]
    public function theFactoryRefusesAnUnknownLineEndingNameInsteadOfFallingBackToCrlf(): void
    {
        // `SettingsFactory` is a public static API; if a new name is added to the config tree
        // tomorrow, a ternary operator would silently drop it to CRLF.
        $this->expectException(InvalidArgumentException::class);

        SettingsFactory::csv([
            'delimiter' => ';',
            'enclosure' => '"',
            'escape' => '\\',
            'write_bom' => true,
            'line_ending' => 'bilinmeyen',
        ]);
    }

    #[Test]
    public function theLineEndingNamesMapToTheRightBytes(): void
    {
        $base = ['delimiter' => ';', 'enclosure' => '"', 'escape' => '\\', 'write_bom' => true];

        self::assertSame("\n", SettingsFactory::csv([...$base, 'line_ending' => 'lf'])->lineEnding);
        self::assertSame("\r\n", SettingsFactory::csv([...$base, 'line_ending' => 'crlf'])->lineEnding);
    }
}
