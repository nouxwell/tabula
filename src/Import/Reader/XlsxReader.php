<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Import\Reader;

use Generator;
use Nouxwell\Tabula\Exception\ImportException;
use PhpOffice\PhpSpreadsheet\Cell\Cell as SpreadsheetCell;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as SpreadsheetReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\CellIterator;

/**
 * An .xlsx/.xls reader that reads through PhpSpreadsheet.
 *
 * Unlike `CsvReader`, THERE IS NO REAL STREAMING here, and there cannot be: an xlsx is a ZIP
 * archive, the shared string table sits in a separate entry, and the rows only take on meaning
 * once that table has been resolved. The library builds the workbook in memory; that is not
 * ours to choose. There are three things we can choose — and they lower the cost markedly:
 *
 *  1. `setReadDataOnly(true)`: styles, conditional formatting, drawings and page setup are
 *     never read at all. In an import every one of those is rubbish, and in a large template
 *     they are the part that eats up most of the file's parsing time.
 *  2. `setReadEmptyCells(false)`: no object is created for a cell whose value is empty. Column
 *     alignment is NOT AFFECTED by this (see `rows()`); only memory is saved.
 *  3. `disconnectWorksheets()`: Worksheet and Spreadsheet hold on to each other, and PHP's
 *     refcount-based garbage collector cannot break that cycle on its own. If we do not cut
 *     the link, a queue worker processing files back to back piles every file up in memory.
 *
 * ★ Dates arrive as a SERIAL NUMBER (float). Because `setReadDataOnly(true)` does not read
 * number formats, the answer to "is this cell a date" DOES NOT EXIST here. That is a
 * deliberate division of labour: the reader carries the raw value, and the decision to convert
 * it to a date is made by the parser, which knows the field's TYPE (see the contract of the
 * `Reader` interface). Had we converted the cell to a string here — which is what the system
 * this replaces did — the serial number would turn into a meaningless string such as "45296"
 * and the date could never be recovered.
 */
final class XlsxReader implements Reader
{
    /**
     * The supported extensions.
     *
     * The list is deliberately identical to the list in the `ImportException::unsupportedFile()`
     * message: telling the user "these are supported" and then silently accepting some other
     * extension as well turns the error message into a liar.
     *
     * @var list<string>
     */
    private const array EXTENSIONS = ['xlsx', 'xls'];

    public function supports(string $path): bool
    {
        return \in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * @return Generator<int, list<mixed>> row number (1-based) => cell values
     */
    public function rows(string $path): Generator
    {
        $spreadsheet = $this->load($path);

        try {
            // The FIRST sheet is read, not the "active" one. Which sheet is active depends on
            // the tab the user happened to be on when they last saved the file; when two users
            // fill in and send back the same template, reading different sheets would be a
            // silent and non-reproducible bug. Our template's helper "_lists" sheet always
            // sits BEHIND the first one, too.
            $sheet = $spreadsheet->getSheet(0);

            // The column limit is taken from the SHEET as a whole, not row by row: however
            // many columns the longest row has, every row comes back with that many cells.
            // Otherwise short rows would be turned into short arrays and the column indexes
            // would drift on the import side.
            $highestColumn = $sheet->getHighestColumn();

            foreach ($sheet->getRowIterator() as $number => $row) {
                $cells = $row->getCellIterator('A', $highestColumn);

                // ★ THIS LINE IS THE MOST IMPORTANT DETAIL IN THIS CLASS.
                // Had it been `true`, only the cells that REALLY EXIST would be walked; an
                // empty cell is skipped, every column after it shifts one to the left, and the
                // whole row is mapped onto the wrong fields — the value in the "Code" column
                // gets written into the "Name" field, and without producing a single error at
                // that. An empty cell MUST HOLD ITS COLUMN POSITION.
                $cells->setIterateOnlyExistingCells(false);

                // For a cell that does not exist, DO NOT CREATE A NEW CELL, return null. The
                // default behaviour (IF_NOT_EXISTS_CREATE_NEW) inflates the workbook with as
                // many cell objects as rows × columns while reading: writing to memory during
                // a read-only operation.
                $cells->setIfNotExists(CellIterator::IF_NOT_EXISTS_RETURN_NULL);

                $values = [];

                // Walking BY HAND instead of with `foreach`: `CellIterator` declares itself as
                // `Iterator<TKey, Cell>`, yet because of the setting above `current()` really
                // can return null. Walking by hand we see the REAL return type, which is
                // `?Cell`, and can turn an empty cell into null; written with a `foreach`, the
                // static analyser takes the null check for "always false" and removes it,
                // while at runtime the cell arrives as null.
                $cells->rewind();

                while ($cells->valid()) {
                    $cell = $cells->current();
                    $values[] = null === $cell ? null : self::valueOf($cell);
                    $cells->next();
                }

                // The key is THE ROW NUMBER THE USER SEES; the iterator already gives 1-based numbers.
                yield $number => $values;
            }
        } finally {
            // `finally`: if the consumer leaves the `foreach` with a `break` (e.g.
            // `ErrorMode::FailFast`), this still runs as the generator is destroyed and the
            // workbook is released.
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * The cell's value; if it is a formula, its RESULT.
     *
     * While filling in the template, the user writes sum or concatenation formulas ("=B2*C2",
     * "=A2&' '&B2"). For such a cell `getValue()` returns the TEXT of the formula and the
     * import writes the string "=B2*C2" into the database — one of the most frequently seen
     * silent corruptions in the system this replaces.
     */
    private static function valueOf(SpreadsheetCell $cell): mixed
    {
        try {
            $value = $cell->getCalculatedValue();
        } catch (SpreadsheetException) {
            // The calculation engine could not resolve this formula (an unsupported function,
            // an external file reference, a circular reference). Rather than rejecting the
            // whole file we fall back to the raw value; the cell goes on to the parser and, if
            // need be, becomes a row error THERE.
            $value = $cell->getValue();
        }

        // Spilled array formulas can return an array. We are reading a single cell, so we
        // descend to the first scalar; an empty array becomes null.
        while (\is_array($value)) {
            $values = array_values($value);
            $value = $values[0] ?? null;
        }

        return $value;
    }

    private function load(string $path): Spreadsheet
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ImportException::fileNotReadable($path);
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
        } catch (SpreadsheetReaderException) {
            // The extension is right but the content is not recognised (e.g. an HTML table saved as .xlsx).
            throw ImportException::unsupportedFile($path);
        }

        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        try {
            $spreadsheet = $reader->load($path);
        } catch (SpreadsheetException) {
            // A corrupt archive, an encrypted file, a half-downloaded upload…
            throw ImportException::fileNotReadable($path);
        }

        if ($spreadsheet->getSheetCount() < 1) {
            $spreadsheet->disconnectWorksheets();

            throw ImportException::emptyFile($path);
        }

        return $spreadsheet;
    }
}
