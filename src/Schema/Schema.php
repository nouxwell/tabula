<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Schema;

use Closure;
use Nouxwell\Tabula\Exception\SchemaException;
use Nouxwell\Tabula\Format;

/**
 * All of the field definitions of a table — the SINGLE source of truth.
 *
 * The same schema feeds three directions at once: export (xlsx/csv/pdf), import and template
 * generation. That is why a file that has been exported can be imported back with no conversion
 * whatsoever.
 */
final class Schema
{
    private string|Closure|null $title = null;

    /** @var array<string, Field> keyed by field key; declaration order is preserved */
    private array $fields = [];

    private function __construct(
        private readonly string $name,
    ) {
    }

    public static function make(string $name): self
    {
        if ('' === trim($name)) {
            throw SchemaException::emptyName();
        }

        return new self($name);
    }

    /** A translation key, plain text, or fn(string $locale): string */
    public function title(string|Closure $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    /**
     * Adds fields. Throws if the same key is given twice — there is no silent overwriting.
     */
    public function fields(Field ...$fields): self
    {
        $clone = clone $this;

        foreach ($fields as $field) {
            $key = $field->getKey();

            if (isset($clone->fields[$key])) {
                throw SchemaException::duplicateField($this->name, $key);
            }

            $clone->fields[$key] = $field;
        }

        return $clone;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string|Closure|null
    {
        return $this->title;
    }

    /** @return array<string, Field> */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** @return list<string> */
    public function getKeys(): array
    {
        return array_keys($this->fields);
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function field(string $key): Field
    {
        return $this->fields[$key] ?? throw SchemaException::unknownField($this->name, $key, $this->getKeys());
    }

    public function isEmpty(): bool
    {
        return [] === $this->fields;
    }

    /**
     * A sub-schema holding only the given keys, IN THE ORDER THEY WERE GIVEN.
     *
     * The columns the user picked pass through here — the client now sends keys only, not labels.
     * An unknown key is not swallowed silently; it becomes an error.
     *
     * @param list<string> $keys
     */
    public function only(array $keys): self
    {
        if ([] === $keys) {
            throw SchemaException::emptySelection($this->name);
        }

        $clone = clone $this;
        $clone->fields = [];

        foreach ($keys as $key) {
            if (!isset($this->fields[$key])) {
                throw SchemaException::unknownField($this->name, $key, $this->getKeys());
            }

            $clone->fields[$key] = $this->fields[$key];
        }

        return $clone;
    }

    /** Drops the fields that are not visible in the given format (see Field::only()). */
    public function forFormat(Format $format): self
    {
        $clone = clone $this;
        $clone->fields = array_filter(
            $this->fields,
            static fn (Field $field): bool => $field->appliesTo($format),
        );

        return $clone;
    }

    /** @return list<Field> */
    public function required(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (Field $field): bool => $field->isRequired(),
        ));
    }
}
