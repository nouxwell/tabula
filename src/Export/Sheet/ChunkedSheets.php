<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Sheet;

use InvalidArgumentException;
use Nouxwell\Tabula\Value\FormatContext;

/**
 * A new sheet every N rows.
 *
 * Gets past Excel's 1,048,576-row limit and past how slowly one gigantic sheet opens.
 * In CSV every chunk becomes a separate file, collected into a single archive.
 */
final readonly class ChunkedSheets implements SheetStrategy
{
    public function __construct(
        private int $rowsPerSheet,
        /** `%d` is replaced with the chunk number. */
        private string $namePattern = 'Sheet %d',
    ) {
        if ($rowsPerSheet < 1) {
            throw new InvalidArgumentException('Rows per sheet must be at least 1.');
        }
    }

    public function sheetFor(int $rowIndex, mixed $row, FormatContext $context): string
    {
        return sprintf($this->namePattern, intdiv($rowIndex, $this->rowsPerSheet) + 1);
    }
}
