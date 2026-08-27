<?php

declare(strict_types=1);

namespace Balin\Tabula\Source;

use Closure;

/**
 * Streams over a generator, or over any `Traversable`.
 *
 * The right choice for writing millions of rows in constant memory.
 */
final readonly class IteratorSource implements DataSource
{
    /**
     * @param Closure(): iterable<int, array<string, mixed>|object> $factory
     */
    private function __construct(
        private Closure $factory,
        private ?int $count,
    ) {
    }

    /**
     * The factory must produce a FRESH iterable ON EVERY CALL; since an exhausted generator
     * cannot be read a second time, what is taken is not the generator itself but the closure
     * that produces it.
     *
     * @param Closure(): iterable<int, array<string, mixed>|object> $factory
     */
    public static function of(Closure $factory, ?int $count = null): self
    {
        return new self($factory, $count);
    }

    public function rows(): iterable
    {
        yield from ($this->factory)();
    }

    public function count(): ?int
    {
        return $this->count;
    }
}
