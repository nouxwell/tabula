<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

/**
 * What to do when a broken row is encountered.
 *
 * In the system this replaces there was NO choice: the whole import was wrapped in a SINGLE
 * Doctrine transaction, so one typo on row 37 of a 5,000-row file rolled back the other 4,999
 * rows as well. The user fixed the mistake and uploaded everything again from scratch.
 */
enum ErrorMode: string
{
    /**
     * The default: COLLECT the errors, process the valid rows.
     *
     * What the user ends up seeing is "4,812 rows imported, these 3 rows were left out for
     * this reason".
     * ⚠ Transaction management is the CALLER's responsibility: the library hands the rows to
     * the callback, it does not write to the database.
     */
    case Collect = 'collect';

    /** Stop at the first error. For flows that need all-or-nothing. */
    case FailFast = 'fail_fast';
}
