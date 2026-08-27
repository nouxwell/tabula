<?php

declare(strict_types=1);

namespace Balin\Tabula\Source;

/**
 * A source of rows.
 *
 * Every implementation must be LAZY: `rows()` returns a generator and the rows are produced as
 * they are consumed. Because the whole pipeline is stream-based, memory does not grow along
 * with the number of rows.
 *
 * (The engine of the system this replaces piled every row into a single `Spreadsheet` object
 * and only then converted them to strings; that is why a hard ceiling of 10,000 rows had been
 * put in place.)
 */
interface DataSource
{
    /**
     * @return iterable<int, array<string, mixed>|object>
     */
    public function rows(): iterable;

    /**
     * Returns the total row count if it is known (for a progress bar); null if it is not.
     */
    public function count(): ?int;
}
