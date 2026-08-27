<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Value\Cell;

/**
 * A CSV writer that writes through native PHP streams (fopen/fputcsv).
 *
 * PhpSpreadsheet was DELIBERATELY not used here. Even though it would end up saving a single
 * plain-text file, the system this replaces first built a huge `Spreadsheet` object in memory,
 * filled every row into it as cell objects and only at the very end dumped it to disk with the
 * `Csv` writer: a fifty-thousand-row export meant hundreds of megabytes of RAM and seconds of CPU.
 * Here the row goes straight to the file handle inside the `writeRow()` call; the only thing left
 * in memory is the row at hand. The stream really does stream.
 *
 * SEVERAL SHEETS: CSV has no concept of a sheet. That is why every `startSheet()` opens A NEW FILE
 * — the first sheet is the path given to `open()` itself, the later ones become
 * "<stem>-<name>.csv". `close()` returns every path produced, in creation order; collecting them
 * into a single archive (or offering them to the user one by one) is the caller's job.
 */
final class CsvWriter implements Writer
{
    /**
     * The UTF-8 signature (BOM).
     *
     * Excel opens a CSV without a signature using the system code page — cp1254 on a Turkish
     * Windows — and the letters "ş ğ ı İ ö ç ü" are mangled on the very first line. There is no
     * other marker that tells Excel the file is UTF-8; these three bytes are not up for
     * negotiation. It was left configurable because the signature can be unnecessary for files
     * that are only ever consumed by a machine (e.g. to be imported into another system).
     */
    private const string BOM = "\xEF\xBB\xBF";

    /** @var resource|null the file handle of the active sheet; null while there is no sheet */
    private $handle;

    /** The path given to `open()`; the first sheet's file and the stem the other names are derived from. */
    private ?string $basePath = null;

    /** The path of the file currently being written — kept so it can appear in the message on a write error. */
    private ?string $currentPath = null;

    private bool $opened = false;

    /** Which sheet we are on; 0 means no sheet has been opened yet (the first sheet uses the base path). */
    private int $sheetIndex = 0;

    /** @var list<string> the file paths written, in creation order */
    private array $paths = [];

    private readonly string $delimiter;

    private readonly string $enclosure;

    private readonly string $escape;

    private readonly bool $writeBom;

    private readonly string $lineEnding;

    /**
     * The REASONS behind the options (why the delimiter is ';', why the BOM is mandatory, why the
     * escaping can be turned off) live on `CsvOptions`.
     *
     * Lining up five scalars in the right order at the call site is error-prone and silently
     * produces the wrong file; `CsvOptions::excel()` / `CsvOptions::rfc4180()` state the intent by
     * name.
     */
    public function __construct(CsvOptions $options = new CsvOptions())
    {
        $this->delimiter = $options->delimiter;
        $this->enclosure = $options->enclosure;
        $this->escape = $options->escape;
        $this->writeBom = $options->writeBom;
        $this->lineEnding = $options->lineEnding;
    }

    public function open(string $path): void
    {
        if ($this->opened) {
            throw WriterException::alreadyOpen();
        }

        // The file IS NOT OPENED here. In CSV a file is born together with a sheet; if no sheet
        // ever starts there is no point in leaving an empty shell on disk either.
        $this->basePath = $path;
        $this->opened = true;
        $this->sheetIndex = 0;
        $this->paths = [];
    }

    /**
     * @param list<Column> $columns
     */
    public function startSheet(string $name, array $columns): void
    {
        if (!$this->opened) {
            throw WriterException::notOpened();
        }

        // If a new sheet is started before the previous one has seen `finishSheet()`, we close it
        // silently: since a sheet is a file in CSV, a handle left open is a straight leak.
        $this->closeHandle();

        $path = 0 === $this->sheetIndex
            ? ($this->basePath ?? throw WriterException::notOpened())
            : $this->derivePath($name);

        ++$this->sheetIndex;
        $this->openFile($path);

        // The header row: at this point `Column::$label` is not a translation key but resolved text.
        $labels = [];
        foreach ($columns as $column) {
            $labels[] = $column->label;
        }

        $this->put($labels);
    }

    /**
     * @param list<Cell> $cells in the same order as the columns
     */
    public function writeRow(array $cells): void
    {
        if (null === $this->handle) {
            throw WriterException::noActiveSheet();
        }

        $fields = [];
        foreach ($cells as $cell) {
            // What gets written is NOT the typed `value` but the localised `text`: CSV has no such
            // thing as a cell type, the user sees whatever is in the file. Had we written the raw
            // float, "1234.5" would come out and a Turkish Excel would take it for a date or for
            // text.
            $fields[] = $cell->text;
        }

        $this->put($fields);
    }

    public function finishSheet(): void
    {
        if (null === $this->handle) {
            throw WriterException::noActiveSheet();
        }

        $this->closeHandle();
    }

    /**
     * @return list<string> the file paths written
     */
    public function close(): array
    {
        // DELIBERATELY DOES NOT THROW: we want the caller to be able to use this inside a
        // `finally`, so that not even an error blowing up in the middle of an export leaves an
        // open handle behind.
        $this->closeHandle();

        $this->opened = false;
        $this->basePath = null;
        $this->sheetIndex = 0;

        // `paths` is not reset; if `close()` is called repeatedly it should return the same list.
        // A new `open()` rebuilds the list from scratch anyway.
        return $this->paths;
    }

    /** Closes the handle (fclose flushes the buffer too) and puts the state back to "no sheet". */
    private function closeHandle(): void
    {
        if (null === $this->handle) {
            return;
        }

        fclose($this->handle);
        $this->handle = null;
        $this->currentPath = null;
    }

    private function openFile(string $path): void
    {
        // We capture the warning text with our own temporary handler rather than with
        // `error_get_last()`: after an `@fopen`, in the case where the call produced no warning at
        // all, `error_get_last()` can return a PREVIOUS, unrelated error and make the message
        // misleading. This cost is paid once per file, not once per row.
        $reason = null;
        set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });

        try {
            // 'wb': binary mode, because we are the ones deciding the line ending — in text mode on
            // Windows our "\r\n" sequence would become "\r\r\n".
            $handle = fopen($path, 'wb');
        } finally {
            restore_error_handler();
        }

        if (false === $handle) {
            throw ExportException::unwritableTarget($path, $reason ?? 'could not open the file');
        }

        $this->handle = $handle;
        $this->currentPath = $path;

        // The path is added to the list AS SOON AS the file is opened: so that even if we hit an
        // error mid-stream, the caller can see the half-written file and clean it up.
        $this->paths[] = $path;

        if ($this->writeBom) {
            fwrite($handle, self::BOM);
        }
    }

    /**
     * Writes a single row to disk.
     *
     * The `$escape` argument is passed EXPLICITLY: since PHP 8.4, relying on this parameter's
     * default emits a "deprecated" warning (the non-standard escaping mechanism will be off by
     * default in PHP 9). Giving the value explicitly — be it '\\' or '' — silences the warning;
     * that is why not a single deprecation shows up on PHP 8.5. For the same reason the line ending
     * is given as the 6th argument, otherwise fputcsv ends every row with "\n".
     *
     * Argument order: $stream, $fields, $separator, $enclosure, $escape, $eol.
     *
     * @param list<string> $fields
     */
    private function put(array $fields): void
    {
        $handle = $this->handle ?? throw WriterException::noActiveSheet();

        // This is the hot path that runs once per row: no error-handling set-up on purpose, just a
        // single return-value check. PHP prints the warning itself on its own channel; all we do is
        // stop the stream.
        $written = fputcsv($handle, $fields, $this->delimiter, $this->enclosure, $this->escape, $this->lineEnding);

        if (false === $written) {
            // The disk filled up or the stream broke. Swallowed silently, the user downloads an
            // incomplete file believing it is complete — which is exactly what used to happen in
            // the system this replaces.
            throw ExportException::unwritableTarget($this->currentPath ?? '(unknown)', 'could not write the row (the disk may be full or the stream may have been closed)');
        }
    }

    /**
     * Derives the file path for the second and later sheets.
     *
     * The extension is taken from the base path (or 'csv' if there is none), so for the base
     * "/tmp/report.csv" the second sheet becomes "/tmp/report-sheet-2.csv". The directory part is
     * never touched — the path string is cut and appended as it is; using `dirname()` would turn a
     * relative path such as "report.csv" into "./report-...csv".
     */
    private function derivePath(string $sheetName): string
    {
        $base = $this->basePath ?? throw WriterException::notOpened();

        $extension = pathinfo($base, PATHINFO_EXTENSION);
        $stem = '' === $extension ? $base : substr($base, 0, -(strlen($extension) + 1));
        $extension = '' === $extension ? 'csv' : $extension;

        $slug = $this->slugify($sheetName);

        if ('' === $slug) {
            // If the name consists entirely of symbols (or is empty) we fall back to the index; the
            // file name must not be left empty.
            $slug = 'sheet-'.($this->sheetIndex + 1);
        }

        $path = $stem.'-'.$slug.'.'.$extension;

        // If two sheets reduce to the same name (e.g. "Product A" and "Product-A") the second would
        // overwrite the first; we break the collision by appending the index.
        if (in_array($path, $this->paths, true)) {
            $path = $stem.'-'.$slug.'-'.($this->sheetIndex + 1).'.'.$extension;
        }

        return $path;
    }

    /**
     * Reduces the sheet name to a fragment that can safely be used in a file name.
     *
     * Turkish letters are converted to their ASCII counterparts: a file name passes through many
     * layers, from the download header (Content-Disposition) all the way to a zip entry, and not
     * every one of those layers carries UTF-8 correctly — "Ürün Listesi.csv" can land on the user's
     * disk as "ÃœrÃ¼n Listesi.csv". The name itself (the sheet title) is only reduced in the file
     * name, not INSIDE the file.
     */
    private function slugify(string $name): string
    {
        $ascii = strtr($name, [
            'ş' => 's', 'Ş' => 's',
            'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'İ' => 'i',
            'ö' => 'o', 'Ö' => 'o',
            'ç' => 'c', 'Ç' => 'c',
            'ü' => 'u', 'Ü' => 'u',
        ]);

        $lower = strtolower($ascii);

        // Everything else that is left (spaces, dots, slashes, untranslatable unicode) turns into a
        // hyphen.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';

        return trim($slug, '-');
    }
}
