<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Exception;

use InvalidArgumentException;

/**
 * Thrown when rows sent by the grid are not in the shape it sends them in: a request to refuse
 * as a whole, not a list of issues about the data.
 */
final class GridException extends InvalidArgumentException implements TabulaException
{
    public static function notARow(int|string $index): self
    {
        return new self(sprintf('Row %s must be an object with a rowId and values.', $index));
    }

    public static function noRowId(int|string $index): self
    {
        return new self(sprintf('Row %s has no rowId; every row the grid sends carries one.', $index));
    }

    public static function duplicateRowId(string $rowId): self
    {
        return new self(sprintf('The rowId "%s" is sent twice; a row id names one row only.', $rowId));
    }
}
