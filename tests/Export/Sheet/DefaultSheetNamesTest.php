<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Export\Sheet;

use Nouxwell\Tabula\Export\Sheet\ChunkedSheets;
use Nouxwell\Tabula\Export\Sheet\GroupedSheets;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Port\PassthroughTranslator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Source\ArraySource;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use Nouxwell\Tabula\Value\FormatContext;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the sheet names the library invents on the user's behalf.
 *
 * These three strings are the only text the package puts in front of an end user without going
 * through the translator, so they are the only place where a stray word from the maintainer's
 * own language can reach a workbook. Nothing asserted them until now, which meant they could
 * silently drift back — the exact class of regression these tests exist to catch.
 */
final class DefaultSheetNamesTest extends TestCase
{
    private function context(): FormatContext
    {
        return new FormatContext('en', new PassthroughTranslator(), new TabulaSettings(), Format::Xlsx);
    }

    #[Test]
    public function chunkedSheetsAreNumberedInEnglish(): void
    {
        $strategy = new ChunkedSheets(2);
        $context = $this->context();

        self::assertSame('Sheet 1', $strategy->sheetFor(0, [], $context));
        self::assertSame('Sheet 1', $strategy->sheetFor(1, [], $context));
        self::assertSame('Sheet 2', $strategy->sheetFor(2, [], $context));
        self::assertSame('Sheet 3', $strategy->sheetFor(4, [], $context));
    }

    #[Test]
    public function aCustomChunkPatternStillWins(): void
    {
        // The default is only a default: a caller naming its own sheets must not be overridden.
        $strategy = new ChunkedSheets(2, 'Page %d');

        self::assertSame('Page 1', $strategy->sheetFor(0, [], $this->context()));
        self::assertSame('Page 2', $strategy->sheetFor(2, [], $this->context()));
    }

    #[Test]
    public function theGroupedFallbackNameIsEnglish(): void
    {
        // A row whose grouping value is null lands in the fallback bucket; that bucket's name is
        // written into the workbook verbatim.
        $strategy = new GroupedSheets('warehouse');

        self::assertSame('Other', $strategy->sheetFor(0, ['warehouse' => null], $this->context()));
        self::assertSame('Other', $strategy->sheetFor(1, ['warehouse' => '   '], $this->context()));
        self::assertSame('Main', $strategy->sheetFor(2, ['warehouse' => 'Main'], $this->context()));
    }

    #[Test]
    public function aCustomGroupedFallbackStillWins(): void
    {
        $strategy = new GroupedSheets('warehouse', 'Unassigned');

        self::assertSame('Unassigned', $strategy->sheetFor(0, ['warehouse' => null], $this->context()));
    }

    #[Test]
    public function theWriterFallbackTitleIsEnglish(): void
    {
        // When the sheet name comes from DATA, sanitising it can leave nothing behind and the
        // writer has to invent a title — which then reaches the user.
        //
        // Forbidden characters alone are not enough to trigger it: `* : / \ ? [ ]` are REPLACED
        // with a dash, so "Sales/Returns" survives as "Sales-Returns". The title only empties out
        // when it consists of the characters that get TRIMMED — leading/trailing apostrophes,
        // which Excel cannot tolerate because it wraps sheet names in single quotes inside
        // formulas.
        $dir = TempDirectory::create();

        try {
            $path = $dir->file('fallback.xlsx');

            $tabula = new Tabula(new PassthroughTranslator(), new TabulaSettings());
            $tabula->export(Schema::make('t')->fields(Field::string('code')->label('Code')))
                ->from(ArraySource::of([
                    ['code' => 'a', 'group' => "'''"],
                    ['code' => 'b', 'group' => "''''"],
                ]))
                ->sheets(new GroupedSheets('group'))
                ->locale('en')
                ->to(Format::Xlsx)
                ->write($path);

            $names = IOFactory::load($path)->getSheetNames();

            self::assertCount(2, $names);
            foreach ($names as $name) {
                self::assertStringStartsWith('Sheet', $name, 'The invented sheet title must be English.');
            }
        } finally {
            $dir->remove();
        }
    }
}
