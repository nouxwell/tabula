<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Exception;

use Nouxwell\Tabula\Import\RowErrorCode;
use Nouxwell\Tabula\Schema\Field;
use RuntimeException;

/**
 * A single cell could not be converted to the type of its field.
 *
 * This exception DOES NOT STOP THE RUN: the import loop catches it, turns it into a `RowError`
 * carrying the row and field, and moves on to the next row (see `ErrorMode`). Because the
 * message is put in front of the user directly, it has to carry both halves of "which value,
 * and what was expected" — saying "invalid value" tells the user nothing.
 *
 * ★ Next to the message every cell factory records a `RowErrorCode` and the values behind the
 * message; the import loop copies both onto the `RowError`. The message itself is unchanged —
 * the code is for the application that words it in another language.
 *
 * The code cannot be called `$code`: `Exception::$code` is the int `getCode()` returns, and PHP
 * refuses to redeclare it. Hence `rowErrorCode()`.
 */
final class ParseException extends RuntimeException implements TabulaException
{
    private ?RowErrorCode $rowErrorCode = null;

    /** @var array<string, string|int|float> */
    private array $rowErrorParams = [];

    public static function notANumber(Field $field, string $raw): self
    {
        return self::coded(
            sprintf('"%s" could not be read as a number.', $raw),
            RowErrorCode::NotANumber,
            $field,
            ['value' => $raw],
        );
    }

    public static function notAnInteger(Field $field, string $raw): self
    {
        return self::coded(
            sprintf('"%s" is not a whole number.', $raw),
            RowErrorCode::NotAnInteger,
            $field,
            ['value' => $raw],
        );
    }

    public static function notADate(Field $field, string $raw, string $expectedPattern): self
    {
        return self::coded(
            sprintf('"%s" could not be read as a date; expected format: %s', $raw, $expectedPattern),
            RowErrorCode::NotADate,
            $field,
            ['value' => $raw, 'format' => $expectedPattern],
        );
    }

    /** @param list<string> $accepted */
    public static function notABoolean(Field $field, string $raw, array $accepted): self
    {
        return self::coded(
            sprintf('"%s" is not a Yes/No value. Accepted: %s', $raw, implode(', ', $accepted)),
            RowErrorCode::NotABoolean,
            $field,
            ['value' => $raw, 'accepted' => implode(', ', $accepted)],
        );
    }

    /** @param list<string> $options */
    public static function notAnOption(Field $field, string $raw, array $options): self
    {
        return self::coded(
            sprintf(
                '"%s" is not in the list. Options: %s',
                $raw,
                [] === $options ? '(no options defined)' : implode(', ', $options),
            ),
            RowErrorCode::NotAnOption,
            $field,
            // The English placeholder text stays out of the params: an application wording the
            // message itself decides what to say about a field with no options.
            ['value' => $raw, 'options' => implode(', ', $options)],
        );
    }

    public static function required(Field $field): self
    {
        return self::coded('This field is required and cannot be left empty.', RowErrorCode::Required, $field);
    }

    public static function noParser(Field $field): self
    {
        // A set-up mistake, not a cell failure: it stops the run before the first row is read,
        // never becomes a RowError, and so carries no code.
        return new self(sprintf('No parser is registered for the "%s" type.', $field->getType()->value));
    }

    /**
     * What the import loop copies onto `RowError::$code`.
     *
     * Null when the exception was built without one — a custom parser that calls
     * `new ParseException(...)` itself. Its message still reaches the user; only the code is
     * missing.
     */
    public function rowErrorCode(): ?RowErrorCode
    {
        return $this->rowErrorCode;
    }

    /**
     * What the import loop copies onto `RowError::$params`.
     *
     * @return array<string, string|int|float>
     */
    public function rowErrorParams(): array
    {
        return $this->rowErrorParams;
    }

    /**
     * Every coded failure carries the field's key and type first; the factory adds the rest.
     *
     * @param array<string, string|int|float> $params
     */
    private static function coded(string $message, RowErrorCode $code, Field $field, array $params = []): self
    {
        $exception = new self($message);
        $exception->rowErrorCode = $code;
        $exception->rowErrorParams = ['field' => $field->getKey(), 'type' => $field->getType()->value] + $params;

        return $exception;
    }
}
