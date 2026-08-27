<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Value\Cell;
use PhpOffice\PhpSpreadsheet\Cell\AddressRange;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetXlsxWriter;

/**
 * An .xlsx writer built on PhpSpreadsheet.
 *
 * Unlike `CsvWriter`, there is NO real streaming here: xlsx is a ZIP archive, and the shared string
 * table and the style table can only be written once the last row is known. That is why
 * PhpSpreadsheet keeps the workbook in memory, and we cannot change that. What we can change — and
 * what the system this replaces missed — are three things:
 *
 *  1. `close()` writes the file STRAIGHT to disk. The old engine opened `ob_start()`, pushed the
 *     writer to `php://output`, pulled the whole archive into a single PHP string with
 *     `ob_get_clean()` and wrote that string into the file: a second copy the size of the file,
 *     right next to the workbook. That is why a fifty-thousand-row export hit `memory_limit`.
 *  2. An empty cell is NEVER created at all (see `writeRow()`), and styles are applied over ranges
 *     (see `applyColumnAlignment()`). Both are places where the "easy" way produces as many objects
 *     as rows×columns.
 *  3. Values are written with an explicit type: a number stays a number, text stays text. The old
 *     engine wrote everything with `setCellValue()`, and the default binder turned a code like
 *     "0501" into 501 and a serial number like "12.05" into a date.
 *
 * The lifecycle is exactly the order given in the `Writer` interface; violations throw
 * `WriterException`.
 */
final class XlsxWriter implements Writer
{
    /** Excel's sheet-name limit. */
    private const int TITLE_MAX_LENGTH = 31;

    /**
     * The characters Excel forbids in a sheet name.
     *
     * The list is identical to PhpSpreadsheet's `Worksheet::INVALID_CHARACTERS` constant; it was
     * copied because that constant is `private`. If we do not clean the name up front,
     * `setTitle()`'s validation throws and the export dies because of a data value the user has no
     * control over whatsoever (a group name such as "Sales/Return").
     *
     * @var list<string>
     */
    private const array INVALID_TITLE_CHARACTERS = ['*', ':', '/', '\\', '?', '[', ']'];

    /** The base name for sheets whose name consists entirely of forbidden characters. */
    private const string FALLBACK_TITLE = 'Sheet';

    private ?Spreadsheet $spreadsheet = null;

    /** The sheet currently being written; null while there is no sheet. */
    private ?Worksheet $sheet = null;

    /** The target path given to `open()`. */
    private ?string $path = null;

    /** @var list<Column> the columns of the active sheet */
    private array $columns = [];

    /**
     * The column letters, in the same order as the columns.
     *
     * Produced once at the start of the sheet instead of being recomputed for every cell; building
     * a coordinate comes down to something as cheap as `$letters[$i].$row`.
     *
     * @var list<string>
     */
    private array $letters = [];

    /** The letter of the last column — for the auto filter and the header range. */
    private string $lastLetter = 'A';

    /** Which sheet we are on; 0 means the ready-made (default) sheet has not been used yet. */
    private int $sheetIndex = 0;

    /**
     * The sheet names already used (folded to lower case) — for de-duplicating names.
     *
     * @var array<string, true>
     */
    private array $usedTitles = [];

    /** The next data row; since the header sits on row 1, data starts at 2. */
    private int $nextRow = 2;

    /** @var list<string> the file paths written */
    private array $paths = [];

    /**
     * The appearance options (producer name, header colours, freeze/filter).
     *
     * The ARGB format of the colours and the rationale behind the defaults live on `XlsxOptions`.
     */
    public function __construct(
        private readonly XlsxOptions $options = new XlsxOptions(),
    ) {
    }

    public function open(string $path): void
    {
        if (null !== $this->spreadsheet) {
            throw WriterException::alreadyOpen();
        }

        // The target is checked at the VERY FIRST step. Since in xlsx the file is only written in
        // `close()`, an unwritable path would blow up AFTER every row had been processed: the user
        // would wait for minutes and then get a "permission denied" error. The check is as cheap as
        // an `is_dir()`.
        $this->guardTarget($path);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator($this->options->creator);

        // The default sheet IS NOT DELETED HERE. `new Spreadsheet()` creates a sheet named
        // "Worksheet"; deleting it and then calling `createSheet()` would be needless object
        // traffic, while not touching it at all would leave an empty tab at the start of every
        // file. Instead the first `startSheet()` takes that sheet over (see `sheetIndex`).
        $this->spreadsheet = $spreadsheet;
        $this->path = $path;
        $this->sheet = null;
        $this->sheetIndex = 0;
        $this->usedTitles = [];
        $this->paths = [];
    }

    /**
     * @param list<Column> $columns
     */
    public function startSheet(string $name, array $columns): void
    {
        $spreadsheet = $this->spreadsheet ?? throw WriterException::notOpened();

        // If a new sheet is started before the previous one has seen `finishSheet()`, we finish it
        // silently — the same behaviour as `CsvWriter`. Closing instead of throwing means the
        // pipeline does not have to make an extra call when the sheet strategy changes.
        $this->finalizeSheet();

        $sheet = 0 === $this->sheetIndex
            ? $spreadsheet->getActiveSheet()
            : $spreadsheet->createSheet();

        // 2nd argument false: there are no formulas in the output file, so scanning named formulas
        // on a rename makes no sense either. The 3rd argument (validation) was left on; even though
        // we clean the name ourselves, we do not switch off the library's last line of defence.
        $sheet->setTitle($this->resolveTitle($name), false, true);

        // The library may have changed the name anyway (if its own comparison diverges from our
        // case folding it appends a suffix of its own); we record the REAL name, otherwise the next
        // sheet would believe that name is still free.
        $this->usedTitles[mb_strtolower($sheet->getTitle())] = true;

        ++$this->sheetIndex;

        $this->sheet = $sheet;
        $this->columns = array_values($columns);
        $this->letters = [];
        $this->nextRow = 2;

        $this->writeHeader($sheet);
    }

    /**
     * @param list<Cell> $cells in the same order as the columns
     */
    public function writeRow(array $cells): void
    {
        $sheet = $this->sheet ?? throw WriterException::noActiveSheet();

        $row = $this->nextRow;
        $index = 0;

        foreach ($cells as $cell) {
            // If more cells arrive than there are columns, we derive the letter on the spot: this
            // should not happen, since the pipeline builds the cells from the column list itself,
            // but if it does, dropping the data silently would be the worst possible behaviour.
            $letter = $this->letters[$index] ?? Coordinate::stringFromColumnIndex($index + 1);
            ++$index;

            $value = $cell->value;

            if ($cell->isEmpty()) {
                // A cell that is REALLY empty is NEVER created. PhpSpreadsheet keeps an object for
                // every cell; "write empty" and "do not write at all" look the same in Excel but
                // differ in memory by as much as rows×columns. The old engine wrote exactly those
                // empty cells too.
                //
                // The criterion is `isEmpty()`, NOT `null === $value`: when
                // `TabulaSettings::$emptyText` is set to a placeholder ("-", "—") the value is null
                // but the TEXT is filled in, and that placeholder has to be written. The `default`
                // arm below takes care of it.
                continue;
            }

            $coordinate = $letter.$row;

            // Everything is written with an EXPLICIT type. `setCellValue()` goes through the default
            // binder and turns strings that look like numbers into numbers and ones that look like
            // dates into dates: account codes that lose their leading zero and serial numbers that
            // turn into dates all come from there.
            match (true) {
                is_string($value) => $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING),
                is_int($value), is_float($value) => $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC),
                is_bool($value) => $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_BOOL),
                // When an unexpected type arrives (e.g. DateTime, Stringable) we do not guess: the
                // cell already has a localised `text` representation, and that is written as text.
                default => $sheet->setCellValueExplicit($coordinate, $cell->text, DataType::TYPE_STRING),
            };

            if (null !== $cell->numberFormat) {
                // The format is applied per CELL, not per column: because `Field::currency()` can be
                // a closure, the same column can end up with a different currency — and therefore a
                // different format code — row by row. The cost is not as heavy as it looks;
                // PhpSpreadsheet hashes an identical style and shares it in a single record, so all
                // that sits in the cell is an integer index.
                $sheet->getStyle($coordinate)
                    ->getNumberFormat()
                    ->setFormatCode($cell->numberFormat);
            }
        }

        ++$this->nextRow;
    }

    public function finishSheet(): void
    {
        if (null === $this->sheet) {
            throw WriterException::noActiveSheet();
        }

        $this->finalizeSheet();
    }

    /**
     * Writes the workbook to disk and releases the memory.
     *
     * Unlike `CsvWriter::close()`, THIS METHOD CAN THROW: in xlsx all the work is done at the very
     * last moment, so an error here means "the file was never written"; swallowed silently, the
     * caller would offer the user a file that does not exist.
     *
     * @return list<string> the file paths written
     */
    public function close(): array
    {
        $spreadsheet = $this->spreadsheet;

        if (null === $spreadsheet) {
            // Already closed: so that it can be called repeatedly and used inside a `finally`.
            return $this->paths;
        }

        // If a sheet is still open we finish it first; otherwise the file is closed without the
        // auto filter and the alignment ever having been applied.
        $this->finalizeSheet();

        $path = $this->path ?? throw WriterException::notOpened();

        // Defined up front so that `unset()` can be called unconditionally inside `finally`; had it
        // been defined inside the `try`, the variable would be undefined if the constructor blew up.
        $writer = null;

        try {
            // STRAIGHT TO DISK. No intermediate buffer, no `php://output`, no pulling into a string.
            $writer = new SpreadsheetXlsxWriter($spreadsheet);
            $writer->save($path);
        } catch (SpreadsheetException $exception) {
            throw ExportException::unwritableTarget($path, $exception->getMessage());
        } finally {
            // Worksheet and Spreadsheet hold on to each other; PHP's refcount-based garbage
            // collector cannot break that cycle on its own, and the memory stays held until the end
            // of the request. `disconnectWorksheets()` severs the link and `unset()` drops the last
            // reference — essential for long-lived workers (messenger) that run export after export
            // in the same process.
            $spreadsheet->disconnectWorksheets();
            unset($writer, $spreadsheet);

            $this->spreadsheet = null;
            $this->path = null;
            $this->sheet = null;
            $this->sheetIndex = 0;
            $this->usedTitles = [];
        }

        $this->paths = [$path];

        return $this->paths;
    }

    /** Verifies at `open()` time that the target path is writable. */
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

        // `dirname()` returns '.' for relative paths; `is_dir('.')` gives the right answer too.
        $directory = \dirname($path);

        if (!is_dir($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('the "%s" directory does not exist', $directory));
        }

        if (!is_writable($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('no write permission for the "%s" directory', $directory));
        }
    }

    /** Writes the header row, sets up the column widths and freezes the header. */
    private function writeHeader(Worksheet $sheet): void
    {
        if ([] === $this->columns) {
            // The pipeline does not send a column-less sheet (`Schema::only()` already rejects an
            // empty selection); even so, we prefer leaving an empty sheet here to blowing up.
            $this->lastLetter = 'A';

            return;
        }

        foreach ($this->columns as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $this->letters[] = $letter;
            $this->lastLetter = $letter; // when the loop ends, the last column's letter is left behind

            // The header is written with an EXPLICIT type as well: a column name such as "2024" or
            // "12.05" would be turned into a number/date by the default binder.
            $sheet->setCellValueExplicit($letter.'1', $column->label, DataType::TYPE_STRING);

            $dimension = $sheet->getColumnDimension($letter);

            if (null === $column->width) {
                // Automatic width is computed at save time from the font metrics; it has a price,
                // but if the schema gives no width this is the only sensible behaviour.
                $dimension->setAutoSize(true);
            } else {
                $dimension->setWidth($column->width);
            }
        }

        // The header style is applied over a single range; applying it column by column would have
        // the same style hashed as many times as there are columns.
        $header = $sheet->getStyle('A1:'.$this->lastLetter.'1');
        $header->getFont()->setBold($this->options->boldHeader);
        $header->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB($this->options->headerFill);
        $header->getBorders()
            ->getBottom()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()
            ->setARGB($this->options->headerBorderColor);
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Required columns get a light red. When the file is downloaded as an import template, the
        // user can see from the header which column must not be left empty; this was the one visual
        // rule in the old templates that actually worked, and it was kept.
        foreach ($this->columns as $index => $column) {
            if (!$column->required) {
                continue;
            }

            $sheet->getStyle($this->letters[$index].'1')
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB($this->options->requiredHeaderFill);
        }

        // "Freeze A2" = row 1 stays put. On lists of a hundred thousand rows, the header not
        // disappearing while scrolling was one of the oldest requests coming from users.
        // It can be turned off for intermediate files going to a machine: it is of no use there but
        // it does produce XML.
        if ($this->options->freezeHeader) {
            $sheet->freezePane('A2');
        }
    }

    /**
     * Closes the sheet: since the row count is only known here, the auto filter and the alignment
     * are applied at this last step.
     *
     * Unlike `finishSheet()`, it returns silently when there is no sheet — `startSheet()` and
     * `close()` call it with a "tidy up whatever the state is" intent.
     */
    private function finalizeSheet(): void
    {
        $sheet = $this->sheet;

        if (null === $sheet) {
            return;
        }

        if ([] !== $this->columns) {
            // The auto filter has to cover the WHOLE header + data range. The old engine set it up
            // with a fixed range ("A1:Z1000"); on exports longer than a thousand rows the last rows
            // fell outside the filter, and when the user filtered, the data "disappeared".
            $lastRow = $this->nextRow - 1; // 1 if no data ever arrived (header only)

            if ($this->options->autoFilter) {
                $sheet->setAutoFilter('A1:'.$this->lastLetter.$lastRow);
            }

            $this->applyColumnAlignment($sheet);
        }

        $this->sheet = null;
        $this->columns = [];
        $this->letters = [];
        $this->lastLetter = 'A';
        $this->nextRow = 2;
    }

    /** Applies the columns' horizontal alignment from `Column::$align`. */
    private function applyColumnAlignment(Worksheet $sheet): void
    {
        foreach ($this->columns as $index => $column) {
            $letter = $this->letters[$index];

            // The range is DELIBERATELY a whole column ("B1:B1048576"). PhpSpreadsheet looks at the
            // shape of the range when it applies a style:
            //  - given a cell range (e.g. "B2:B50000") it calls `getCell()` for EVERY coordinate in
            //    between and CREATES the cells that do not exist — we would hand back in a single
            //    line all the memory we saved by never writing the null values;
            //  - in whole-column form only the cells that exist are visited, and for the empty ones
            //    the column's own default style (ColumnDimension.xfIndex) is updated.
            //
            // Since the application only merges the 'horizontal' key, the header's boldness, its
            // fill and its border, and the cells' number format, are all left as they are.
            $sheet->getStyle($letter.'1:'.$letter.AddressRange::MAX_ROW)
                ->getAlignment()
                ->setHorizontal(self::horizontalOf($column->align));
        }
    }

    /**
     * Reduces the sheet name to a title Excel will accept and that is unique within that file.
     *
     * The name usually comes FROM THE DATA (`GroupedSheets` turns a field's value into the sheet
     * name), which means the export can blow up the moment a user enters "Sales/Return" or a
     * customer title longer than 31 characters. Cleaning up here means not blowing up there.
     */
    private function resolveTitle(string $name): string
    {
        $title = str_replace(self::INVALID_TITLE_CHARACTERS, '-', $name);

        // An apostrophe cannot sit at the start or the end: Excel wraps the sheet name in single
        // quotes inside formulas, and a quote at the edge clashes with its own escaping.
        $title = trim($title, " '");
        $title = $this->truncate($title, self::TITLE_MAX_LENGTH);

        if ('' === $title) {
            $title = self::FALLBACK_TITLE.' '.($this->sheetIndex + 1);
        }

        return $this->deduplicate($title);
    }

    /**
     * Appends " (2)", " (3)" … to a sheet whose name is seen for the second time.
     *
     * De-duplication is mandatory: Excel treats a workbook that has two sheets carrying the same
     * name as a "file that needs repairing" and opens it discarding the content. The suffix counts
     * towards the 31-character limit; the side that gets truncated is ALWAYS the base name — had
     * the suffix been truncated, "(2)" and "(3)" would have become indistinguishable.
     */
    private function deduplicate(string $title): string
    {
        if (!isset($this->usedTitles[mb_strtolower($title)])) {
            return $title;
        }

        for ($suffixIndex = 2;; ++$suffixIndex) {
            $suffix = ' ('.$suffixIndex.')';
            $candidate = $this->truncate($title, self::TITLE_MAX_LENGTH - mb_strlen($suffix)).$suffix;

            if (!isset($this->usedTitles[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }
    }

    /**
     * Reduces the name to at most `$length` CHARACTERS.
     *
     * Characters, not bytes: in a name such as "Ürün Grubu", `substr()` would cut through the middle
     * of a multi-byte letter and what came out would be invalid UTF-8. PhpSpreadsheet measures the
     * limit with `mb_strlen` too; if we did not use the same unit we would diverge from its
     * validation.
     */
    private function truncate(string $value, int $length): string
    {
        if ($length < 1) {
            return '';
        }

        return mb_strlen($value) > $length
            ? rtrim(mb_substr($value, 0, $length))
            : $value;
    }

    /**
     * Converts an `Align` into Excel's horizontal alignment code.
     *
     * `Column::$align` is resolved by this point, so `Auto` never arrives; `default` still aligns
     * left — the export does not stop because of a column that cannot be aligned.
     */
    private static function horizontalOf(Align $align): string
    {
        return match ($align) {
            Align::Right => Alignment::HORIZONTAL_RIGHT,
            Align::Center => Alignment::HORIZONTAL_CENTER,
            default => Alignment::HORIZONTAL_LEFT,
        };
    }
}
