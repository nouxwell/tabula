<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Import\Reader;

/**
 * A reader for a format that can hold more than one sheet.
 *
 * CSV has no such concept, which is exactly why this is a separate interface rather than an
 * argument on `Reader::rows()`. Asking a CSV reader which sheet to read would be a question
 * with no answer, and giving it one to ignore is how a caller ends up believing a selection
 * took effect when it did not.
 */
interface SheetAware
{
    /**
     * Returns a copy that reads the named sheet.
     *
     * Immutable on purpose: readers are resolved from a shared registry, so mutating one would
     * leak the selection into whatever import ran next.
     */
    public function forSheet(string $name): static;

    /**
     * The names of the sheets that could hold data, in workbook order.
     *
     * "Could hold data" excludes hidden sheets: the template writes its dropdown sources into a
     * hidden `_lists` sheet, so every filled-in template has two sheets, and counting that one
     * would make an ordinary import look ambiguous.
     *
     * @return list<string>
     */
    public function dataSheets(string $path): array;
}
