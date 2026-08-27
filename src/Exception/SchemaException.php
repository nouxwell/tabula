<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use InvalidArgumentException;

/** Şema tanımı ya da alan seçimi hatalı olduğunda fırlatılır. */
final class SchemaException extends InvalidArgumentException implements TabulaException
{
    public static function emptyName(): self
    {
        return new self('Şema adı boş olamaz.');
    }

    public static function duplicateField(string $schema, string $key): self
    {
        return new self(sprintf('"%s" şemasında "%s" alanı birden fazla kez tanımlandı.', $schema, $key));
    }

    /** @param list<string> $known */
    public static function unknownField(string $schema, string $key, array $known): self
    {
        return new self(sprintf(
            '"%s" şemasında "%s" diye bir alan yok. Tanımlı alanlar: %s',
            $schema,
            $key,
            [] === $known ? '(hiç yok)' : implode(', ', $known),
        ));
    }

    public static function emptySelection(string $schema): self
    {
        return new self(sprintf('"%s" şemasından en az bir alan seçilmeli.', $schema));
    }
}
