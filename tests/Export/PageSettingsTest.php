<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Export;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Export\ExportBuilder;
use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Export\Writer\PdfOptions;
use Balin\Tabula\Export\Writer\PdfWriter;
use Balin\Tabula\Format;
use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Source\ArraySource;
use Balin\Tabula\Tabula;
use Balin\Tabula\Tests\Fixture\PdfDocument;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the `->page()` and `->columns()` settings REALLY reach the paper.
 *
 * The whole of this file guards against a single bug: **a page setting that is silently not
 * applied.** In the system this replaces, the page size was defined both in PHP
 * (`Dompdf::setPaper()`) and in the template's `@page` rule; because Dompdf applies the CSS at
 * render time, `setPaper()` was effectively decorative and the journal output was printed on A5
 * while everyone believed it was A4. Nobody noticed, because nothing anywhere made any noise.
 *
 * That is why the assertions are NOT made at the "the object was constructed" level but on the
 * bytes of the produced PDF; `PdfDocument` does the reading. The expected measurement is not
 * written out by hand either, it is derived from `Page` itself — otherwise the test would be
 * looking at its own copy of the paper table instead of at the code.
 */
final class PageSettingsTest extends TestCase
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

    // ---------------------------------------------------------------- does the page really reach the paper

    /**
     * With no setting given at all, the `PdfOptions` default must hold: A4 LANDSCAPE.
     *
     * The A5 test below would show that the paper "is not A5" even if `->page()` did nothing
     * whatsoever; this test additionally nails down what the default actually is.
     */
    #[Test]
    public function withoutAnyPageSettingThePdfIsA4Landscape(): void
    {
        $path = $this->dir->file('default.pdf');

        $this->export()->to(Format::Pdf)->write($path);

        PdfDocument::at($path)->assertPaper(Page::a4()->landscape());
    }

    #[Test]
    public function theRequestedPageSizeReachesTheProducedPdf(): void
    {
        $path = $this->dir->file('a5.pdf');

        $this->export()->to(Format::Pdf)->page(Page::a5())->write($path);

        PdfDocument::at($path)->assertPaper(Page::a5());
    }

    /**
     * The column budget has to reach the paper too — and that it did is read off the PAGE COUNT.
     *
     * On A5 portrait the usable width is 148 − 2×10 = 128 mm.
     *
     *  - With a 40 mm minimum column the budget is 3 columns; because the anchor (`code`) is
     *    repeated in every group, 2 data columns are left per group, so the remaining 5 of the
     *    6 columns are split into THREE groups as 2+2+1.
     *  - With a 20 mm minimum column the budget is 6 columns; all six fit, so a SINGLE group is left.
     *
     * Between the two outputs the paper, the schema and the row are identical; the only thing
     * that changes is the budget. The difference is therefore the work of the budget alone.
     */
    #[Test]
    public function theColumnBudgetSplitsTheDocumentIntoPageSets(): void
    {
        $narrow = $this->dir->file('split.pdf');
        $wide = $this->dir->file('single-group.pdf');

        $this->export()->to(Format::Pdf)
            ->page(Page::a5())
            ->columns(ColumnBudget::fit()->minWidth(40.0)->anchor('code'))
            ->write($narrow);

        $this->export()->to(Format::Pdf)
            ->page(Page::a5())
            ->columns(ColumnBudget::fit()->minWidth(20.0))
            ->write($wide);

        PdfDocument::at($narrow)->assertPageCount(3);
        PdfDocument::at($wide)->assertPageCount(1);
    }

    // ---------------------------------------------------------------- ★ silently ignoring is forbidden

    /**
     * ★ The heart of this phase.
     *
     * If a page setting is handed to a writer that has no concept of paper, the export DOES NOT
     * START. Silently ignoring it would be the very bug we are trying to eliminate: the user
     * believes they asked for A3, opens the Excel file, and understands nothing.
     */
    #[Test]
    #[DataProvider('formatsWithoutPaper')]
    public function aPageSettingOnAFormatWithoutPaperStopsTheExport(Format $format, string $file): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/PageAware/');

        $this->export()->to($format)->page(Page::a3())->write($this->dir->file($file));
    }

    /** The same rule applies when only the budget is given; the two are halves of one setting. */
    #[Test]
    #[DataProvider('formatsWithoutPaper')]
    public function aColumnBudgetOnAFormatWithoutPaperAlsoStopsTheExport(Format $format, string $file): void
    {
        $this->expectException(ExportException::class);

        $this->export()->to($format)->columns(ColumnBudget::fit())->write($this->dir->file($file));
    }

    /** @return iterable<string, array{Format, string}> */
    public static function formatsWithoutPaper(): iterable
    {
        yield 'csv' => [Format::Csv, 'olmaz.csv'];
        yield 'xlsx' => [Format::Xlsx, 'olmaz.xlsx'];
    }

    /**
     * The error must come out before a SINGLE BYTE is written to the file.
     *
     * If it blew up after the writing had started, a half-written file would be left on disk and
     * the caller could serve it to the user. A configuration mistake is a setup error, not a
     * streaming error.
     */
    #[Test]
    public function theRefusalHappensBeforeAnythingIsWritten(): void
    {
        $path = $this->dir->file('untouched.csv');

        try {
            $this->export()->to(Format::Csv)->page(Page::a3())->write($path);
            self::fail('The page setting should have been refused.');
        } catch (ExportException) {
            // expected
        }

        self::assertFileDoesNotExist($path);
    }

    /** With no setting given at all, the paperless formats of course keep working. */
    #[Test]
    public function aFormatWithoutPaperStillWorksWhenNoPageSettingIsGiven(): void
    {
        $path = $this->dir->file('normal.csv');

        self::assertSame($path, $this->export()->to(Format::Csv)->write($path)->path());
        self::assertFileExists($path);
    }

    // ---------------------------------------------------------------- an explicitly given writer

    /**
     * A writer handed in explicitly through `->writer()` must receive the page setting too.
     *
     * Saying "only configure what comes out of the factory" would mean that an explicitly given
     * `PdfWriter` swallows the setting silently — the back door to the very bug we are avoiding.
     */
    #[Test]
    public function anExplicitlyGivenWriterAlsoReceivesThePageSetting(): void
    {
        $writer = new PdfWriter(new PdfOptions(page: Page::a4()->landscape()));

        $path = $this->dir->file('manual.pdf');
        $this->export()->to(Format::Pdf)->writer($writer)->page(Page::a5())->write($path);

        PdfDocument::at($path)->assertPaper(Page::a5());
    }

    /**
     * ...but the instance ITSELF must not change.
     *
     * The writer given through `->writer()` may be a service and may be shared across several
     * exports; if one export's A5 leaks into another's output, the bug surfaces as an unrelated
     * report being printed on the wrong paper (see why `PageAware::withPage()` returns a copy).
     */
    #[Test]
    public function thePageSettingDoesNotStickToTheSharedWriterInstance(): void
    {
        $writer = new PdfWriter(new PdfOptions(page: Page::a4()->landscape()));

        $this->export()->to(Format::Pdf)->writer($writer)->page(Page::a5())
            ->write($this->dir->file('before-a5.pdf'));

        $later = $this->dir->file('after.pdf');
        $this->export()->to(Format::Pdf)->writer($writer)->write($later);

        PdfDocument::at($later)->assertPaper(Page::a4()->landscape());
    }

    // ---------------------------------------------------------------- immutability

    /** Like every other setup method, `->page()` returns a COPY. */
    #[Test]
    public function settingThePageLeavesTheOriginalBuilderUntouched(): void
    {
        $base = $this->export()->to(Format::Pdf);
        $a5 = $base->page(Page::a5());

        self::assertNotSame($base, $a5);

        $basePath = $this->dir->file('base.pdf');
        $a5Path = $this->dir->file('derived.pdf');

        $base->write($basePath);
        $a5->write($a5Path);

        PdfDocument::at($basePath)->assertPaper(Page::a4()->landscape());
        PdfDocument::at($a5Path)->assertPaper(Page::a5());
    }

    // ---------------------------------------------------------------- helpers

    private function export(): ExportBuilder
    {
        return (new Tabula(new ArrayTranslator([])))
            ->export($this->schema())
            ->from(ArraySource::of([[
                'code' => '0042',
                'name' => 'Çiğdem Şahin Ltd. Şti.',
                'city' => 'İstanbul',
                'phone' => '0212 000 00 00',
                'email' => 'info@ornek.test',
                'note' => 'Şubat ayında görüşülecek',
            ]]))
            ->locale('tr');
    }

    private function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('Kod'),
            Field::string('name')->label('Ünvan'),
            Field::string('city')->label('Şehir'),
            Field::string('phone')->label('Telefon'),
            Field::string('email')->label('E-posta'),
            Field::string('note')->label('Not'),
        );
    }
}
