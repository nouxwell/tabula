<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

/**
 * Why a single cell, or a whole row, was not accepted.
 *
 * THE ROW NUMBER IS A FIRST-CLASS FIELD. In the system this replaces the row number only
 * survived if a `%row%` placeholder had been embedded in the translation text; on field-level
 * errors it was usually lost and the user was left alone with a "something is wrong somewhere"
 * message.
 */
final readonly class RowError
{
    /**
     * @param int         $row     the row number the user SEES in Excel (1-based)
     * @param string|null $field   field key; null means the error belongs to the whole row
     * @param string      $message the localised message to show the user
     * @param string|null $value   the rejected raw value — it answers the "why" question
     */
    public function __construct(
        public int $row,
        public ?string $field,
        public string $message,
        public ?string $value = null,
    ) {
    }

    public static function forField(int $row, string $field, string $message, ?string $value = null): self
    {
        return new self($row, $field, $message, $value);
    }

    public static function forRow(int $row, string $message): self
    {
        return new self($row, null, $message);
    }

    public function isFieldError(): bool
    {
        return null !== $this->field;
    }
}
