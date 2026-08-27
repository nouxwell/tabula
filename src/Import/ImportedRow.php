<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

use Balin\Tabula\Exception\ImportException;

/**
 * A single parsed and validated row — what gets handed to the callback.
 *
 * Values are accessed BY FIELD KEY and their types follow the schema's `FieldType`:
 * `quantity` is a float, `bool` a real bool, `enum` an enum INSTANCE, `date` a
 * DateTimeImmutable. In other words, the caller never has to parse strings.
 */
final readonly class ImportedRow
{
    /**
     * @param int                  $row    the row number the user sees in Excel
     * @param array<string, mixed> $values field key => parsed value
     */
    public function __construct(
        public int $row,
        private array $values,
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
}
