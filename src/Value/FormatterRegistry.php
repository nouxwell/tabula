<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value;

use Nouxwell\Tabula\Exception\ValueException;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Value\Formatter\BoolFormatter;
use Nouxwell\Tabula\Value\Formatter\DateFormatter;
use Nouxwell\Tabula\Value\Formatter\EnumFormatter;
use Nouxwell\Tabula\Value\Formatter\MoneyFormatter;
use Nouxwell\Tabula\Value\Formatter\NumberFormatter;
use Nouxwell\Tabula\Value\Formatter\StringFormatter;

/**
 * Maps a type to a formatter.
 *
 * Lookups are cached per type; once the row count runs into the hundreds of thousands, a
 * linear scan on every single cell is a measurable cost.
 *
 * The formatter in front wins: a custom formatter added through `with()` overrides the
 * built-in one. (In the system this replaces, the `normalize()` method had been
 * copied into 8 separate classes; here, changing the behaviour means adding a single class.)
 */
final class FormatterRegistry
{
    /** @var list<ValueFormatter> */
    private array $formatters;

    /** @var array<string, ValueFormatter> */
    private array $cache = [];

    public function __construct(ValueFormatter ...$formatters)
    {
        $this->formatters = array_values($formatters);
    }

    /** A registry wired up with the built-in formatters. */
    public static function default(): self
    {
        return new self(
            new StringFormatter(),
            new NumberFormatter(),
            new MoneyFormatter(),
            new BoolFormatter(),
            new DateFormatter(),
            new EnumFormatter(),
        );
    }

    /** Prepends the custom formatters TO THE FRONT, so they override the built-in ones. */
    public function with(ValueFormatter ...$formatters): self
    {
        return new self(...array_values($formatters), ...$this->formatters);
    }

    public function for(FieldType $type): ValueFormatter
    {
        return $this->cache[$type->value] ??= $this->find($type);
    }

    private function find(FieldType $type): ValueFormatter
    {
        foreach ($this->formatters as $formatter) {
            if ($formatter->supports($type)) {
                return $formatter;
            }
        }

        throw ValueException::noFormatter($type);
    }
}
