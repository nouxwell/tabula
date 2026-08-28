<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Export\Writer;

use Nouxwell\Tabula\Exception\WriterException;
use Nouxwell\Tabula\Export\Column;
use Nouxwell\Tabula\Export\Page\ColumnBudget;
use Nouxwell\Tabula\Export\Page\Overflow;
use Nouxwell\Tabula\Export\Page\Page;
use Nouxwell\Tabula\Export\Writer\PdfOptions;
use Nouxwell\Tabula\Export\Writer\PdfWriter;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Schema\Align;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Schema\Priority;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Source\ArraySource;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use Nouxwell\Tabula\Value\Cell;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PDF writer — with the real Dompdf, writing real files.
 *
 * Working against a fake Dompdf would be useless here: most of what we want to verify (that the
 * paper really changed, that the Turkish letters do not turn into boxes, that the grouping
 * produces extra PAGES) is only visible in the produced document.
 *
 * Extracting text out of a PDF is out of scope; instead we look at the document's own structure:
 *  - `/MediaBox` → the paper's real dimensions in points,
 *  - `/BaseFont` → the embedded font family,
 *  - the number of `/Type /Page` entries → the page count.
 * These sit UNCOMPRESSED in Dompdf's output and reading them needs no PDF parser.
 */
#[CoversClass(PdfWriter::class)]
#[CoversClass(PdfOptions::class)]
final class PdfWriterTest extends TestCase
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

    // ---------------------------------------------------------------- the pipeline

    #[Test]
    public function aMinimalExportProducesARealPdfFile(): void
    {
        $path = $this->dir->file('customers.pdf');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')
            // No writer is handed in: `to(Format::Pdf)` on its own has to be enough, otherwise
            // the PDF path is not wired into the factory and nobody gets a PDF out of `Format::Pdf`.
            ->to(Format::Pdf)
            ->write($path);

        self::assertFileExists($path);
        self::assertSame($path, $result->path());
        self::assertSame(2, $result->rows);
        self::assertSame(1, $result->sheets);

        // The "%PDF-" signature is the first five bytes of the file; had Dompdf silently produced
        // an empty or half-written output (when the font cannot be resolved, say), the file would
        // exist but be invalid.
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
    }

    #[Test]
    public function anEmptyResultStillProducesAHeaderOnlyDocument(): void
    {
        // The user should see a table that says "no results", not an empty file — the same
        // promise the other two writers make.
        $path = $this->dir->file('empty.pdf');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of([]))
            ->locale('tr')
            ->to(Format::Pdf)
            ->write($path);

        self::assertTrue($result->isEmpty());
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        self::assertSame(1, self::pageCount($path));
    }

    // ---------------------------------------------------------------- ★ Turkish

    #[Test]
    public function turkishLettersGetAFontThatActuallyContainsThem(): void
    {
        $path = $this->dir->file('turkish.pdf');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of([['code' => '0042', 'name' => 'Çiğdem Şahin Öz', 'qty' => 1]]))
            ->locale('tr')
            ->to(Format::Pdf)
            ->write($path);

        $bytes = (string) file_get_contents($path);

        self::assertStringStartsWith('%PDF-', $bytes);
        // A document of tens of kilobytes means the font subset really was embedded. The same
        // document printed with a core font (Helvetica) would stay a few kilobytes.
        self::assertGreaterThan(5_000, strlen($bytes));

        // ★ This is the real guarantee. Dompdf's built-in "core" fonts are bound to Latin-1 and
        // do NOT contain the letters "ş ğ ı İ" at all; when the family cannot be resolved Dompdf
        // silently falls back to them and the letters turn into boxes. Seeing an embedded DejaVu
        // subset in the document means the `PdfOptions::BUNDLED_UNICODE_FONT` decision really
        // worked its way into the output.
        self::assertMatchesRegularExpression('#/BaseFont\s*/[A-Z]{6}\+DejaVuSans#', $bytes);
    }

    #[Test]
    public function theTurkishTextItselfEndsUpInsideTheDocument(): void
    {
        // "Extracting text" out of a PDF is out of scope, but Dompdf writes the text into the
        // content stream as UTF-16BE; inflating the stream and searching for those bytes needs no
        // full parser. This test deliberately touches Dompdf's output format: if it breaks, the
        // Dompdf version has changed and the Turkish output needs to be gone over again.
        $path = $this->dir->file('turkish-text.pdf');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of([['code' => '0042', 'name' => 'Çiğdem Şahin Öz', 'qty' => 1]]))
            ->locale('tr')
            ->to(Format::Pdf)
            ->write($path);

        $needle = (string) mb_convert_encoding('Çiğdem Şahin Öz', 'UTF-16BE', 'UTF-8');

        self::assertStringContainsString(
            $needle,
            self::readableBytes($path),
            'The Turkish cell text did not reach the document (or its encoding was mangled).',
        );
    }

    // ---------------------------------------------------------------- lifecycle

    #[Test]
    public function writingARowBeforeAnySheetIsRefused(): void
    {
        $writer = new PdfWriter();
        $writer->open($this->dir->file('unordered.pdf'));

        $this->expectException(WriterException::class);
        $this->expectExceptionMessageMatches('/There is no active sheet/');

        $writer->writeRow([Cell::text('x')]);
    }

    #[Test]
    public function startingASheetBeforeOpeningIsRefused(): void
    {
        // Were it silently accepted, the rows would pile up somewhere and the `close()` call
        // would have no target: the user waits for a file that was never written.
        $this->expectException(WriterException::class);
        $this->expectExceptionMessageMatches('/The writer is not open/');

        (new PdfWriter())->startSheet('Test', self::columns(1));
    }

    #[Test]
    public function openingTwiceIsRefused(): void
    {
        $writer = new PdfWriter();
        $writer->open($this->dir->file('one.pdf'));

        $this->expectException(WriterException::class);
        $this->expectExceptionMessageMatches('/already open/');

        $writer->open($this->dir->file('two.pdf'));
    }

    #[Test]
    public function finishingASheetThatWasNeverStartedIsRefused(): void
    {
        $writer = new PdfWriter();
        $writer->open($this->dir->file('closing.pdf'));

        $this->expectException(WriterException::class);

        $writer->finishSheet();
    }

    #[Test]
    public function closeCanBeCalledTwiceAndKeepsReportingTheSameFile(): void
    {
        // `ExportBuilder` calls `close()` inside a `finally`; if the second call blew up it would
        // mask the original error. The same promise the CSV/xlsx writers make.
        $path = $this->dir->file('twice.pdf');

        $writer = new PdfWriter();
        $writer->open($path);
        $writer->startSheet('Test', self::columns(2));
        $writer->writeRow(self::cells(2));

        self::assertSame([$path], $writer->close());
        self::assertSame([$path], $writer->close());
    }

    #[Test]
    public function aWriterIsReusableAfterItIsClosed(): void
    {
        $writer = new PdfWriter();

        $first = $this->drive($writer, 'first.pdf');
        $second = $this->drive($writer, 'second.pdf');

        self::assertFileExists($first);
        self::assertFileExists($second);
        // The first document's body must not carry over into the second: `open()` resets the
        // accumulated HTML.
        self::assertSame(self::pageCount($first), self::pageCount($second));
    }

    // ---------------------------------------------------------------- ★ does the paper really change

    #[Test]
    public function thePaperSizeReachesTheDocumentInsteadOfStayingInTheConfiguration(): void
    {
        // The regression for the most insidious bug of the system this replaces: when
        // `Dompdf::setPaper()` and the `@page` rule in the CSS contradicted each other, the CSS
        // silently won and the setting in PHP stayed decorative. Here we read the measurement
        // PRINTED into the document (`/MediaBox`, in points) and compare it with what `Page`
        // says — so that not one setting is left untranslated in between.
        $a5 = Page::a5();
        $a3 = Page::a3()->landscape();

        self::assertPaper($a5, $this->write(new PdfOptions(page: $a5), 'a5.pdf'));
        self::assertPaper($a3, $this->write(new PdfOptions(page: $a3), 'a3-landscape.pdf'));
    }

    #[Test]
    public function turningThePageAloneIsEnoughToChangeTheDocument(): void
    {
        $portrait = Page::a4();
        $landscape = $portrait->landscape();

        self::assertPaper($portrait, $this->write(new PdfOptions(page: $portrait), 'portrait.pdf'));
        self::assertPaper($landscape, $this->write(new PdfOptions(page: $landscape), 'landscape.pdf'));
    }

    #[Test]
    public function withPageHandsBackAFreshWriterAndLeavesTheOriginalOnItsOwnPaper(): void
    {
        // The same writer instance (handed in by hand through `ExportBuilder::writer()`, say) may
        // be shared across several exports; one export's A3 must not leak into another's output.
        $original = new PdfWriter(new PdfOptions(page: Page::a5()));

        self::assertSame($original, $original->withPage(null, null), 'Two nulls must not change anything.');

        $copy = $original->withPage(Page::a3()->landscape(), null);
        self::assertNotSame($original, $copy);

        self::assertPaper(Page::a3()->landscape(), $this->drive($copy, 'copy.pdf'));
        self::assertPaper(Page::a5(), $this->drive($original, 'original.pdf'));
    }

    // ---------------------------------------------------------------- ★ grouping

    #[Test]
    public function splittingIntoPageSetsCostsMorePagesThanDroppingColumns(): void
    {
        // An end-to-end and cheap proof: same data, same paper, only the overflow strategy
        // differs. NextPageSet splits the 18 columns into two groups and prints ALL rows twice;
        // Drop stays in a single group. Had no grouping happened at all (had the writer forgotten
        // to call `split()`, say), both documents would come out with the same page count.
        $columns = 18;
        $rows = 40;

        $pageSets = $this->write(
            new PdfOptions(budget: ColumnBudget::fit()->anchor('k1')),
            'takim.pdf',
            $columns,
            $rows,
        );

        $dropped = $this->write(
            new PdfOptions(budget: ColumnBudget::fit()->anchor('k1')->overflow(Overflow::Drop)),
            'elenmis.pdf',
            $columns,
            $rows,
        );

        self::assertGreaterThan(
            self::pageCount($dropped),
            self::pageCount($pageSets),
            'The columns were not split into page sets: both documents have the same page count.',
        );

        self::assertGreaterThan((int) filesize($dropped), (int) filesize($pageSets));
    }

    #[Test]
    public function aColumnSetThatFitsIsNotSplitAtAll(): void
    {
        // The counter-check: the page difference above comes from the grouping itself, not from
        // "the PDF just got long". A table that fits the budget has to stay on a single page.
        $path = $this->write(new PdfOptions(), 'sigan.pdf', 8, 5);

        self::assertSame(1, self::pageCount($path));
    }

    // ---------------------------------------------------------------- helpers

    private function tabula(): Tabula
    {
        return new Tabula(new ArrayTranslator([
            'tr' => [
                'col.code' => 'Kod',
                'col.name' => 'Ünvan',
                'col.qty' => 'Miktar',
            ],
        ]));
    }

    private function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('col.code'),
            Field::string('name')->label('col.name'),
            Field::quantity('qty')->label('col.qty'),
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['code' => '0042', 'name' => 'Çiğdem Şahin Öz', 'qty' => 12.5],
            ['code' => '0043', 'name' => 'Öz Güneş A.Ş.', 'qty' => 3],
        ];
    }

    /** Produces a document with the given options and returns its path. */
    private function write(PdfOptions $options, string $name, int $columnCount = 2, int $rowCount = 1): string
    {
        return $this->drive(new PdfWriter($options), $name, $columnCount, $rowCount);
    }

    /** Drives the writer by hand, without the pipeline. */
    private function drive(PdfWriter $writer, string $name, int $columnCount = 2, int $rowCount = 1): string
    {
        $path = $this->dir->file($name);

        $writer->open($path);
        $writer->startSheet('Test', self::columns($columnCount));

        for ($i = 0; $i < $rowCount; ++$i) {
            $writer->writeRow(self::cells($columnCount));
        }

        $writer->finishSheet();
        $writer->close();

        return $path;
    }

    /** @return list<Column> */
    private static function columns(int $count): array
    {
        $columns = [];

        for ($i = 1; $i <= $count; ++$i) {
            $columns[] = new Column(
                key: 'k'.$i,
                label: 'Column '.$i,
                type: FieldType::String,
                align: Align::Left,
                width: null,
                required: false,
                priority: Priority::Normal,
            );
        }

        return $columns;
    }

    /** @return list<Cell> */
    private static function cells(int $count): array
    {
        $cells = [];

        for ($i = 1; $i <= $count; ++$i) {
            $cells[] = Cell::text('value '.$i);
        }

        return $cells;
    }

    /** Verifies that the paper size printed into the document is the one `Page` says it is. */
    private static function assertPaper(Page $page, string $path): void
    {
        [$widthPt, $heightPt] = self::mediaBox($path);

        // Half a point of tolerance: Dompdf rounds the `/MediaBox` values to three decimals.
        self::assertEqualsWithDelta(self::toPoints($page->widthMm()), $widthPt, 0.5, 'The width of the document comes from the wrong paper.');
        self::assertEqualsWithDelta(self::toPoints($page->heightMm()), $heightPt, 0.5, 'The height of the document comes from the wrong paper.');
    }

    /**
     * Reads the first `/MediaBox` entry in the document, in points.
     *
     * @return array{float, float}
     */
    private static function mediaBox(string $path): array
    {
        $bytes = (string) file_get_contents($path);

        if (1 !== preg_match('#/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)#', $bytes, $found)) {
            self::fail('There is no /MediaBox in the document — the shape of Dompdf\'s output may have changed.');
        }

        return [(float) $found[1], (float) $found[2]];
    }

    /** The unit of a PDF is the point: 1 inch = 72 points = 25.4 mm. */
    private static function toPoints(float $mm): float
    {
        return $mm * 72.0 / 25.4;
    }

    /**
     * The number of pages in the document.
     *
     * The `\b` filters out the page TREE (`/Type /Pages`); that node appears once and would show
     * one page too many, breaking the comparison.
     */
    private static function pageCount(string $path): int
    {
        return (int) preg_match_all('#/Type\s*/Page\b#', (string) file_get_contents($path));
    }

    /**
     * The document's raw bytes plus every compressed stream that can be inflated.
     *
     * Not every stream is zlib (font subsets, cross-reference streams); a stream that cannot be
     * inflated is not an error, it is simply a stream we are not interested in. To silence the
     * warning we install our own handler instead of using `@`: PHPUnit runs with `failOnWarning`
     * and can report suppressed warnings too.
     */
    private static function readableBytes(string $path): string
    {
        $bytes = (string) file_get_contents($path);
        $readable = $bytes;

        preg_match_all("/stream\r?\n(.*?)endstream/s", $bytes, $streams);

        set_error_handler(static fn (): bool => true);

        try {
            foreach ($streams[1] as $stream) {
                $inflated = gzuncompress($stream);

                if (false !== $inflated) {
                    $readable .= $inflated;
                }
            }
        } finally {
            restore_error_handler();
        }

        return $readable;
    }
}
