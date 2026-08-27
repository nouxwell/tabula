<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use InvalidArgumentException;

/** Thrown when a schema definition or a field selection is invalid. */
final class SchemaException extends InvalidArgumentException implements TabulaException
{
    public static function emptyName(): self
    {
        return new self('The schema name cannot be empty.');
    }

    public static function duplicateField(string $schema, string $key): self
    {
        return new self(sprintf('The "%s" schema defines the field "%s" more than once.', $schema, $key));
    }

    /** @param list<string> $known */
    public static function unknownField(string $schema, string $key, array $known): self
    {
        return new self(sprintf(
            'The "%s" schema has no field called "%s". Defined fields: %s',
            $schema,
            $key,
            [] === $known ? '(none)' : implode(', ', $known),
        ));
    }

    public static function emptySelection(string $schema): self
    {
        return new self(sprintf('At least one field must be selected from the "%s" schema.', $schema));
    }
}
