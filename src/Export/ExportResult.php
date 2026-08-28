<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export;

use Nouxwell\Tabula\Exception\ExportException;
use Nouxwell\Tabula\Format;

/** The result of a completed export. */
final readonly class ExportResult
{
    /**
     * @param list<string> $paths   the file paths written (in CSV, several sheets = several files)
     * @param list<string> $columns the column keys written, in the order they were written
     */
    public function __construct(
        public array $paths,
        public int $rows,
        public int $sheets,
        public array $columns,
        public Format $format,
    ) {
    }

    /** The path of a single-file output. */
    public function path(): string
    {
        return $this->paths[0] ?? throw ExportException::noOutput();
    }

    public function isMultiFile(): bool
    {
        return count($this->paths) > 1;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->rows;
    }
}
