<?php

declare(strict_types=1);

namespace Balin\Tabula\Source;

/**
 * Elde hazır duran satır dizisi.
 *
 * "İster veriyi array şeklinde kendin yolla" durumunun karşılığı.
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

    /** Dizi kaynağında satır sayısı her zaman bilinir (arayüzün `?int` dönüşü daraltılır). */
    public function count(): int
    {
        return count($this->rows);
    }
}
