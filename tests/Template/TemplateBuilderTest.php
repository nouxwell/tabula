<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Template;

use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Port\Translator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Template\TemplateBuilder;
use Nouxwell\Tabula\Template\TemplateOptions;
use Nouxwell\Tabula\Tests\Fixture\Status;
use Nouxwell\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Template production tests — the produced file is measured by READING IT BACK with
 * PhpSpreadsheet.
 *
 * The template is the contract of the "return" end of the round trip, and all of it rests on
 * one single decision:
 *
 *     row 1 → canonical field keys (hidden)
 *     row 2 → translated labels
 *     row 3 → data
 *
 * In the system this replaces the file's identity was the TRANSLATED HEADER STRING; a single
 * word changed in a translation file silently made every template users had on disk
 * unreadable. The tests below verify that that identity really does sit in the file.
 */
final class TemplateBuilderTest extends TestCase
{
    private TempDirectory $dir;

    private ?Spreadsheet $loaded = null;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        // Worksheet and Spreadsheet hold on to each other; a refcount-based garbage collector
        // cannot break that cycle.
        $this->loaded?->disconnectWorksheets();
        $this->loaded = null;

        $this->dir->remove();
    }

    // ---------------------------------------------------------------- helpers

    private function translator(): Translator
    {
        return new ArrayTranslator([
            'tr' => [
                'sheet.customer' => 'Müşteriler',
                'col.code' => 'Kod',
                'col.name' => 'Ünvan',
                'col.active' => 'Aktif',
                'col.locked' => 'Kilitli',
                'col.status' => 'Durum',
                'col.qty' => 'Miktar',
                'status.open' => 'Açık',
                'status.closed' => 'Kapalı',
                'tabula.bool.yes' => 'Evet',
                'tabula.bool.no' => 'Hayır',
            ],
        ]);
    }

    /**
     * The two BOOLEAN columns sit side by side on purpose: both produce the same
     * ["Evet","Hayır"] set, so if de-duplication works a single column must be created on the
     * helper sheet.
     */
    private function schema(): Schema
    {
        return Schema::make('customer')->title('sheet.customer')->fields(
            Field::string('code')->label('col.code')->required(),
            Field::string('name')->label('col.name'),
            Field::bool('isActive')->label('col.active'),
            Field::bool('isLocked')->label('col.locked'),
            Field::enum('status', Status::class)->label('col.status'),
            Field::quantity('qty')->label('col.qty')->decimals(3),
        );
    }

    private function build(?TemplateOptions $options = null): Spreadsheet
    {
        $builder = new TemplateBuilder(
            $this->translator(),
            new TabulaSettings(),
            $options ?? new TemplateOptions(),
        );

        $path = $this->dir->file('template.xlsx');
        $builder->write($this->schema(), $path, 'tr');

        return $this->loaded = IOFactory::load($path);
    }

    /** @return list<string> */
    private function rowValues(Worksheet $sheet, int $row): array
    {
        $last = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        $values = [];
        for ($column = 1; $column <= $last; ++$column) {
            $values[] = $sheet->getCell([$column, $row])->getValueString();
        }

        return $values;
    }

    /**
     * The REAL options of the drop-down list: the range the validation formula points at is
     * read cell by cell from the helper sheet.
     *
     * @return list<string>
     */
    private function dropdownOptions(Spreadsheet $spreadsheet, DataValidation $validation): array
    {
        self::assertSame(DataValidation::TYPE_LIST, $validation->getType());

        $formula = $validation->getFormula1();

        if (1 !== preg_match('/^_lists!\$([A-Z]+)\$(\d+):\$[A-Z]+\$(\d+)$/', $formula, $matches)) {
            self::fail(sprintf('Unexpected validation formula: %s', $formula));
        }

        $lists = $this->listSheet($spreadsheet);

        $options = [];
        for ($row = (int) $matches[2]; $row <= (int) $matches[3]; ++$row) {
            $options[] = $lists->getCell($matches[1].$row)->getValueString();
        }

        return $options;
    }

    private function listSheet(Spreadsheet $spreadsheet): Worksheet
    {
        $lists = $spreadsheet->getSheetByName('_lists');

        if (null === $lists) {
            self::fail('The hidden "_lists" helper sheet was not produced.');
        }

        return $lists;
    }

    // ---------------------------------------------------------------- layout

    #[Test]
    public function rowOneCarriesTheCanonicalKeysAndRowTwoTheTranslatedLabels(): void
    {
        $sheet = $this->build()->getSheet(0);

        // ★ THIS is the file's IDENTITY. Row 1 stays the same even if the translation changes.
        self::assertSame(
            ['code', 'name', 'isActive', 'isLocked', 'status', 'qty'],
            $this->rowValues($sheet, 1),
            'Row 1 must carry the canonical field keys.',
        );

        self::assertSame(
            ['Kod', 'Ünvan', 'Aktif', 'Kilitli', 'Durum', 'Miktar'],
            $this->rowValues($sheet, 2),
            'Row 2 must carry the translation the user reads.',
        );

        self::assertSame('Müşteriler', $sheet->getTitle());
    }

    #[Test]
    public function theKeyRowIsHiddenButStillPresentInTheFile(): void
    {
        $sheet = $this->build()->getSheet(0);

        // The row is HIDDEN, not deleted: the user does not see the technical keys, the import does.
        self::assertFalse($sheet->getRowDimension(1)->getVisible(), 'The key row must be hidden.');
        self::assertTrue($sheet->getRowDimension(2)->getVisible(), 'The label row must be visible.');
        self::assertSame('code', $sheet->getCell('A1')->getValueString(), 'The hidden row must go on sitting in the file.');
    }

    #[Test]
    public function dataStartsAtRowThreeAndBothHeaderRowsAreFrozen(): void
    {
        $sheet = $this->build()->getSheet(0);

        // "Freeze A3" = both the key row AND the label row stay put.
        // (`XlsxWriter` freezes A2 for a single header row; the difference comes from the
        // hidden key row.)
        self::assertSame('A3', $sheet->getFreezePane());

        // A template has no FILLED data row at all.
        self::assertSame(['', '', '', '', '', ''], $this->rowValues($sheet, 3), 'The data rows must be empty.');

        // Column styles are applied with a FULL COLUMN range ("B1:B1048576"). A range starting
        // from the data row ("B3:B1048576") looks intuitively right but no longer counts as a
        // full column: PhpSpreadsheet really does create a cell for the ONE MILLION
        // coordinates in between and the file blows up memory in a single row.
        // (In the loaded file C3/D3/E3 appear; the reader creates those while applying the
        // validation ranges — the written file's own extent is A1:F2.)
        self::assertLessThan(10, $sheet->getHighestRow(), 'A full-column style must not create a million cells.');
    }

    #[Test]
    public function aRequiredColumnHeaderCarriesTheRequiredFill(): void
    {
        $sheet = $this->build()->getSheet(0);

        // This was the one visual cue in the old templates that actually worked; it was kept.
        self::assertSame(
            'FFFCE4E4',
            $sheet->getStyle('A2')->getFill()->getStartColor()->getARGB(),
            'A required column header must have the red fill.',
        );

        self::assertSame(
            'FFF2F2F2',
            $sheet->getStyle('B2')->getFill()->getStartColor()->getARGB(),
            'A column that is not required must get the ordinary header fill.',
        );
    }

    // ---------------------------------------------------------------- drop-down lists

    /**
     * ★ The most concrete flaw of the system this replaces: the value it wrote into the cell
     * WAS NOT PRESENT in its own validation list (the text came from one translation family,
     * the list from another). Here the list is produced by asking the very formatter the
     * export uses; the two cannot drift apart.
     */
    #[Test]
    public function aBoolColumnDropdownContainsTheTranslatedYesNoPair(): void
    {
        $spreadsheet = $this->build();
        $sheet = $spreadsheet->getSheet(0);

        self::assertTrue($sheet->dataValidationExists('C3'), 'A boolean column must have a drop-down list.');

        self::assertSame(
            ['Evet', 'Hayır'],
            $this->dropdownOptions($spreadsheet, $sheet->getDataValidation('C3')),
            'The text in the list must be THE SAME as the text the export writes into the cell.',
        );
    }

    #[Test]
    public function anEnumColumnDropdownContainsEveryCaseLabel(): void
    {
        $spreadsheet = $this->build();
        $sheet = $spreadsheet->getSheet(0);

        self::assertSame(
            ['Açık', 'Kapalı'],
            $this->dropdownOptions($spreadsheet, $sheet->getDataValidation('E3')),
        );
    }

    #[Test]
    public function identicalOptionSetsShareASingleRangeOnTheHiddenListSheet(): void
    {
        $spreadsheet = $this->build();
        $sheet = $spreadsheet->getSheet(0);

        $first = $sheet->getDataValidation('C3')->getFormula1();
        $second = $sheet->getDataValidation('D3')->getFormula1();

        self::assertSame($first, $second, 'Two columns sharing the same option set must look at a SINGLE range.');

        $lists = $this->listSheet($spreadsheet);

        // The two boolean columns fall into one column, the enum takes the second: two columns in total.
        self::assertSame('B', $lists->getHighestColumn(), 'Without de-duplication three separate columns would be created.');
        self::assertSame(Worksheet::SHEETSTATE_HIDDEN, $lists->getSheetState());
    }

    #[Test]
    public function aPlainColumnGetsNoDropdown(): void
    {
        $sheet = $this->build()->getSheet(0);

        // An empty drop-down list is worse than no list at all: Excel locks the cell.
        self::assertFalse($sheet->dataValidationExists('A3'), 'A text column must have no list.');
        self::assertFalse($sheet->dataValidationExists('F3'), 'A quantity column must have no list.');
    }

    // ---------------------------------------------------------------- options

    #[Test]
    public function switchingOffTheKeyRowMovesEverythingUpOneRow(): void
    {
        // Turning the key row off condemns the file to matching BY LABEL — that is, it goes
        // back to the fatal flaw of the system this replaces. The option must nevertheless
        // keep its contract.
        $sheet = $this->build(new TemplateOptions(includeKeyRow: false))->getSheet(0);

        self::assertSame(['Kod', 'Ünvan', 'Aktif', 'Kilitli', 'Durum', 'Miktar'], $this->rowValues($sheet, 1));
        self::assertSame('A2', $sheet->getFreezePane());
        self::assertTrue($sheet->dataValidationExists('C2'));
    }
}
