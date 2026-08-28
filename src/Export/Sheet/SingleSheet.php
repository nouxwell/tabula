<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Sheet;

use InvalidArgumentException;
use Nouxwell\Tabula\Value\FormatContext;

/**
 * Everything on a single sheet — the default behaviour.
 *
 * The only exception is overflow protection: when `$maxRows` is given (and `ExportBuilder` passes
 * it through from `TabulaSettings::$maxRowsPerSheet`), once the sheet is full it carries on as
 * `Name (2)`, `Name (3)`. Excel takes at most 1,048,576 rows on one sheet, and once that limit is
 * exceeded the writer can produce a CORRUPT file without raising an error — a new sheet instead of
 * a silent overflow.
 */
final readonly class SingleSheet implements SheetStrategy
{
    public function __construct(
        private string $name = 'Sheet1',
        private ?int $maxRows = null,
    ) {
        if (null !== $maxRows && $maxRows < 1) {
            throw new InvalidArgumentException('Rows per sheet must be at least 1.');
        }
    }

    public function sheetFor(int $rowIndex, mixed $row, FormatContext $context): string
    {
        if (null === $this->maxRows || $rowIndex < $this->maxRows) {
            return $this->name;
        }

        return sprintf('%s (%d)', $this->name, intdiv($rowIndex, $this->maxRows) + 1);
    }
}
