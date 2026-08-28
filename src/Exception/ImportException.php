<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Exception;

use Nouxwell\Tabula\Import\RowError;
use RuntimeException;

/**
 * Thrown when the import PIPELINE cannot be set up, or when the file as a whole is unusable.
 *
 * A single rejected cell is NOT an exception — that one is collected as a `RowError`. The
 * failures here belong to the "we cannot process this file at all" class.
 */
final class ImportException extends RuntimeException implements TabulaException
{
    /** @var list<RowError> */
    private array $rowErrors = [];

    public static function noSource(): self
    {
        return new self('No file to import was given: call ->from(...) first.');
    }

    /**
     * `->run()` was called without `->each()`.
     *
     * Silently returning "0 rows imported" was the worst option available: the library does
     * NOT write rows to the database, it only hands them to the callback — with no callback
     * the work was effectively never done, and the user would notice only once the data
     * failed to turn up.
     */
    public static function noHandler(): self
    {
        return new self(
            'No row callback was given: call ->each(fn (ImportedRow $row) => ...) first. '
            .'Tabula parses the rows, it does not write them to the database; persisting them is the job of your callback.',
        );
    }

    public static function fileNotReadable(string $path): self
    {
        return new self(sprintf('The file "%s" cannot be read.', $path));
    }

    public static function unsupportedFile(string $path): self
    {
        return new self(sprintf(
            '"%s" has an unsupported file type. Supported: .xlsx, .xls, .csv',
            $path,
        ));
    }

    public static function emptyFile(string $path): self
    {
        return new self(sprintf('"%s" is empty: it does not even have a header row.', $path));
    }

    /**
     * None of the headers in the file matched the schema.
     *
     * @param list<string> $found
     * @param list<string> $expected
     */
    public static function noMatchingColumns(array $found, array $expected): self
    {
        return new self(sprintf(
            'None of the columns in the file matched the schema. Found in the file: %s. Expected: %s. '
            .'Make sure you downloaded the right template.',
            [] === $found ? '(none)' : implode(', ', $found),
            implode(', ', $expected),
        ));
    }

    /**
     * `MatchStrategy::Key` was requested but the file has no key row.
     */
    public static function keyRowMissing(): self
    {
        return new self(
            'The key row was not found; this file may not have been produced from a Tabula template. '
            .'Download the template again, or allow matching by label (MatchStrategy::Auto).',
        );
    }

    /** @param list<string> $known */
    public static function unknownRowField(string $key, array $known): self
    {
        return new self(sprintf(
            'The row has no field called "%s". Available: %s',
            $key,
            [] === $known ? '(none)' : implode(', ', $known),
        ));
    }

    /**
     * The first error under `ErrorMode::FailFast`: the run stopped.
     *
     * @param list<RowError> $errors
     */
    public static function stoppedAtFirstError(array $errors): self
    {
        $first = $errors[0] ?? null;

        // An explicit `null` check instead of `$first?->row ?? 0`: PHPStan treats the `?->`
        // operator as redundant on the left-hand side of `??` (nullsafe.neverNull) and reports
        // it as an error at level 8, while turning `?->` into `->` would be fatal on an EMPTY
        // list. The produced text is the same either way.
        $exception = new self(sprintf(
            'Import stopped at the first error (row %d): %s',
            null === $first ? 0 : $first->row,
            null === $first ? 'unknown error' : $first->message,
        ));
        $exception->rowErrors = $errors;

        return $exception;
    }

    /** @return list<RowError> */
    public function rowErrors(): array
    {
        return $this->rowErrors;
    }
}
