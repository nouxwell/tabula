<?php

declare(strict_types=1);

namespace Balin\Tabula\Source;

use Closure;
use InvalidArgumentException;

/**
 * A source that works with server-side pagination.
 *
 * The closure has the signature `fn(int $page, int $limit): iterable` and is called page by
 * page until an empty page comes back. Doctrine, an external API or a stored procedure — all
 * of them can be plugged in here; `DoctrineSource` (Phase 1) is a thin special case of this.
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
     * @param Closure(int, int): iterable<array<string, mixed>|object> $fetcher fn(page, limit)
     */
    public static function of(Closure $fetcher, int $pageSize = 1000, ?int $count = null, int $firstPage = 1): self
    {
        if ($pageSize < 1) {
            throw new InvalidArgumentException('The page size must be at least 1.');
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

            // An empty page, or a page that came back only partly filled = the last page.
            if (0 === $rowsInBatch || $rowsInBatch < $this->pageSize) {
                return;
            }

            // If the total is known, do not make one more round for nothing.
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
