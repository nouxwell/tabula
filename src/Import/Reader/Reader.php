<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Import\Reader;

/**
 * Reads raw rows from a file.
 *
 * Cells ARE NOT CONVERTED TO STRINGS, they are handed over as they are: Excel returns a date
 * as a serial number (float) and a quantity as a real number. Converting all of them to
 * strings destroys that information and would force the parser to recover the date out of a
 * string such as "45296". The parsers are tolerant in that they accept both the native type
 * and a string.
 *
 * The row number is THE NUMBER THE USER SEES (1-based) — when an error message says "row 37",
 * the user must be able to go and look at row 37 in Excel.
 */
interface Reader
{
    public function supports(string $path): bool;

    /**
     * @return iterable<int, list<mixed>> row number (1-based) => cell values
     */
    public function rows(string $path): iterable;
}
