<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Import;

use Nouxwell\Tabula\Exception\ImportException;
use Nouxwell\Tabula\Export\Sheet\GroupedSheets;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Import\ImportedRow;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Port\Translator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Source\ArraySource;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What happens when a workbook holds more than one sheet.
 *
 * The export can split a file per group or per chunk, which means the library could produce a
 * workbook it then could not read back. Until this was fixed the import read sheet one and
 * stopped: the other sheets were dropped without an error, the row count looked plausible, and
 * nothing anywhere pointed at the missing rows. On accounting data that is the worst shape a
 * failure can take, because it does not look like one.
 *
 * The fix is not to guess. An ambiguous workbook is refused and the caller names a sheet.
 */
final class MultiSheetTest extends TestCase
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

    private function translator(): Translator
    {
        return new ArrayTranslator(['tr' => [
            'col.code' => 'Kod',
            'col.warehouse' => 'Depo',
        ]]);
    }

    private function schema(): Schema
    {
        return Schema::make('stock')->fields(
            Field::string('code')->label('col.code'),
            Field::string('warehouse')->label('col.warehouse'),
        );
    }

    /**
     * @return list<array{code: string, warehouse: string}>
     */
    private function rows(): array
    {
        return [
            ['code' => 'A-1', 'warehouse' => 'Ankara'],
            ['code' => 'A-2', 'warehouse' => 'Ankara'],
            ['code' => 'I-1', 'warehouse' => 'Izmir'],
        ];
    }

    private function groupedExport(): string
    {
        $tabula = new Tabula($this->translator(), new TabulaSettings());
        $path = $this->dir->file('grouped.xlsx');

        $tabula->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->sheets(new GroupedSheets('warehouse'))
            ->locale('tr')
            ->to(Format::Xlsx)
            ->write($path);

        return $path;
    }

    /**
     * ★ The regression this class exists for.
     *
     * Three rows go out across two sheets. Before the fix, importing that same file back
     * returned the two Ankara rows and no error at all — the Izmir row simply ceased to exist.
     */
    #[Test]
    public function aWorkbookSplitAcrossSheetsIsRefusedRatherThanHalfRead(): void
    {
        $tabula = new Tabula($this->translator(), new TabulaSettings());

        $this->expectException(ImportException::class);
        // The message has to name the sheets: the caller's next move is to pick one, and they
        // should not have to open the file to learn what the choices are.
        $this->expectExceptionMessageMatches('/"Ankara".+"Izmir"/s');

        $tabula->import($this->schema())
            ->from($this->groupedExport())
            ->locale('tr')
            ->each(static fn (ImportedRow $row) => null)
            ->run();
    }

    #[Test]
    public function namingASheetReadsThatSheetAndOnlyThatSheet(): void
    {
        $tabula = new Tabula($this->translator(), new TabulaSettings());
        $path = $this->groupedExport();

        $read = [];
        $result = $tabula->import($this->schema())
            ->from($path)
            ->sheet('Izmir')
            ->locale('tr')
            ->each(function (ImportedRow $row) use (&$read): void {
                $read[] = $row->toArray();
            })
            ->run();

        self::assertCount(0, $result->errors);
        self::assertCount(1, $read, 'Only the Izmir sheet should have been read.');
        self::assertSame('I-1', $read[0]['code']);
    }

    #[Test]
    public function everySheetCanBeReadByNamingEachInTurn(): void
    {
        $tabula = new Tabula($this->translator(), new TabulaSettings());
        $path = $this->groupedExport();

        $codes = [];
        foreach (['Ankara', 'Izmir'] as $sheet) {
            $tabula->import($this->schema())
                ->from($path)
                ->sheet($sheet)
                ->locale('tr')
                ->each(function (ImportedRow $row) use (&$codes): void {
                    $codes[] = $row->toArray()['code'];
                })
                ->run();
        }

        // Nothing was lost: the round trip is complete once the caller says how to read it.
        self::assertSame(['A-1', 'A-2', 'I-1'], $codes);
    }

    #[Test]
    public function anUnknownSheetNameSaysWhichNamesExist(): void
    {
        $tabula = new Tabula($this->translator(), new TabulaSettings());

        $this->expectException(ImportException::class);
        $this->expectExceptionMessageMatches('/no sheet named "Bursa".+Ankara/s');

        $tabula->import($this->schema())
            ->from($this->groupedExport())
            ->sheet('Bursa')
            ->locale('tr')
            ->each(static fn (ImportedRow $row) => null)
            ->run();
    }

    /**
     * A single-sheet file must stay untouched by all of this.
     *
     * This is the case that would have made the fix worse than the bug: refusing a workbook the
     * moment it has more than one sheet would break every template import, because the template
     * writes its dropdown sources into a hidden `_lists` sheet. Hidden sheets are not data.
     */
    #[Test]
    public function aTemplateWithItsHiddenHelperSheetIsStillASingleSheetFile(): void
    {
        $tabula = new Tabula($this->translator(), new TabulaSettings());

        $schema = Schema::make('stock')->fields(
            Field::string('code')->label('col.code'),
            Field::options('warehouse', ['col.code'])->label('col.warehouse'),
        );

        $path = $this->dir->file('template.xlsx');
        $tabula->template()->write($schema, $path, 'tr');

        $result = $tabula->import($schema)
            ->from($path)
            ->locale('tr')
            ->each(static fn (ImportedRow $row) => null)
            ->run();

        // An empty template has no data rows; the point is that it was READ, not refused.
        self::assertCount(0, $result->errors);
    }

    #[Test]
    public function askingForASheetOnACsvIsRefusedInsteadOfIgnored(): void
    {
        $tabula = new Tabula($this->translator(), new TabulaSettings());

        $path = $this->dir->file('flat.csv');
        file_put_contents($path, "Kod;Depo\nA-1;Ankara\n");

        $this->expectException(ImportException::class);
        $this->expectExceptionMessageMatches('/without sheets/');

        $tabula->import($this->schema())
            ->from($path)
            ->sheet('Ankara')
            ->locale('tr')
            ->each(static fn (ImportedRow $row) => null)
            ->run();
    }
}
