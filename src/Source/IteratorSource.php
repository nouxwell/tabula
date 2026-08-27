<?php

declare(strict_types=1);

namespace Balin\Tabula\Source;

use Closure;

/**
 * Generator ya da herhangi bir `Traversable` üzerinden akış.
 *
 * Sabit bellekte milyonlarca satır yazmak için doğru seçim.
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
     * Fabrika HER ÇAĞRIDA taze bir iterable üretmelidir; tükenmiş bir generator
     * ikinci kez okunamayacağı için doğrudan generator değil, onu üreten kapanış alınır.
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
