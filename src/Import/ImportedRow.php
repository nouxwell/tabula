<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Import;

use Nouxwell\Tabula\Exception\ImportException;

/**
 * A single parsed and validated row — what gets handed to the callback.
 *
 * Values are accessed BY FIELD KEY and their types follow the schema's `FieldType`:
 * `quantity` is a float, `bool` a real bool, `enum` an enum INSTANCE, `date` a
 * DateTimeImmutable. In other words, the caller never has to parse strings.
 *
 * When the import was asked to (`ImportBuilder::keepRawValues()`), the value the reader produced
 * before parsing is kept alongside, for the caller that needs to see what was in the cell.
 */
final readonly class ImportedRow
{
    /**
     * @param int                       $row    the row number the user sees in Excel
     * @param array<string, mixed>      $values field key => parsed value
     * @param array<string, mixed>|null $raws   field key => the value as the reader gave it; null when the
     *                                          import did not keep raw values
     */
    public function __construct(
        public int $row,
        private array $values,
        private ?array $raws = null,
    ) {
    }

    /** An unknown key does not silently return null: a typo must become visible at once. */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw ImportException::unknownRowField($key, array_keys($this->values));
        }

        return $this->values[$key];
    }

    /** Fall back to the default if the field is absent from the file, or empty. */
    public function getOr(string $key, mixed $default): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * The value AS THE READER GAVE IT, before the field's parser touched it.
     *
     * What that is depends on the file. A CSV cell is the text as it stands in the file, and an
     * empty one is an empty string. An xlsx cell is what the workbook stores: a number is an int
     * or a float, a date its serial number (45296, not "05.01.2024"), a formula its result as
     * PhpSpreadsheet calculates it, an empty cell null. An xlsx file keeps no "text the user typed",
     * so there is none to return. A cell missing from a short CSV row is null as well.
     *
     * For showing the original next to the parsed value, for an audit trail, and for moving code
     * that normalises strings itself over to typed values one field at a time. Only accepted rows
     * reach the callback; for a rejected cell, `RowError::$value` carries what was rejected.
     *
     * The key is checked the way `get()` checks it, so a typo is refused. So is a row whose import
     * did not call `keepRawValues()`: answering null for every field would look like empty cells.
     */
    public function raw(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw ImportException::unknownRowField($key, array_keys($this->values));
        }

        return $this->rawValues()[$key] ?? null;
    }

    /**
     * Every raw value, keyed like `toArray()`.
     *
     * Only the schema's fields: a column in the file that matched no field is not here (its
     * header is in `ImportResult::$ignored`).
     *
     * @return array<string, mixed>
     */
    public function rawValues(): array
    {
        return $this->raws ?? throw ImportException::rawValuesNotKept();
    }
}
