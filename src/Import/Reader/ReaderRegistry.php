<?php

declare(strict_types=1);

namespace Balin\Tabula\Import\Reader;

use Balin\Tabula\Exception\ImportException;

/**
 * Maps a file to a reader.
 *
 * The choice is made BY EXTENSION, not by content: sniffing the first bytes of the file the
 * user uploaded (the magic number) looks "smarter" but in practice solves the wrong problem —
 * when a user sends a CSV with an .xlsx extension, telling them "this file is a CSV, correct
 * the extension" is far more useful than silently accepting the file and then saying "no
 * column matched".
 *
 * The reader at the front wins: a custom reader added with `with()` overrides a built-in one
 * (see `FormatterRegistry` — the same pattern, the same reasoning).
 */
final class ReaderRegistry
{
    /** @var list<Reader> */
    private array $readers;

    public function __construct(Reader ...$readers)
    {
        $this->readers = array_values($readers);
    }

    /** A registry wired up with the built-in readers. */
    public static function default(): self
    {
        return new self(new XlsxReader(), new CsvReader());
    }

    /** Overrides the built-ins by prepending the custom readers. */
    public function with(Reader ...$readers): self
    {
        return new self(...array_values($readers), ...$this->readers);
    }

    /**
     * The first reader able to read this path.
     *
     * @throws ImportException when no reader recognises the extension
     */
    public function for(string $path): Reader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($path)) {
                return $reader;
            }
        }

        throw ImportException::unsupportedFile($path);
    }

    /** Is there a reader for this path — for those who want to ask without an exception. */
    public function supports(string $path): bool
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($path)) {
                return true;
            }
        }

        return false;
    }
}
