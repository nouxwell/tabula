<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Export\Column;
use Balin\Tabula\Value\Cell;

/**
 * A unit that writes to a file.
 *
 * The lifecycle is strictly this:
 *
 *     open(path)
 *       startSheet(name, columns)
 *         writeRow(cells) ×N
 *       finishSheet()
 *       ... (several sheets)
 *     close() → the file paths written
 *
 * A writer MUST NOT ACCUMULATE rows; every `writeRow()` call should move towards the output as far
 * as it possibly can. `close()` returns a list because in CSV a multi-sheet output means more than
 * one file.
 */
interface Writer
{
    public function open(string $path): void;

    /**
     * @param list<Column> $columns
     */
    public function startSheet(string $name, array $columns): void;

    /**
     * @param list<Cell> $cells in the same order as the columns
     */
    public function writeRow(array $cells): void;

    public function finishSheet(): void;

    /**
     * @return list<string> the file paths written
     */
    public function close(): array;
}
