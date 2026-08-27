<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use RuntimeException;

/** Bir satırdan değer okunamadığında ya da biçimlendirilemediğinde fırlatılır. */
final class ValueException extends RuntimeException implements TabulaException
{
    public static function noFormatter(FieldType $type): self
    {
        return new self(sprintf('"%s" tipi için biçimlendirici kayıtlı değil.', $type->value));
    }

    public static function unreadableSource(Field $field, string $path, string $reason): self
    {
        return new self(sprintf(
            '"%s" alanı "%s" yolundan okunamadı: %s',
            $field->getKey(),
            $path,
            $reason,
        ));
    }

    public static function notAnEnum(Field $field, string $class): self
    {
        return new self(sprintf(
            '"%s" alanı enum olarak tanımlı ama "%s" bir enum sınıfı değil.',
            $field->getKey(),
            $class,
        ));
    }

    public static function unexpectedType(Field $field, mixed $value, string $expected): self
    {
        return new self(sprintf(
            '"%s" alanı %s bekliyordu, %s geldi.',
            $field->getKey(),
            $expected,
            get_debug_type($value),
        ));
    }
}
