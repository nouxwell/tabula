<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use RuntimeException;

/** Thrown when a value cannot be read out of a row, or cannot be formatted. */
final class ValueException extends RuntimeException implements TabulaException
{
    public static function noFormatter(FieldType $type): self
    {
        return new self(sprintf('No formatter is registered for the "%s" type.', $type->value));
    }

    public static function unreadableSource(Field $field, string $path, string $reason): self
    {
        return new self(sprintf(
            'Field "%s" could not be read from the path "%s": %s',
            $field->getKey(),
            $path,
            $reason,
        ));
    }

    public static function notAnEnum(Field $field, string $class): self
    {
        return new self(sprintf(
            'Field "%s" is declared as an enum but "%s" is not an enum class.',
            $field->getKey(),
            $class,
        ));
    }

    public static function unexpectedType(Field $field, mixed $value, string $expected): self
    {
        return new self(sprintf(
            'Field "%s" expected %s, got %s.',
            $field->getKey(),
            $expected,
            get_debug_type($value),
        ));
    }
}
