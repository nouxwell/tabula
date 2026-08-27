<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

/** The result of a completed import. */
final readonly class ImportResult
{
    /**
     * @param int            $read     number of data rows read from the file (headers excluded)
     * @param int            $imported number of rows successfully handed to the callback
     * @param list<RowError> $errors   row-level and field-level errors
     * @param list<string>   $columns  the field keys recognised in the file, in file order
     * @param list<string>   $ignored  headers with no counterpart in the schema, ignored
     */
    public function __construct(
        public int $read,
        public int $imported,
        public array $errors,
        public array $columns,
        public array $ignored = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    public function isCompletelySuccessful(): bool
    {
        return [] === $this->errors && $this->read === $this->imported;
    }

    /** The number of rejected rows. */
    public function rejected(): int
    {
        return $this->read - $this->imported;
    }

    /**
     * Groups the errors by row number — the most natural way to show the user
     * "here is what is wrong on row 37".
     *
     * @return array<int, list<RowError>>
     */
    public function errorsByRow(): array
    {
        $grouped = [];

        foreach ($this->errors as $error) {
            $grouped[$error->row][] = $error;
        }

        ksort($grouped);

        return $grouped;
    }
}
