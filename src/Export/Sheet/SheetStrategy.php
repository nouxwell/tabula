<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Sheet;

use Balin\Tabula\Value\FormatContext;

/**
 * How rows are split across sheets.
 *
 * The pipeline calls `sheetFor()` for every row and opens a new sheet WHENEVER the returned name
 * CHANGES. That is why the strategy has to be pure and predictable.
 */
interface SheetStrategy
{
    /**
     * @param int   $rowIndex the overall row index, starting at 0
     * @param mixed $row      the raw row
     */
    public function sheetFor(int $rowIndex, mixed $row, FormatContext $context): string;
}
