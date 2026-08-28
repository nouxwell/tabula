<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Import\Reader;

use Generator;
use Nouxwell\Tabula\Exception\ImportException;

/**
 * A CSV reader that works through native PHP streams (fopen/fgetcsv).
 *
 * PhpSpreadsheet's `Csv` reader was DELIBERATELY not used — the same reasoning as in
 * `CsvWriter`, only in the opposite direction: that reader first builds the plain text into a
 * huge in-memory `Spreadsheet` object, cell by cell, whereas we hand the row to the consumer
 * the moment we read it. The only thing kept in memory is the row at hand.
 *
 * The file may not be a template we produced; that is why two things are SNIFFED:
 *
 *  1. The UTF-8 signature (BOM). Our own writer emits it on purpose (so that Excel does not
 *     take the file for cp1254). If we do not drop it, the first cell of the first row starts
 *     with three invisible bytes and "\u{FEFF}code" matches no schema key at all: the file is
 *     rejected with "no column recognised", and on top of that the header looks perfectly
 *     correct on screen.
 *  2. The delimiter. Hard-coding it is fatal: our own writer emits ';' (in Turkish Excel ','
 *     is the decimal separator), but a file the user obtained from another system may use ','.
 *     A row read with the wrong delimiter collapses into a SINGLE cell and the file is again
 *     silently rejected.
 */
final class CsvReader implements Reader
{
    /** The UTF-8 signature — the same three bytes as `CsvWriter::BOM`. */
    private const string BOM = "\xEF\xBB\xBF";

    private const string ENCLOSURE = '"';

    /**
     * PHP's non-standard escaping is OFF.
     *
     * An empty string puts `fgetcsv()` on RFC 4180 footing: a doubled `""` is resolved
     * correctly, while a backslash is read as an ordinary character. Had it been left on, a
     * cell such as `"C:\path\"` would swallow the closing quote and the rest of the row would
     * flow into one single giant cell. Passing the argument EXPLICITLY is mandatory as well:
     * since PHP 8.4, relying on the default emits a "deprecated" notice, and the test suite
     * runs with `failOnDeprecation`.
     */
    private const string ESCAPE = '';

    public function supports(string $path): bool
    {
        return 'csv' === strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * @return Generator<int, list<mixed>> row number (1-based) => cell values
     */
    public function rows(string $path): Generator
    {
        $handle = $this->open($path);

        try {
            $firstLine = fgets($handle);

            if (false === $firstLine) {
                // A completely empty file. We do NOT throw `emptyFile` here: the reader's job
                // is to yield rows, and the "there is not even a header row" decision belongs
                // to the importer, which knows the schema.
                return;
            }

            $hasBom = str_starts_with($firstLine, self::BOM);
            $delimiter = self::sniffDelimiter($hasBom ? substr($firstLine, \strlen(self::BOM)) : $firstLine);

            // Back to the beginning; if there is a BOM, start AFTER the signature. That way
            // the first row too goes down the same `fgetcsv()` path as the others — trimming
            // the signature off the string afterwards would cut in the wrong place in files
            // whose first cell is quoted.
            rewind($handle);

            if ($hasBom) {
                fseek($handle, \strlen(self::BOM));
            }

            $number = 0;

            // `$length = null`: the line length is unbounded. Giving a fixed buffer would split
            // a row in two in the middle of a long address or description cell.
            while (false !== ($fields = fgetcsv($handle, null, $delimiter, self::ENCLOSURE, self::ESCAPE))) {
                ++$number;

                // An empty line arrives as `[null]`; it is yielded as it is. The "completely
                // empty row" decision belongs to the importer as well — had we skipped it here,
                // the row numbers would drift away from the ones the user sees in Excel.
                yield $number => $fields;
            }
        } finally {
            // Even if the consumer leaves the `foreach` with a `break` (e.g.
            // `ErrorMode::FailFast`), this runs as the generator is destroyed and the handle
            // does not leak.
            fclose($handle);
        }
    }

    /**
     * Picks the delimiter of the first line from between ';' and ','.
     *
     * The counting happens OUTSIDE QUOTES: a single quoted cell such as "Ankara, Çankaya"
     * would otherwise be enough to make us take the file for a comma-separated one, and the
     * whole column mapping would break. The scan is byte by byte; since ';' ',' and '"' are
     * ASCII, they cannot collide with the continuation bytes (>= 0x80) of multi-byte UTF-8
     * characters.
     *
     * On a tie — zero to zero included — ';' wins: that is the delimiter of the files we write
     * ourselves, so we land on the right side even for a single-column file.
     */
    private static function sniffDelimiter(string $line): string
    {
        $semicolons = 0;
        $commas = 0;
        $inQuotes = false;
        $length = \strlen($line);

        for ($index = 0; $index < $length; ++$index) {
            $character = $line[$index];

            if (self::ENCLOSURE === $character) {
                // A doubled quote ("") flips twice, so the state is unchanged — no separate rule is needed.
                $inQuotes = !$inQuotes;

                continue;
            }

            if ($inQuotes) {
                continue;
            }

            if (';' === $character) {
                ++$semicolons;
            } elseif (',' === $character) {
                ++$commas;
            }
        }

        return $commas > $semicolons ? ',' : ';';
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ImportException::fileNotReadable($path);
        }

        // 'rb': binary mode. Text mode converts line endings on Windows and we would no longer
        // be able to tell a genuine "\r\n" inside quotes apart from a line ending.
        // `@`: there can be a race between the check above and this call (the file may be
        // deleted, permissions may change); we suppress the warning and turn it into the same
        // exception.
        $handle = @fopen($path, 'rb');

        if (false === $handle) {
            throw ImportException::fileNotReadable($path);
        }

        return $handle;
    }
}
