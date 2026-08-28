<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Source;

/**
 * An array of rows already at hand.
 *
 * The counterpart of the "or just send the data over yourself as an array" case.
 */
final readonly class ArraySource implements DataSource
{
    /**
     * @param list<array<string, mixed>|object> $rows
     */
    private function __construct(
        private array $rows,
    ) {
    }

    /**
     * @param iterable<array<string, mixed>|object> $rows
     */
    public static function of(iterable $rows): self
    {
        return new self(is_array($rows) ? array_values($rows) : iterator_to_array($rows, false));
    }

    public function rows(): iterable
    {
        yield from $this->rows;
    }

    /** With an array source the row count is always known (the interface's `?int` return is narrowed). */
    public function count(): int
    {
        return count($this->rows);
    }
}
