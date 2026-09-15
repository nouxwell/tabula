<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Template;

use DateTimeImmutable;
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
use PhpOffice\PhpSpreadsheet\Style\Alignment;
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
            // Naming the keys this file's catalogue defines. The shipped defaults are the plain
            // words "Yes"/"No" so that an unconfigured template cannot offer a dropdown whose
            // options are translation keys.
            new TabulaSettings(boolTrueKey: 'tabula.bool.yes', boolFalseKey: 'tabula.bool.no'),
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
        self::assertFalse($sheet->dataValidationExists('A3'), 'A text column must have no validation at all.');

        // The quantity column DOES carry a validation now — a numeric one, not a list.
        // Asserting mere existence here would pass for a dropdown too, which is the thing
        // this test exists to rule out.
        self::assertSame(
            DataValidation::TYPE_DECIMAL,
            $sheet->getDataValidation('F3')->getType(),
            'A quantity column must be validated as a number, never as a list.',
        );
    }

    // ---------------------------------------------------------------- column widths

    /**
     * Two of the builder's own defaults used to fight each other.
     *
     * Auto-sizing measures the header TEXT and stops; the auto-filter then draws its button
     * inside the cell against the right edge. A column sized exactly to its header lost its
     * last characters behind the arrow — "Döviz Kuru" rendered as "Döviz Ku▾".
     */
    #[Test]
    public function anAutoSizedColumnLeavesRoomForTheFilterButton(): void
    {
        $schema = Schema::make('w')->fields(
            Field::string('narrow')->label('col.code'),
            Field::string('wide')->label('col.name'),
        );

        $path = $this->dir->file('widths.xlsx');
        (new TemplateBuilder($this->translator(), new TabulaSettings(), new TemplateOptions()))
            ->write($schema, $path, 'tr');

        $sheet = IOFactory::load($path)->getSheet(0);

        foreach (['A', 'B'] as $letter) {
            $dimension = $sheet->getColumnDimension($letter);

            // The width must be RESOLVED, not left on auto-size: a column still on auto-size
            // is re-measured by Excel on open, without the headroom.
            self::assertFalse($dimension->getAutoSize(), "{$letter} must carry a resolved width.");

            $label = (string) $sheet->getCell($letter.'2')->getValue();
            self::assertGreaterThan(
                mb_strlen($label) + 2,
                $dimension->getWidth(),
                "{$letter} is too narrow for its header plus the filter button.",
            );
        }
    }

    /**
     * The collision the width headroom alone could not fix.
     *
     * The filter button sits at the cell's right edge and a right-aligned label is pushed to
     * that same edge, so they overlap however wide the column is — widening a right-aligned
     * cell adds the room on the LEFT. Only the header moves; the column below keeps its
     * alignment, because lining figures up on the decimal point is why it was right-aligned.
     */
    #[Test]
    public function aNumericHeaderIsNotRightAlignedUnderTheFilterButton(): void
    {
        $sheet = $this->build()->getSheet(0);

        // F is the quantity column: right-aligned data, header moved off the edge.
        self::assertSame(
            Alignment::HORIZONTAL_LEFT,
            $sheet->getStyle('F2')->getAlignment()->getHorizontal(),
            'A right-aligned header would sit under the filter button.',
        );

        self::assertSame(
            Alignment::HORIZONTAL_RIGHT,
            $sheet->getStyle('F3')->getAlignment()->getHorizontal(),
            'The data must still line up on the right.',
        );
    }

    /**
     * A header long enough to blow past Excel's own ceiling still produces an openable file.
     *
     * Auto-sizing measures the text and stops; nothing in PhpSpreadsheet clamps the result, so
     * around 210 characters the computed width crosses 255 and Excel opens the workbook saying
     * it needs repairing. Labels come from translation files, so "nobody would write that" is
     * not a guarantee.
     */
    #[Test]
    public function anAbsurdlyLongHeaderStaysWithinExcelsColumnLimit(): void
    {
        $schema = Schema::make('w')->fields(
            Field::string('long')->label('col.long'),
        );

        $translator = new ArrayTranslator(['tr' => ['col.long' => str_repeat('A', 400)]]);

        $path = $this->dir->file('long-header.xlsx');
        (new TemplateBuilder($translator, new TabulaSettings(), new TemplateOptions()))
            ->write($schema, $path, 'tr');

        $sheet = IOFactory::load($path)->getSheet(0);

        self::assertLessThanOrEqual(255.0, $sheet->getColumnDimension('A')->getWidth());
        // The label itself is never truncated — only the column stops widening.
        self::assertSame(400, mb_strlen((string) $sheet->getCell('A2')->getValue()));
    }

    /** A width the caller set by hand is left exactly as given — they said what they wanted. */
    #[Test]
    public function anExplicitWidthIsNotWidened(): void
    {
        $schema = Schema::make('w')->fields(
            Field::string('fixed')->label('col.code')->width(9),
        );

        $path = $this->dir->file('fixed-width.xlsx');
        (new TemplateBuilder($this->translator(), new TabulaSettings(), new TemplateOptions()))
            ->write($schema, $path, 'tr');

        $sheet = IOFactory::load($path)->getSheet(0);

        self::assertEqualsWithDelta(9.0, $sheet->getColumnDimension('A')->getWidth(), 0.01);
    }

    // ---------------------------------------------------------------- type validation

    #[Test]
    public function aDateColumnIsValidatedAsADateAndAnIntegerAsAWholeNumber(): void
    {
        $schema = Schema::make('doc')->fields(
            Field::date('issuedAt')->label('col.date'),
            Field::integer('lineNo')->label('col.line'),
        );

        $path = $this->dir->file('typed.xlsx');
        (new TemplateBuilder($this->translator(), new TabulaSettings(), new TemplateOptions()))
            ->write($schema, $path, 'tr');

        $sheet = IOFactory::load($path)->getSheet(0);

        self::assertSame(DataValidation::TYPE_DATE, $sheet->getDataValidation('A3')->getType());
        // WHOLE, not decimal: "12,5" typed into a line number is a typo, and silently
        // rounding it is precisely what the import refuses to do.
        self::assertSame(DataValidation::TYPE_WHOLE, $sheet->getDataValidation('B3')->getType());
    }

    /**
     * The bounds exist to make the rule well-formed, not to express a limit.
     *
     * Excel has no "any number" rule; a decimal validation must carry an operator and bounds.
     * Tight, sensible-looking bounds would reject the one legitimate value that exceeds them
     * and leave the user no way to see why the cell refuses it.
     */
    #[Test]
    public function theNumericBoundsExcludeNothingASpreadsheetCanHold(): void
    {
        $validation = $this->build()->getSheet(0)->getDataValidation('F3');

        self::assertSame(DataValidation::OPERATOR_BETWEEN, $validation->getOperator());
        self::assertLessThanOrEqual(-1.0e15, (float) $validation->getFormula1());
        self::assertGreaterThanOrEqual(1.0e15, (float) $validation->getFormula2());
    }

    /**
     * Blank stays allowed on every rule.
     *
     * Requiredness belongs to the import, where the failure can name the row and the field.
     * Made Excel's job, it fires a warning box for merely tabbing through a row the user has
     * not reached yet — and a user who meets that box twice switches validation off for good,
     * taking the rules that do matter with it.
     */
    #[Test]
    public function everyValidationAllowsABlankCell(): void
    {
        $sheet = $this->build()->getSheet(0);

        foreach (['C3', 'E3', 'F3'] as $cell) {
            self::assertTrue(
                $sheet->getDataValidation($cell)->getAllowBlank(),
                "{$cell} must accept an empty cell.",
            );
        }
    }

    // ---------------------------------------------------------------- examples

    private function buildWith(Schema $schema, ?TemplateOptions $options = null, ?Translator $translator = null): Spreadsheet
    {
        $builder = new TemplateBuilder(
            $translator ?? $this->translator(),
            new TabulaSettings(boolTrueKey: 'tabula.bool.yes', boolFalseKey: 'tabula.bool.no'),
            $options ?? new TemplateOptions(),
        );

        $path = $this->dir->file('examples.xlsx');
        $builder->write($schema, $path, 'tr');

        return $this->loaded = IOFactory::load($path);
    }

    /**
     * The example is a MESSAGE, never a cell.
     *
     * A sample row in the data area is imported as a real record the moment a user forgets to
     * delete it — on an opening-balance template that is two invented ledger entries. An input
     * message cannot be imported, so the file stays empty and the guidance is still there the
     * moment the user needs it.
     */
    #[Test]
    public function aTextColumnWithAnExampleShowsItWhenTheCellIsSelected(): void
    {
        $sheet = $this->buildWith(Schema::make('doc')->fields(
            Field::string('code')->label('col.code')->required()->example('120.01.001'),
        ))->getSheet(0);

        $validation = $sheet->getDataValidation('A3');

        self::assertTrue($validation->getShowInputMessage());
        self::assertSame('Kod', $validation->getPromptTitle());
        self::assertSame("Example: 120.01.001\nRequired", $validation->getPrompt());
        // An "any value" rule: it carries the message and can never refuse what is typed.
        self::assertSame(DataValidation::TYPE_NONE, $validation->getType());
        self::assertSame('', $sheet->getCell('A3')->getValueString(), 'The example must not be written into a cell.');
    }

    #[Test]
    public function aNumericExampleIsShownTheWayTheExportWouldWriteIt(): void
    {
        $validation = $this->buildWith(Schema::make('doc')->fields(
            Field::decimal('total')->label('col.total')->decimals(2)->example(1250.5),
        ))->getSheet(0)->getDataValidation('A3');

        self::assertSame('Example: 1.250,50', $validation->getPrompt());
        // The message rides on the existing rule instead of replacing it.
        self::assertSame(DataValidation::TYPE_DECIMAL, $validation->getType());
    }

    #[Test]
    public function aDateExampleFollowsTheDatePattern(): void
    {
        $validation = $this->buildWith(Schema::make('doc')->fields(
            Field::date('issuedAt')->label('col.date')->example(new DateTimeImmutable('2026-01-31')),
        ))->getSheet(0)->getDataValidation('A3');

        self::assertSame('Example: 31.01.2026', $validation->getPrompt());
        self::assertSame(DataValidation::TYPE_DATE, $validation->getType());
    }

    /**
     * A template has no row, and a closure written for rows must never be called with null.
     *
     * The first draft sent the example through the export formatter as it was. The currency
     * closure the README shows — `fn (array $row): string => $row['currencyCode']` — then threw
     * a TypeError and no template was written at all, on a schema that built fine the moment its
     * example was taken away. On these columns the example is formatted by the type alone, the
     * way the template's own cell format already sees the column: no closure, no symbol.
     */
    #[Test]
    public function anExampleOnATypedColumnLeavesOutEverythingThatNeedsARow(): void
    {
        $rowOnly = static fn (mixed $raw, array $row): string => 'row '.$row['id'];

        $sheet = $this->buildWith(Schema::make('doc')->fields(
            Field::money('balance')->label('col.balance')
                ->currency(static fn (array $row): string => $row['currencyCode'])
                ->example(1250.5),
            Field::money('fixed')->label('col.fixed')->currency('TRY')->example(1250.5),
            Field::decimal('rate')->label('col.rate')->decimals(2)->format($rowOnly)->example(1250.5),
            Field::date('issuedAt')->label('col.date')->format($rowOnly)->example(new DateTimeImmutable('2026-01-31')),
            Field::string('code')->label('col.code')->format($rowOnly)->example(501),
        ))->getSheet(0);

        self::assertSame('Example: 1.250,50', $sheet->getDataValidation('A3')->getPrompt());
        // A fixed code needs no row, but the cell format has no symbol either: the user types a bare number.
        self::assertSame('Example: 1.250,50', $sheet->getDataValidation('B3')->getPrompt());
        self::assertSame('Example: 1.250,50', $sheet->getDataValidation('C3')->getPrompt());
        self::assertSame('Example: 31.01.2026', $sheet->getDataValidation('D3')->getPrompt());
        self::assertSame('Example: 501', $sheet->getDataValidation('E3')->getPrompt());
    }

    /**
     * A dropdown column is the exception, on purpose: its example goes through the very call that
     * built its list, so it reads exactly like its entry in the list.
     */
    #[Test]
    public function aDropdownExampleGoesThroughTheSameFormatClosureAsItsList(): void
    {
        $spreadsheet = $this->buildWith(Schema::make('doc')->fields(
            Field::bool('inStock')->label('col.stock')
                ->format(static fn (mixed $raw, mixed $row): string => true === $raw ? 'Var' : 'Yok')
                ->example(true),
        ));
        $validation = $spreadsheet->getSheet(0)->getDataValidation('A3');

        self::assertSame('Example: Var', $validation->getPrompt());
        self::assertSame(['Var', 'Yok'], $this->dropdownOptions($spreadsheet, $validation));
    }

    #[Test]
    public function aDropdownExampleIsTheTranslatedOptionAndTheListIsKept(): void
    {
        $spreadsheet = $this->buildWith(Schema::make('doc')->fields(
            Field::bool('isActive')->label('col.active')->example(true),
        ));
        $validation = $spreadsheet->getSheet(0)->getDataValidation('A3');

        self::assertSame('Example: Evet', $validation->getPrompt());
        self::assertSame(['Evet', 'Hayır'], $this->dropdownOptions($spreadsheet, $validation));
    }

    #[Test]
    public function aTextExampleIsTranslatedWhenTheCatalogueKnowsItAndAClosureIsUsedAsIs(): void
    {
        $translator = new ArrayTranslator(['tr' => ['col.city' => 'Şehir', 'example.city' => 'İstanbul']]);

        $sheet = $this->buildWith(Schema::make('doc')->fields(
            Field::string('city')->label('col.city')->example('example.city'),
            Field::string('note')->label('col.note')
                ->example(static fn (string $locale): string => 'tr' === $locale ? 'Kapıda ödeme' : 'Cash on delivery'),
        ), translator: $translator)->getSheet(0);

        self::assertSame('Example: İstanbul', $sheet->getDataValidation('A3')->getPrompt());
        self::assertSame('Example: Kapıda ödeme', $sheet->getDataValidation('B3')->getPrompt());
    }

    /**
     * Without an example nothing changes: a text column still carries no validation at all and
     * a dropdown gets no message bolted on.
     */
    #[Test]
    public function aColumnWithoutAnExampleGetsNoMessage(): void
    {
        $sheet = $this->build()->getSheet(0);

        self::assertFalse($sheet->dataValidationExists('A3'));
        self::assertFalse($sheet->getDataValidation('C3')->getShowInputMessage());
    }

    #[Test]
    public function theWordsComeFromTheOptionsAndAreTranslated(): void
    {
        $translator = new ArrayTranslator(['tr' => [
            'col.code' => 'Kod',
            'tpl.example' => 'Örnek',
            'tpl.required' => 'Zorunlu',
        ]]);

        $validation = $this->buildWith(
            Schema::make('doc')->fields(Field::string('code')->label('col.code')->required()->example('120.01.001')),
            new TemplateOptions(exampleWord: 'tpl.example', requiredWord: 'tpl.required'),
            $translator,
        )->getSheet(0)->getDataValidation('A3');

        self::assertSame("Örnek: 120.01.001\nZorunlu", $validation->getPrompt());
    }

    /**
     * Excel refuses an input-message title over 32 characters and a text over 255, and opens
     * such a workbook saying it needs repairing. The cut counts characters, not bytes.
     */
    #[Test]
    public function aLongLabelAndExampleAreCutToWhatExcelAccepts(): void
    {
        $label = str_repeat('Ğ', 40);

        $validation = $this->buildWith(Schema::make('doc')->fields(
            Field::string('code')->label(static fn (): string => $label)->required()->example(str_repeat('ş', 300)),
        ))->getSheet(0)->getDataValidation('A3');

        self::assertSame(32, mb_strlen($validation->getPromptTitle()));
        self::assertStringEndsWith('…', $validation->getPromptTitle());
        self::assertLessThanOrEqual(255, mb_strlen($validation->getPrompt()));
        // The requiredness line survives; only the example gives way.
        self::assertStringEndsWith("\nRequired", $validation->getPrompt());
    }

    /**
     * Excel counts the message TEXT in UTF-16 units, not characters.
     *
     * 128 emoji are 128 characters but 256 units, and Excel refuses to open such a workbook —
     * measured in Excel, after a character count had let exactly that through. The mixed column
     * is the realistic one: ordinary letters with a few emoji pass 255 units while staying under
     * 255 characters.
     */
    #[Test]
    public function theMessageIsCutInTheUnitsExcelCounts(): void
    {
        $sheet = $this->buildWith(Schema::make('doc')->fields(
            Field::string('code')->label('col.code')->required()->example(str_repeat('😀', 300)),
            Field::string('note')->label('col.note')->example(str_repeat('ş', 230).str_repeat('😀', 10)),
        ))->getSheet(0);

        foreach (['A3', 'B3'] as $cell) {
            $prompt = $sheet->getDataValidation($cell)->getPrompt();

            self::assertLessThanOrEqual(255, intdiv(\strlen(mb_convert_encoding($prompt, 'UTF-16LE', 'UTF-8')), 2), $cell);
            self::assertTrue(mb_check_encoding($prompt, 'UTF-8'), $cell.': no character may be split.');
            self::assertStringEndsWith('…', explode("\n", $prompt)[0], $cell);
        }

        self::assertStringEndsWith("\nRequired", $sheet->getDataValidation('A3')->getPrompt());
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
