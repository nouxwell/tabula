<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

use ArrayIterator;
use Balin\Tabula\Exception\ImportException;
use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Import\Reader\ReaderRegistry;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\Parser\StringParser;
use Balin\Tabula\Value\ParserRegistry;
use Balin\Tabula\Value\ValueParser;
use Closure;
use Iterator;
use IteratorIterator;
use Traversable;

/**
 * The fluent set-up and execution of a single import — the reverse direction of `ExportBuilder`.
 *
 * The set-up is immutable: every setting returns a new copy, so several runs can be derived
 * from the same base configuration (for instance a dry validation pass first, then the real
 * writing pass):
 *
 *     $base = $tabula->import($schema)->from($path)->locale('tr');
 *
 *     $preview = $base->each(static fn (ImportedRow $row) => $rows[] = $row->toArray())->run();
 *     $result  = $base->each(static fn (ImportedRow $row) => $repo->save($row))->run();
 *
 * ⚠ TRANSACTION MANAGEMENT IS THE CALLER'S JOB. The library parses a row and hands it to the
 * callback; it does not write to the database. In the system this replaces the whole import
 * was wrapped in a SINGLE Doctrine transaction, so a typo on row 37 of a 5,000-row file rolled
 * back the other 4,999 rows as well (see `ErrorMode`).
 */
final class ImportBuilder
{
    private ?string $path = null;

    private ?string $locale = null;

    private MatchStrategy $strategy = MatchStrategy::Auto;

    private ErrorMode $errorMode = ErrorMode::Collect;

    /** @var (Closure(ImportedRow): void)|null */
    private ?Closure $handler = null;

    public function __construct(
        private readonly Schema $schema,
        private readonly Translator $translator,
        private readonly TabulaSettings $settings,
        private readonly ParserRegistry $parsers,
        private readonly ReaderRegistry $readers,
    ) {
    }

    // ---------------------------------------------------------------- set-up

    /** The file to read. The type is chosen FROM THE EXTENSION (see `ReaderRegistry`). */
    public function from(string $path): self
    {
        return $this->with(static function (self $b) use ($path): void {
            $b->path = $path;
        });
    }

    /**
     * The parsing language.
     *
     * Unlike on export, this is not decoration: whether the value "Evet" turns into `true` and
     * "Açık" into the `Status::Open` enum depends on this language (see `ParseContext`).
     */
    public function locale(string $locale): self
    {
        return $this->with(static function (self $b) use ($locale): void {
            $b->locale = $locale;
        });
    }

    public function matchBy(MatchStrategy $strategy): self
    {
        return $this->with(static function (self $b) use ($strategy): void {
            $b->strategy = $strategy;
        });
    }

    public function onError(ErrorMode $mode): self
    {
        return $this->with(static function (self $b) use ($mode): void {
            $b->errorMode = $mode;
        });
    }

    /**
     * The closure called for every valid row.
     *
     * Only ERROR-FREE rows arrive; a half-parsed row is never handed over.
     *
     * @param Closure(ImportedRow): void $fn
     */
    public function each(Closure $fn): self
    {
        return $this->with(static function (self $b) use ($fn): void {
            $b->handler = $fn;
        });
    }

    // ---------------------------------------------------------------- execution

    public function run(): ImportResult
    {
        $path = $this->path ?? throw ImportException::noSource();
        $handler = $this->handler ?? throw ImportException::noHandler();

        // Readability is checked HERE, BEFORE the registry: the registry looks at the
        // extension and would say "unrecognised file type" for a "list.txt" that does not even
        // exist. The user's real problem is that the file is missing or not permitted; the
        // message has to say so.
        if (!is_file($path) || !is_readable($path)) {
            throw ImportException::fileNotReadable($path);
        }

        $reader = $this->readers->for($path);

        $context = new ParseContext(
            locale: $this->locale ?? $this->settings->defaultLocale,
            translator: $this->translator,
            settings: $this->settings,
        );

        // The stream is one-way (the readers return a Generator): there is no second pass over
        // the file. Yet to know which row is DATA we first have to see the first two rows —
        // and since `foreach` cannot look ahead, we advance the cursor by hand.
        $iterator = self::iterator($reader->rows($path));
        $iterator->rewind();

        if (!$iterator->valid()) {
            throw ImportException::emptyFile($path);
        }

        $firstRow = $iterator->current();
        $iterator->next();
        $secondRow = $iterator->valid() ? $iterator->current() : [];

        $map = HeaderMap::resolve($firstRow, $secondRow, $this->schema, $this->strategy, $context);

        // The parsers are resolved ONCE, before a single row is read. `ParserRegistry::for()`
        // throws a `ParseException` when it finds no registered parser; that is a
        // CONFIGURATION error, not a row error. Had we resolved inside the loop, the very same
        // missing piece would turn into one `RowError` per row and the user would be left
        // alone with an "all 5,000 rows are broken" report.
        /** @var list<array{int, Field, ValueParser}> $columns */
        $columns = [];

        foreach ($map->fields as $index => $field) {
            $columns[] = [$index, $field, $this->parsers->for($field)];
        }

        $read = 0;
        $imported = 0;
        /** @var list<RowError> $errors */
        $errors = [];

        // The cursor is sitting on row 2; we filter out the header rows BY ROW NUMBER, not by
        // counting. The numbers are used exactly as they come from the reader, that is, as the
        // user sees them in Excel.
        for (; $iterator->valid(); $iterator->next()) {
            $number = $iterator->key();

            if ($number < $map->firstDataRow) {
                continue;
            }

            $cells = $iterator->current();

            // ★ A completely empty row is skipped SILENTLY and does not even enter the `read`
            // counter. A blank row left dangling at the end of an Excel file is the rule, not
            // the exception; counting it as a data row would make EVERY real file that has a
            // required field "invalid" — and over a row the user never even touched.
            // Keeping it out of `read` is just as essential: otherwise `rejected()` returns a
            // number that corresponds to no `RowError` at all and the report contradicts itself.
            if (self::isEmptyRow($cells)) {
                continue;
            }

            ++$read;

            /** @var list<RowError> $rowErrors */
            $rowErrors = [];
            /** @var array<string, mixed> $values */
            $values = [];

            foreach ($columns as [$index, $field, $parser]) {
                $key = $field->getKey();
                // A short row DOES NOT SHIFT the columns: in a CSV the trailing cells may
                // never have been written at all, and a missing cell is a blank cell.
                $raw = $cells[$index] ?? null;

                try {
                    $value = $parser->parse($raw, $field, $context);
                } catch (ParseException $exception) {
                    // The raw value travels alongside the error: a message that just says
                    // "invalid value" does not tell the user which cell to look at.
                    $rowErrors[] = RowError::forField($number, $key, $exception->getMessage(), StringParser::describe($raw));

                    continue;
                }

                // ★ THE REQUIREDNESS CHECK LIVES HERE. The parser DOES NOT KNOW that the field
                // is required, and must not: for it a blank cell is not an error but "no
                // value" (see `StringParser::isBlank()`). Requiredness is the schema's
                // knowledge, and only this loop sees the schema.
                if (null === $value && $field->isRequired()) {
                    $rowErrors[] = RowError::forField($number, $key, ParseException::required($field)->getMessage());

                    continue;
                }

                $values[$key] = $value;
            }

            if ([] !== $rowErrors) {
                // ★ The row is rejected AS A WHOLE. Handing a half-parsed row to the callback
                // would have meant the caller writing half a record into the database.
                if (ErrorMode::FailFast === $this->errorMode) {
                    // ALL of the row's errors are carried, not just the first cell's: fixing
                    // and uploading the same row four times over is of use to nobody. The
                    // exception's message still shows the first one.
                    throw ImportException::stoppedAtFirstError($rowErrors);
                }

                foreach ($rowErrors as $error) {
                    $errors[] = $error;
                }

                continue;
            }

            // An exception thrown by the callback is NOT SWALLOWED: the caller's database
            // error is not a `RowError` and must not get lost inside a "4,812 rows imported"
            // report. (The readers' `finally` blocks still close the file as the cursor is
            // destroyed.)
            $handler(new ImportedRow($number, $values));
            ++$imported;
        }

        return new ImportResult(
            read: $read,
            imported: $imported,
            errors: $errors,
            columns: $map->columns(),
            ignored: $map->ignored,
        );
    }

    // ---------------------------------------------------------------- internals

    /**
     * Turns the `iterable` the reader gives us into a cursor that can be advanced by hand.
     *
     * @param iterable<int, list<mixed>> $rows
     *
     * @return Iterator<int, list<mixed>>
     */
    private static function iterator(iterable $rows): Iterator
    {
        return match (true) {
            // The built-in readers return a Generator, so this is the first branch. `rewind()`
            // is harmless on a Generator that has not started yet.
            $rows instanceof Iterator => $rows,
            $rows instanceof Traversable => new IteratorIterator($rows),
            default => new ArrayIterator($rows),
        };
    }

    /**
     * Is the row empty in its ENTIRETY?
     *
     * The check is NOT limited to the matched columns: taking a row that has data in an
     * unmatched column for "empty" and skipping it would be silent data loss. Such a row is
     * processed and, if its required fields are blank, shows up to the user as an error — it
     * does not vanish silently.
     *
     * @param list<mixed> $cells
     */
    private static function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (!StringParser::isBlank($cell)) {
                return false;
            }
        }

        return true;
    }

    private function with(Closure $mutator): self
    {
        $clone = clone $this;
        $mutator($clone);

        return $clone;
    }
}
