<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Template;

use Closure;
use Nouxwell\Tabula\Exception\ExportException;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Port\Translator;
use Nouxwell\Tabula\Schema\Align;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Value\FormatContext;
use Nouxwell\Tabula\Value\FormatterRegistry;
use PhpOffice\PhpSpreadsheet\Cell\AddressRange;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetXlsxWriter;
use UnitEnum;

/**
 * Produces an empty import template — the contract of the "return" leg of the round trip.
 *
 * ★ THE LAYOUT IS THE DECISION THAT EXPLAINS THIS ENTIRE CLASS:
 *
 *     row 1 → CANONICAL FIELD KEYS (hidden in xlsx)
 *     row 2 → translated labels (what the user reads)
 *     row 3 → data
 *
 * The file's identity is the key, not the header TEXT. The import side can reach its decision
 * without looking at any marker: if EVERY non-blank cell in row 1 lands on a key in the
 * schema, that row is the key row; if it does not, row 1 is the label header and the data
 * starts at row 2. This is why a template we produce makes a flawless round trip while a file
 * the user prepared by hand keeps working too.
 *
 * In the system this replaces the file's identity was the TRANSLATED HEADER STRING: a single
 * word changed in a translation file silently made every template users had on disk
 * unreadable. The hidden key row here is the repair of that flaw.
 */
final class TemplateBuilder
{
    /** Excel's sheet-name limit — the same as in `XlsxWriter`. */
    private const int TITLE_MAX_LENGTH = 31;

    /**
     * The characters Excel forbids in a sheet name.
     *
     * Identical to `XlsxWriter::INVALID_TITLE_CHARACTERS`; it was copied because the constant
     * over there is `private`. The name usually comes from the schema title, that is, from a
     * translation — a single slash, as in "Sales/Return", blows up `setTitle()`'s validation
     * and template generation would die over a text the user has no control over whatsoever.
     *
     * @var list<string>
     */
    private const array INVALID_TITLE_CHARACTERS = ['*', ':', '/', '\\', '?', '[', ']'];

    /** For templates whose name consists entirely of forbidden characters (or clashes with the helper sheet). */
    private const string FALLBACK_TITLE = 'Sablon';

    /**
     * The hidden helper sheet that holds the sources of the dropdown lists.
     *
     * The options could just as well have been embedded straight into the validation
     * ("Evet,Hayır"), but Excel limits an embedded list to 255 CHARACTERS and opens a file
     * that exceeds the limit saying it "needs to be repaired". An order enum with thirty cases
     * passes that limit comfortably. A range reference has no limit, and on top of that
     * columns sharing the same list all look at a single range.
     */
    private const string LIST_SHEET_TITLE = '_lists';

    /**
     * The registry that produces both the output text and the dropdown options from a cell value.
     *
     * ★ That the two derive from the SAME call is this class's second contract. The system
     * this replaces produced the text it wrote into the cell from one translation family
     * (`general.yes`) and the template's allowed-value list from ANOTHER family (`form.true`);
     * the upshot was that the value it wrote itself was not in its own allowed-value list.
     * Here the option list is produced by asking the very formatter the export uses — for the
     * two to drift apart is impossible.
     */
    private readonly FormatterRegistry $formatters;

    public function __construct(
        private readonly Translator $translator,
        private readonly TabulaSettings $settings,
        private readonly TemplateOptions $options = new TemplateOptions(),
    ) {
        $this->formatters = FormatterRegistry::default();
    }

    /**
     * Writes the template to disk.
     *
     * @return string the path of the file written (the same path that was given)
     */
    public function write(Schema $schema, string $path, string $locale): string
    {
        // A field that is `Field::only()` visible in PDF must not be in the template either:
        // offering the user a column they cannot fill in and the import does not expect breeds
        // the question "why does this field not work".
        $schema = $schema->forFormat(Format::Xlsx);
        $fields = array_values($schema->getFields());

        if ([] === $fields) {
            throw ExportException::noColumns($schema->getName(), Format::Xlsx);
        }

        $this->guardTarget($path);

        $context = new FormatContext(
            locale: $locale,
            translator: $this->translator,
            settings: $this->settings,
            format: Format::Xlsx,
        );

        $keyRow = $this->options->includeKeyRow ? 1 : null;
        $labelRow = null === $keyRow ? 1 : 2;
        $firstDataRow = $labelRow + 1;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator($this->options->xlsx->creator);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle($this->sheetTitle($schema, $context), false, true);

            $letters = [];
            foreach ($fields as $index => $field) {
                $letters[] = Coordinate::stringFromColumnIndex($index + 1);
            }

            $lastLetter = $letters[\count($letters) - 1];

            $this->writeHeader($sheet, $fields, $letters, $keyRow, $labelRow, $context);
            $this->applyColumnStyles($sheet, $fields, $letters);

            // ★ AFTER applyColumnStyles, never inside writeHeader(). The column style is applied
            // to the WHOLE column — the header cell included — so anything writeHeader() sets is
            // overwritten a moment later. This cost one debugging round: the alignment was set,
            // and then silently undone.
            if ($this->options->xlsx->autoFilter) {
                $this->unRightAlignHeaders($sheet, $fields, $letters, $labelRow);
            }
            $lastSampleRow = $this->createSampleRows($sheet, $lastLetter, $firstDataRow);
            $this->applyDropdowns($spreadsheet, $sheet, $fields, $letters, $firstDataRow, $context);
            $this->applyTypeValidation($sheet, $fields, $letters, $firstDataRow);

            if ($this->options->xlsx->freezeHeader) {
                // "Freeze A3" = both the key AND the label row stay fixed. `XlsxWriter` freezes
                // A2 for its single header row; the difference here comes precisely from the
                // presence of the hidden key row.
                $sheet->freezePane('A'.$firstDataRow);
            }

            if ($this->options->xlsx->autoFilter) {
                $sheet->setAutoFilter('A'.$labelRow.':'.$lastLetter.max($labelRow, $lastSampleRow));
                $this->makeRoomForFilterButtons($sheet, $letters);
            }

            // The user should open the file on the DATA sheet; the hidden "_lists" cannot be
            // the active tab in any case.
            $spreadsheet->setActiveSheetIndex(0);

            $writer = new SpreadsheetXlsxWriter($spreadsheet);
            $writer->save($path);
        } catch (SpreadsheetException $exception) {
            throw ExportException::unwritableTarget($path, $exception->getMessage());
        } finally {
            // Worksheet and Spreadsheet hold on to each other; a refcount-based garbage
            // collector cannot break that cycle. Essential in long-lived workers that produce
            // one template after another.
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return $path;
    }

    // ---------------------------------------------------------------- header

    /**
     * @param list<Field>  $fields
     * @param list<string> $letters
     */
    private function writeHeader(
        Worksheet $sheet,
        array $fields,
        array $letters,
        ?int $keyRow,
        int $labelRow,
        FormatContext $context,
    ): void {
        foreach ($fields as $index => $field) {
            $letter = $letters[$index];

            if (null !== $keyRow) {
                // The key is written with an EXPLICIT type. The default binder turns a field
                // key such as "0501" into the number 501 and, when the file is read back, it
                // matches nothing at all — the entire meaning of the key row lies in its
                // matching exactly.
                $sheet->setCellValueExplicit($letter.$keyRow, $field->getKey(), DataType::TYPE_STRING);
            }

            $sheet->setCellValueExplicit($letter.$labelRow, $this->labelOf($field, $context), DataType::TYPE_STRING);

            $dimension = $sheet->getColumnDimension($letter);

            if (null === $field->getWidth()) {
                $dimension->setAutoSize(true);
            } else {
                $dimension->setWidth($field->getWidth());
            }
        }

        $lastLetter = $letters[\count($letters) - 1];
        $xlsx = $this->options->xlsx;

        // The header style is applied over a SINGLE range; applying it column by column would
        // have the same style hashed once per column.
        $header = $sheet->getStyle('A'.$labelRow.':'.$lastLetter.$labelRow);
        $header->getFont()->setBold($xlsx->boldHeader);
        $header->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB($xlsx->headerFill);
        $header->getBorders()
            ->getBottom()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()
            ->setARGB($xlsx->headerBorderColor);
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Required columns in light red — the SAME rule as on export (see `XlsxWriter`).
        // This was the one visual cue in the templates of the system this replaces that
        // actually worked, and it was kept.
        foreach ($fields as $index => $field) {
            if (!$field->isRequired()) {
                continue;
            }

            $sheet->getStyle($letters[$index].$labelRow)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB($xlsx->requiredHeaderFill);
        }

        if (null !== $keyRow && $this->options->hideKeyRow) {
            // The row is HIDDEN, not deleted: the user does not see the technical keys, but
            // the file goes on carrying them. The identity staying inside the file is the sole
            // reason the round trip is independent of translation.
            $sheet->getRowDimension($keyRow)->setVisible(false);
        }
    }

    private function labelOf(Field $field, FormatContext $context): string
    {
        $label = $field->getLabel();

        if ($label instanceof Closure) {
            return (string) $label($context->locale);
        }

        // If no label was given, the key itself becomes the header — a column is never left headerless.
        return $context->trans($label ?? $field->getKey());
    }

    // ---------------------------------------------------------------- column formats

    /**
     * Number format and alignment, per column.
     *
     * @param list<Field>  $fields
     * @param list<string> $letters
     */
    private function applyColumnStyles(Worksheet $sheet, array $fields, array $letters): void
    {
        foreach ($fields as $index => $field) {
            $letter = $letters[$index];

            // ★ The range MUST be of the form "B1:B1048576", that is, it has to start at ROW 1.
            // PhpSpreadsheet's recognition of a "whole column" hinges on the pattern
            // `^[A-Z]+1:[A-Z]+1048576$`: if it matches, only the column's default style is
            // updated; if it does not, the range counts as a CELL range and a cell object is
            // genuinely CREATED for the million coordinates in between. Writing
            // "B3:B1048576" (starting at the data row) feels instinctively right and blows the
            // file up in memory in a single line.
            //
            // The header cells are unaffected by this: they have their own style index, and
            // the column default is applied only to unstyled cells.
            $range = $letter.'1:'.$letter.AddressRange::MAX_ROW;

            $format = $this->numberFormatFor($field);

            if (null !== $format) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
            }

            $sheet->getStyle($range)
                ->getAlignment()
                ->setHorizontal(self::horizontalOf($field->getAlign()));
        }
    }

    /**
     * Takes right alignment off the HEADER cells — the data below keeps it.
     *
     * The filter button is drawn inside the cell, hard against its right edge, and a
     * right-aligned label is pushed to that same edge. The two land on top of each other:
     * "Döviz Kuru" renders as "Döviz Ku▾" however wide the column is, because widening a
     * right-aligned cell adds the room on the LEFT, where nothing needed it.
     *
     * Only right alignment collides. Left starts at the far side and centre keeps its distance
     * once the column has headroom — measured on a real file, the left and centre headers were
     * clean and every right-aligned one was clipped.
     *
     * The columns themselves are untouched: numbers still line up on the decimal point, which
     * is the reason they were right-aligned in the first place. Only the label moves, and a
     * left label over right-aligned figures is the ordinary arrangement in a spreadsheet.
     *
     * @param list<Field>  $fields
     * @param list<string> $letters
     */
    private function unRightAlignHeaders(Worksheet $sheet, array $fields, array $letters, int $labelRow): void
    {
        foreach ($fields as $index => $field) {
            if (Align::Right !== $field->getAlign()) {
                continue;
            }

            $sheet->getStyle($letters[$index].$labelRow)
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }
    }

    /**
     * Widens auto-sized columns so the filter button does not sit on top of the header.
     *
     * Two of this class's own defaults were fighting each other. Auto-sizing measures the TEXT
     * and stops there; the auto-filter then draws its button INSIDE the cell, hard against the
     * right edge. A column sized exactly to its header therefore loses its last two characters
     * behind the arrow — "Döviz Kuru" reads as "Döviz Ku▾", and the wider the header the worse
     * it looks, because the button is a fixed width the measurement never knew about.
     *
     * The widths have to be resolved BEFORE they can be adjusted: while a column is on
     * auto-size, its width is -1 and the real number is only worked out during save.
     * `calculateColumnWidths()` performs that measurement early, after which each column can be
     * given an explicit width — which also turns auto-size off, so the value survives the save
     * instead of being recomputed without the headroom.
     *
     * A column with a width set by hand is left alone: the caller said what they wanted.
     *
     * @param list<string> $letters
     */
    private function makeRoomForFilterButtons(Worksheet $sheet, array $letters): void
    {
        // Excel's filter button is a fixed ~2 width units. Three leaves the header clear of it
        // without opening a visible gap; two was still touching the last glyph.
        $headroom = 3.0;

        // Which columns are ours to touch has to be noted BEFORE the measurement: resolving a
        // width clears the auto-size flag, and afterwards an auto-sized column is
        // indistinguishable from one the caller sized by hand.
        $auto = [];
        foreach ($letters as $letter) {
            if ($sheet->getColumnDimension($letter)->getAutoSize()) {
                $auto[] = $letter;
            }
        }

        if ([] === $auto) {
            return;
        }

        $sheet->calculateColumnWidths();

        foreach ($auto as $letter) {
            $dimension = $sheet->getColumnDimension($letter);
            $width = $dimension->getWidth();

            // -1 means the measurement produced nothing to widen (an empty column).
            if ($width <= 0) {
                continue;
            }

            $dimension->setAutoSize(false);
            $dimension->setWidth($width + $headroom);
        }
    }

    /**
     * The field's Excel number format code; null (General) if no format is needed.
     *
     * A user typing into a quantity column sees three decimals, one typing into a money column
     * sees two — in other words, the template looks the same as the file the export produces.
     */
    private function numberFormatFor(Field $field): ?string
    {
        $type = $field->getType();
        $numbers = $this->settings->numbers;

        return match (true) {
            // A text column is EXPLICITLY text-formatted: so that an account code like "0501"
            // or a serial number like "12.05" is not turned into a number or a date by Excel.
            // Leading zeros being eaten was the most frequently reported import bug of the
            // system this replaces.
            FieldType::String === $type => NumberFormat::FORMAT_TEXT,
            // The currency symbol is DELIBERATELY absent: the symbol depends on the row's
            // currency (`Field::currency()` may be a closure) and an empty template has no
            // rows. Rather than stamping the wrong symbol we give a bare number format.
            $type->isNumeric() => $numbers->excelFormatCode(max(0, $field->getDecimals() ?? $numbers->digitsFor($type))),
            $type->isTemporal() => $this->settings->dates->excelFormatFor($type),
            // Enum/options/bool columns stay General: had we given '@', Excel would stamp the
            // "number stored as text" warning on option labels that look like numbers.
            default => null,
        };
    }

    /**
     * Creates pre-formatted empty rows beneath the header.
     *
     * ★ This is the only place in the library that DELIBERATELY creates empty cells
     * (`XlsxWriter::writeRow()` does exactly the opposite). When a style is applied to a CELL
     * range, PhpSpreadsheet creates every coordinate in between; in a template that is the
     * desired behaviour — the user sees the grid to be filled in — and the cost is bounded by
     * `sampleRows` × columns, not by the number of rows.
     *
     * @return int the last row created; 0 if none were created
     */
    private function createSampleRows(Worksheet $sheet, string $lastLetter, int $firstDataRow): int
    {
        $rows = $this->options->sampleRows;

        if ($rows < 1) {
            return 0;
        }

        $lastRow = $firstDataRow + $rows - 1;

        $sheet->getStyle('A'.$firstDataRow.':'.$lastLetter.$lastRow)
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_HAIR)
            ->getColor()
            ->setARGB($this->options->xlsx->headerBorderColor);

        return $lastRow;
    }

    // ---------------------------------------------------------------- dropdown lists

    /**
     * Adds a dropdown (data validation) to bool/enum/options columns.
     *
     * Columns that share the same set of options all look at a SINGLE range: the set is
     * deduplicated by md5. If ten enum columns use the same list, one single column appears on
     * the helper sheet — the file gets smaller, and a user who wants to correct the list by
     * hand corrects it in one place.
     *
     * @param list<Field>  $fields
     * @param list<string> $letters
     */
    private function applyDropdowns(
        Spreadsheet $spreadsheet,
        Worksheet $sheet,
        array $fields,
        array $letters,
        int $firstDataRow,
        FormatContext $context,
    ): void {
        /** @var array<int, list<string>> $optionsByIndex */
        $optionsByIndex = [];

        foreach ($fields as $index => $field) {
            if (!$field->getType()->isEnumerable()) {
                continue;
            }

            $options = $this->optionsFor($field, $context);

            // A field whose options are derived at runtime (from the row) can come back empty;
            // in that case no list is placed. An empty dropdown is worse than no dropdown at
            // all: Excel locks the cell and the user cannot type anything.
            if ([] !== $options) {
                $optionsByIndex[$index] = $options;
            }
        }

        if ([] === $optionsByIndex) {
            return;
        }

        $lists = $spreadsheet->createSheet();
        $lists->setTitle(self::LIST_SHEET_TITLE, false, true);
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        /** @var array<string, string> $rangeByHash md5 => "_lists!\$A\$1:\$A\$5" */
        $rangeByHash = [];
        $nextColumn = 1;

        foreach ($optionsByIndex as $index => $options) {
            $hash = md5(implode("\0", $options));

            if (!isset($rangeByHash[$hash])) {
                $letter = Coordinate::stringFromColumnIndex($nextColumn);
                ++$nextColumn;

                foreach ($options as $row => $option) {
                    // EXPLICIT type: had an option label such as "0501" or "2024" been turned
                    // into a number, the value in the list would not match the text written
                    // into the cell.
                    $lists->setCellValueExplicit($letter.($row + 1), $option, DataType::TYPE_STRING);
                }

                $rangeByHash[$hash] = sprintf(
                    '%s!$%s$1:$%s$%d',
                    self::LIST_SHEET_TITLE,
                    $letter,
                    $letter,
                    \count($options),
                );
            }

            $validation = new DataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            // Leaving it blank IS ALLOWED: the requiredness check is the import's job (it
            // turns into a `RowError`). Had we made Excel do it, the user would run into a
            // warning box merely for moving through a row they have not filled in yet.
            $validation->setAllowBlank(true);
            // ★ `setShowDropDown(true)` = SHOW the arrow. In OOXML the `showDropDown`
            // attribute means the OPPOSITE ("hide the list") and PhpSpreadsheet applies that
            // inversion itself while writing; if we pass `false`, the validation works but no
            // arrow appears in the cell.
            $validation->setShowDropDown(true);
            // No error TEXT is given: Excel shows its own localised warning. Had we put our
            // own string here, it would be pinned to the application's language (or to an
            // untranslated key) whatever the user's Excel language happened to be.
            $validation->setShowErrorMessage(true);
            $validation->setFormula1($rangeByHash[$hash]);

            // The range reaches to the end of the column. A data-validation sqref DOES NOT
            // CREATE cells — it is merely a range string — so using a whole column here is
            // free, and on top of that the list stays valid even if the user pastes in
            // thousands of rows.
            $sheet->setDataValidation(
                $letters[$index].$firstDataRow.':'.$letters[$index].AddressRange::MAX_ROW,
                $validation,
            );
        }
    }

    /**
     * Rejects values of the wrong TYPE as they are typed, before the file is ever uploaded.
     *
     * The import parser catches these mistakes anyway, but only once the user has filled the
     * whole file in and sent it back. Catching them in the cell is the difference between "row
     * 348 could not be read" and the cursor never leaving the cell in the first place.
     *
     * Only shape is enforced here, never business meaning. Requiredness, ranges and
     * cross-field rules stay on the import side, where they can produce a `RowError` that
     * names the row and the field. A template is not the place to encode policy: it is opened
     * once, kept for months, and filled in against rules that have moved on since.
     *
     * String columns get NOTHING. They are already formatted as text so that "0501" keeps its
     * leading zero, and any validation on top of that would only ever fire on a value Excel
     * has no opinion about.
     *
     * @param list<Field>  $fields
     * @param list<string> $letters
     */
    private function applyTypeValidation(
        Worksheet $sheet,
        array $fields,
        array $letters,
        int $firstDataRow,
    ): void {
        foreach ($fields as $index => $field) {
            $type = $field->getType();

            // An enumerable column already carries a list validation from applyDropdowns();
            // a second rule on the same range would simply overwrite it.
            if ($type->isEnumerable()) {
                continue;
            }

            $validation = match (true) {
                $type->isTemporal() => $this->dateValidation(),
                FieldType::Integer === $type => $this->numberValidation(DataValidation::TYPE_WHOLE),
                $type->isNumeric() => $this->numberValidation(DataValidation::TYPE_DECIMAL),
                default => null,
            };

            if (null === $validation) {
                continue;
            }

            // Whole column, exactly as in applyDropdowns(): an sqref is only a range string
            // and creates no cells, so this stays free however far the user pastes.
            $sheet->setDataValidation(
                $letters[$index].$firstDataRow.':'.$letters[$index].AddressRange::MAX_ROW,
                $validation,
            );
        }
    }

    /**
     * "This has to be a number" — expressed the only way Excel understands it.
     *
     * Excel has no bare "is numeric" rule; a decimal validation is required to carry an
     * operator and bounds. The bounds below are therefore deliberately absurd: they exist so
     * that the rule is well-formed, not to express a limit. ±1e15 sits below the 2^53 mark
     * where doubles stop representing integers exactly, so nothing a spreadsheet can hold
     * faithfully is excluded.
     *
     * Picking tighter, "sensible-looking" bounds would be the mistake here. A quantity column
     * capped at a million rejects the one legitimate stock count that exceeds it, and the user
     * has no way to see why the cell refuses their number.
     */
    private function numberValidation(string $type): DataValidation
    {
        $validation = new DataValidation();
        $validation->setType($type);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
        $validation->setFormula1('-1E15');
        $validation->setFormula2('1E15');

        return $this->finishValidation($validation);
    }

    /**
     * "This has to be a date.".
     *
     * The window is wide on purpose but not unbounded: 1900 is where Excel's own serial
     * numbering begins, and an upper edge in the far future still catches the mistake this
     * rule is really for — a date typed as text, or a stray number landing in a date column,
     * which would otherwise be read back as some day in 1901.
     */
    private function dateValidation(): DataValidation
    {
        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_DATE);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
        // Excel serials: 1900-01-01 and 2199-12-31.
        $validation->setFormula1('1');
        $validation->setFormula2('109574');

        return $this->finishValidation($validation);
    }

    private function finishValidation(DataValidation $validation): DataValidation
    {
        // Blank is allowed, for the same reason as in applyDropdowns(): requiredness belongs
        // to the import, and a warning box for merely tabbing through an unfilled row teaches
        // users to switch validation off.
        $validation->setAllowBlank(true);
        // No error text of our own — Excel shows its message in the language of the user's
        // Excel, which ours could not match.
        $validation->setShowErrorMessage(true);

        return $validation;
    }

    /**
     * The field's TRANSLATED options as they will appear in the dropdown.
     *
     * The values are passed one by one through the formatter the export uses. So the text in
     * the list is whatever the cell would say if we exported that same value; for the two to
     * drift apart is impossible. Two options that land on the same text are deduplicated —
     * beyond showing a repeated row in Excel, they would also be indistinguishable on the
     * parsing side.
     *
     * @return list<string>
     */
    private function optionsFor(Field $field, FormatContext $context): array
    {
        $formatter = $this->formatters->for($field->getType());

        /** @var list<mixed> $values */
        $values = match ($field->getType()) {
            // The bool options come from a SINGLE family:
            // `TabulaSettings::$boolTrueKey`/`$boolFalseKey`.
            // (The system this replaces had three parallel "yes/no" translation families.)
            FieldType::Bool => [true, false],
            FieldType::Enum => self::enumCases($field),
            FieldType::Options => self::optionKeys($field),
            default => [],
        };

        $labels = [];

        foreach ($values as $value) {
            // ★ A template HAS NO ROWS: `$row = null` is what reaches the formatter. If the
            // field's own `format()` closure or `options()` closure looks at the row, it must
            // be prepared for null — that is the one contract stated in the documentation.
            $text = $formatter->format($value, $field, null, $context)->text;

            if ('' === $text || \in_array($text, $labels, true)) {
                continue;
            }

            $labels[] = $text;
        }

        return $labels;
    }

    /**
     * All of the enum's cases.
     *
     * @return list<UnitEnum>
     */
    private static function enumCases(Field $field): array
    {
        $enumClass = $field->getEnumClass();

        if (null === $enumClass || !enum_exists($enumClass)) {
            return [];
        }

        return array_values($enumClass::cases());
    }

    /**
     * The keys of the options map.
     *
     * @return list<int|string>
     */
    private static function optionKeys(Field $field): array
    {
        $options = $field->getOptions();

        if ($options instanceof Closure) {
            // The closure is called with a null row (see `optionsFor()`); if a row-dependent
            // list comes back empty here, no dropdown is placed on that column.
            $options = $options(null);
        }

        return \is_array($options) ? array_keys($options) : [];
    }

    // ---------------------------------------------------------------- internals

    /**
     * Reduces the schema title to a sheet name Excel will accept.
     *
     * The same rules as `XlsxWriter::resolveTitle()`; no deduplication, because here there are
     * only two sheets. The one extra rule is the CLASH with the helper sheet: if the schema
     * title really were "_lists", `createSheet()` would blow up over the duplicate name.
     */
    private function sheetTitle(Schema $schema, FormatContext $context): string
    {
        $title = $schema->getTitle();

        $resolved = match (true) {
            $title instanceof Closure => (string) $title($context->locale),
            null === $title => $schema->getName(),
            default => $context->trans($title),
        };

        $resolved = str_replace(self::INVALID_TITLE_CHARACTERS, '-', $resolved);

        // An apostrophe cannot sit at the start or the end: Excel wraps a sheet name in single
        // quotes inside formulas and a quote at the edge collides with its own escaping.
        $resolved = trim($resolved, " '");

        // CHARACTERS, not bytes: `substr()` would cut a name such as "Ürün Grubu" in the
        // middle of a multi-byte letter and produce invalid UTF-8.
        if (mb_strlen($resolved) > self::TITLE_MAX_LENGTH) {
            $resolved = rtrim(mb_substr($resolved, 0, self::TITLE_MAX_LENGTH));
        }

        if ('' === $resolved || 0 === strcasecmp($resolved, self::LIST_SHEET_TITLE)) {
            return self::FALLBACK_TITLE;
        }

        return $resolved;
    }

    /**
     * Verifies AS THE VERY FIRST STEP that the target path is writable.
     *
     * Since in xlsx the file is only written right at the end, an unwritable path would blow
     * up AFTER the whole sheet had been built — the same reasoning and the same checks as
     * `XlsxWriter::guardTarget()`.
     */
    private function guardTarget(string $path): void
    {
        if ('' === trim($path)) {
            throw ExportException::unwritableTarget($path, 'the target path is empty');
        }

        if (is_file($path)) {
            if (!is_writable($path)) {
                throw ExportException::unwritableTarget($path, 'the file exists and is read-only');
            }

            return;
        }

        $directory = \dirname($path);

        if (!is_dir($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('directory "%s" does not exist', $directory));
        }

        if (!is_writable($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('no write permission for directory "%s"', $directory));
        }
    }

    /** Converts an `Align` into Excel's horizontal alignment code. */
    private static function horizontalOf(Align $align): string
    {
        return match ($align) {
            Align::Right => Alignment::HORIZONTAL_RIGHT,
            Align::Center => Alignment::HORIZONTAL_CENTER,
            default => Alignment::HORIZONTAL_LEFT,
        };
    }
}
