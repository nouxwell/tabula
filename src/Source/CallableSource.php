<?php

declare(strict_types=1);

namespace Balin\Tabula\Source;

use Closure;
use InvalidArgumentException;

/**
 * Sunucu-taraflı sayfalama ile çalışan kaynak.
 *
 * Kapanış `fn(int $page, int $limit): iterable` imzasındadır ve boş sayfa dönene
 * kadar sayfa sayfa çağrılır. Doctrine, harici API ya da saklı yordam — hepsi
 * buradan bağlanabilir; `DoctrineSource` (Faz 1) bunun ince bir özel hâlidir.
 */
final readonly class CallableSource implements DataSource
{
    /**
     * @param Closure(int, int): iterable<array<string, mixed>|object> $fetcher
     */
    private function __construct(
        private Closure $fetcher,
        private int $pageSize,
        private ?int $count,
        private int $firstPage,
    ) {
    }

    /**
     * @param Closure(int, int): iterable<array<string, mixed>|object> $fetcher fn(sayfa, limit)
     */
    public static function of(Closure $fetcher, int $pageSize = 1000, ?int $count = null, int $firstPage = 1): self
    {
        if ($pageSize < 1) {
            throw new InvalidArgumentException('Sayfa boyutu en az 1 olmalı.');
        }

        return new self($fetcher, $pageSize, $count, $firstPage);
    }

    public function rows(): iterable
    {
        $page = $this->firstPage;
        $seen = 0;

        while (true) {
            $batch = ($this->fetcher)($page, $this->pageSize);
            $rowsInBatch = 0;

            foreach ($batch as $row) {
                ++$rowsInBatch;
                ++$seen;
                yield $row;
            }

            // Boş sayfa ya da eksik dolu sayfa = son sayfa.
            if (0 === $rowsInBatch || $rowsInBatch < $this->pageSize) {
                return;
            }

            // Toplam biliniyorsa gereksiz bir tur daha atma.
            if (null !== $this->count && $seen >= $this->count) {
                return;
            }

            ++$page;
        }
    }

    public function count(): ?int
    {
        return $this->count;
    }
}
