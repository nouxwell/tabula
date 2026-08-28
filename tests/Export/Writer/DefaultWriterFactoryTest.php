<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Export\Writer;

use Nouxwell\Tabula\Export\Column;
use Nouxwell\Tabula\Export\Page\Page;
use Nouxwell\Tabula\Export\Writer\CsvOptions;
use Nouxwell\Tabula\Export\Writer\CsvWriter;
use Nouxwell\Tabula\Export\Writer\DefaultWriterFactory;
use Nouxwell\Tabula\Export\Writer\PdfOptions;
use Nouxwell\Tabula\Export\Writer\PdfWriter;
use Nouxwell\Tabula\Export\Writer\Writer;
use Nouxwell\Tabula\Export\Writer\XlsxOptions;
use Nouxwell\Tabula\Export\Writer\XlsxWriter;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The writer factory's contract.
 *
 * The factory itself is a few lines long; what is really valuable are its TWO PROMISES:
 *
 *  1. EVERY call hands out a FRESH writer. Writers carry state (an open file handle, the active
 *     sheet); a single shared instance would, on a code path that runs two exports back to back
 *     in the same request, make one of them write over the other's file. This is verified not
 *     only as "a different object" with `assertNotSame` but by REALLY driving two writers at the
 *     same time and looking at their files (see `twoWritersFromOneFactory...`).
 *  2. `with()` does not mutate, it COPIES. An application-specific writer (a letterheaded PDF,
 *     say) is plugged in this way, and the shared factory service is not polluted.
 */
#[CoversClass(DefaultWriterFactory::class)]
final class DefaultWriterFactoryTest extends TestCase
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

    // ---------------------------------------------------------------- format → class

    #[Test]
    public function everyBuiltInFormatGetsItsOwnWriterClass(): void
    {
        $factory = new DefaultWriterFactory();

        self::assertInstanceOf(CsvWriter::class, $factory->for(Format::Csv));
        self::assertInstanceOf(XlsxWriter::class, $factory->for(Format::Xlsx));
        self::assertInstanceOf(PdfWriter::class, $factory->for(Format::Pdf));
    }

    /**
     * Does the PDF options object given to the factory really reach the writer it produces?
     *
     * `PdfWriter` does NOT expose its options (the writer has no surface to read them off), so
     * the assertion is read from the output itself: A5 portrait paper comes out as 419×595
     * points in the PDF's `MediaBox`. Saying "the object was constructed" would not be enough
     * here — the reason this phase exists is a page setting that was configured but not applied.
     */
    #[Test]
    public function thePdfOptionsGivenToTheFactoryReachTheWriterItProduces(): void
    {
        $path = $this->dir->file('a5.pdf');

        $writer = (new DefaultWriterFactory(pdf: new PdfOptions(page: Page::a5())))->for(Format::Pdf);

        $writer->open($path);
        $writer->startSheet('Müşteriler', self::columns());
        $writer->writeRow([Cell::text('0042')]);
        $writer->finishSheet();

        self::assertSame([$path], $writer->close());
        self::assertStringContainsString('MediaBox [0.000 0.000 419.528 595.276]', (string) file_get_contents($path));
    }

    // ---------------------------------------------------------------- ★ a fresh writer

    #[Test]
    public function everyCallHandsOutAFreshWriter(): void
    {
        $factory = new DefaultWriterFactory();

        self::assertNotSame($factory->for(Format::Csv), $factory->for(Format::Csv));
        self::assertNotSame($factory->for(Format::Xlsx), $factory->for(Format::Xlsx));
        self::assertNotSame($factory->for(Format::Pdf), $factory->for(Format::Pdf));
    }

    /**
     * The concrete cost behind the "a different object" claim.
     *
     * Had the factory shared a single instance, the second `open()` would be refused as "already
     * open" — and had it not been refused, the two exports would write into the same file handle.
     * The scenario below is INTERLEAVED on purpose: in real life it easily gets this way through
     * an event listener where one export triggers another.
     */
    #[Test]
    public function twoWritersFromOneFactoryDoNotWriteIntoEachOthersFile(): void
    {
        $factory = new DefaultWriterFactory();

        $first = $factory->for(Format::Csv);
        $second = $factory->for(Format::Csv);

        $firstPath = $this->dir->file('first.csv');
        $secondPath = $this->dir->file('second.csv');

        $first->open($firstPath);
        $first->startSheet('First', self::columns());

        $second->open($secondPath);
        $second->startSheet('Second', self::columns());

        $first->writeRow([Cell::text('one')]);
        $second->writeRow([Cell::text('two')]);

        self::assertSame([$firstPath], $first->close());
        self::assertSame([$secondPath], $second->close());

        $firstBytes = (string) file_get_contents($firstPath);
        $secondBytes = (string) file_get_contents($secondPath);

        self::assertStringContainsString('one', $firstBytes);
        self::assertStringNotContainsString('two', $firstBytes);

        self::assertStringContainsString('two', $secondBytes);
        self::assertStringNotContainsString('one', $secondBytes);
    }

    // ---------------------------------------------------------------- with()

    #[Test]
    public function anOverrideReplacesTheBuiltInWriter(): void
    {
        $replacement = $this->createStub(Writer::class);

        $factory = (new DefaultWriterFactory())->with(Format::Csv, static fn (): Writer => $replacement);

        self::assertSame($replacement, $factory->for(Format::Csv));
        // Only the given format may change; the others must stay built-in.
        self::assertInstanceOf(XlsxWriter::class, $factory->for(Format::Xlsx));
    }

    /**
     * The override must NOT be memoised.
     *
     * If the closure's result is produced once and kept, the factory's whole reason for existing
     * — a fresh writer on every call — is undone; and this time it is the application wiring the
     * library up, not the library itself, that appears to be at fault.
     */
    #[Test]
    public function theOverrideIsAskedOnceForEveryCall(): void
    {
        $calls = 0;

        $factory = (new DefaultWriterFactory())->with(
            Format::Csv,
            static function () use (&$calls): Writer {
                ++$calls;

                return new CsvWriter();
            },
        );

        $first = $factory->for(Format::Csv);
        $second = $factory->for(Format::Csv);
        $third = $factory->for(Format::Csv);

        self::assertSame(3, $calls, 'The closure should have been asked three times; it may have been memoised.');
        self::assertNotSame($first, $second);
        self::assertNotSame($second, $third);
    }

    #[Test]
    public function withReturnsANewFactoryAndLeavesTheOriginalUntouched(): void
    {
        $original = new DefaultWriterFactory();
        $replacement = $this->createStub(Writer::class);

        $overridden = $original->with(Format::Csv, static fn (): Writer => $replacement);

        self::assertNotSame($original, $overridden);

        // The source factory is a SHARED service in the container; had `with()` polluted it, one
        // call site's custom writer would spread to the whole application.
        self::assertInstanceOf(CsvWriter::class, $original->for(Format::Csv));
        self::assertSame($replacement, $overridden->for(Format::Csv));
    }

    // ---------------------------------------------------------------- do the options reach the writer

    #[Test]
    public function theCsvOptionsGivenToTheFactoryReachTheWriterItProduces(): void
    {
        $path = $this->dir->file('feed.csv');

        $this->tabula(new DefaultWriterFactory(CsvOptions::rfc4180()))->export(self::schema())
            ->from(ArraySource::of([['code' => '0042', 'name' => 'Öz Güneş A.Ş.']]))
            ->locale('tr')
            ->to(Format::Csv)
            ->write($path);

        $bytes = (string) file_get_contents($path);

        // Had the options given to the factory not reached the writer, what would be here is a
        // file with a BOM and semicolons (that is, one tuned for Turkish Excel).
        self::assertStringStartsWith("Kod,Ünvan\r\n", $bytes);
    }

    #[Test]
    public function theXlsxOptionsGivenToTheFactoryReachTheWriterItProduces(): void
    {
        $path = $this->dir->file('plain.xlsx');

        $this->tabula(new DefaultWriterFactory(xlsx: XlsxOptions::plain()))->export(self::schema())
            ->from(ArraySource::of([['code' => '0042', 'name' => 'Öz Güneş A.Ş.']]))
            ->locale('tr')
            ->to(Format::Xlsx)
            ->write($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        self::assertNull($sheet->getFreezePane());
        self::assertSame('', $sheet->getAutoFilter()->getRange());
    }

    // ---------------------------------------------------------------- helpers

    private function tabula(DefaultWriterFactory $writers): Tabula
    {
        return new Tabula(
            new ArrayTranslator(['tr' => ['col.code' => 'Kod', 'col.name' => 'Ünvan']]),
            writers: $writers,
        );
    }

    private static function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('col.code'),
            Field::string('name')->label('col.name'),
        );
    }

    /**
     * A single resolved column header, for driving the writer by hand without the pipeline.
     *
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [
            new Column(
                key: 'code',
                label: 'Kod',
                type: FieldType::String,
                align: Align::Left,
                width: null,
                required: false,
                priority: Priority::Normal,
            ),
        ];
    }
}
