<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use Balin\Tabula\Schema\Field;
use RuntimeException;

/**
 * A single cell could not be converted to the type of its field.
 *
 * This exception DOES NOT STOP THE RUN: the import loop catches it, turns it into a `RowError`
 * carrying the row and field, and moves on to the next row (see `ErrorMode`). Because the
 * message is put in front of the user directly, it has to carry both halves of "which value,
 * and what was expected" — saying "invalid value" tells the user nothing.
 */
final class ParseException extends RuntimeException implements TabulaException
{
    public static function notANumber(Field $field, string $raw): self
    {
        return new self(sprintf('"%s" could not be read as a number.', $raw));
    }

    public static function notAnInteger(Field $field, string $raw): self
    {
        return new self(sprintf('"%s" is not a whole number.', $raw));
    }

    public static function notADate(Field $field, string $raw, string $expectedPattern): self
    {
        return new self(sprintf('"%s" could not be read as a date; expected format: %s', $raw, $expectedPattern));
    }

    /** @param list<string> $accepted */
    public static function notABoolean(Field $field, string $raw, array $accepted): self
    {
        return new self(sprintf(
            '"%s" is not a Yes/No value. Accepted: %s',
            $raw,
            implode(', ', $accepted),
        ));
    }

    /** @param list<string> $options */
    public static function notAnOption(Field $field, string $raw, array $options): self
    {
        return new self(sprintf(
            '"%s" is not in the list. Options: %s',
            $raw,
            [] === $options ? '(no options defined)' : implode(', ', $options),
        ));
    }

    public static function required(Field $field): self
    {
        return new self('This field is required and cannot be left empty.');
    }

    public static function noParser(Field $field): self
    {
        return new self(sprintf('No parser is registered for the "%s" type.', $field->getType()->value));
    }
}
