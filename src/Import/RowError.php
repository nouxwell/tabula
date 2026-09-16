<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Import;

/**
 * Why a single cell, or a whole row, was not accepted.
 *
 * THE ROW NUMBER IS A FIRST-CLASS FIELD. In the system this replaces the row number only
 * survived if a `%row%` placeholder had been embedded in the translation text; on field-level
 * errors it was usually lost and the user was left alone with a "something is wrong somewhere"
 * message.
 *
 * ★ `$message` is a sentence to show as it is, and it is in English. `$code` and `$params` say the
 * same thing in a form an application can word in its own language (see `RowErrorCode`).
 */
final readonly class RowError
{
    /**
     * @param int                             $row     the row number the user SEES in Excel (1-based)
     * @param string|null                     $field   field key; null means the error belongs to the whole row
     * @param string                          $message the message to show the user — the sentence is English,
     *                                                 the values quoted in it are the user's own
     * @param string|null                     $value   the rejected raw value — it answers the "why" question
     * @param RowErrorCode|null               $code    what went wrong, for an application with its own wording;
     *                                                 null when a custom parser threw without setting one
     * @param array<string, string|int|float> $params  the values that wording needs (`field`, `type`, `value`, ...)
     */
    public function __construct(
        public int $row,
        public ?string $field,
        public string $message,
        public ?string $value = null,
        public ?RowErrorCode $code = null,
        public array $params = [],
    ) {
    }

    /** @param array<string, string|int|float> $params */
    public static function forField(
        int $row,
        string $field,
        string $message,
        ?string $value = null,
        ?RowErrorCode $code = null,
        array $params = [],
    ): self {
        return new self($row, $field, $message, $value, $code, $params);
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
