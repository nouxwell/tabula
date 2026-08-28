<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Source;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Nouxwell\Tabula\Exception\SourceException;

/**
 * A row source over a Doctrine `QueryBuilder`.
 *
 * There are TWO read modes, and the default one is the safer of the two:
 *
 *  1. STREAMING (the default) — a single query, `Query::toIterable()`. Doctrine hydrates the
 *     rows one by one; since pagination arithmetic never comes into play at all, there is NO
 *     risk of skipping or repeating rows. On top of that, `toIterable()` errors out on
 *     fetch-joined collections through Doctrine's own guard. This is the right choice for
 *     very nearly every export, and the `setFirstResult`/`setMaxResults` window put in place
 *     by the caller is preserved.
 *
 *  2. CHUNKED — `chunk(2000)`; every page is a separate query. Used when the driver buffers
 *     the whole result set, or when a single long-running query has to be avoided. It
 *     REQUIRES an ORDER BY.
 *
 * The hydration default is `HYDRATE_ARRAY`: an export reads a projection, not an entity graph.
 * A query such as `select('c.code', 'c.name')` produces array rows directly and `Field::from()`
 * reads them by field name.
 */
final class DoctrineSource implements DataSource
{
    /**
     * @param AbstractQuery::HYDRATE_*|string $hydrationMode
     */
    private function __construct(
        private readonly QueryBuilder $queryBuilder,
        private readonly ?int $chunkSize,
        private readonly int|string $hydrationMode,
        private readonly ?int $count,
    ) {
    }

    public static function of(QueryBuilder $queryBuilder): self
    {
        return new self($queryBuilder, null, AbstractQuery::HYDRATE_ARRAY, null);
    }

    /** Switches to chunked mode. If the query has no ORDER BY, `rows()` throws. */
    public function chunk(int $size): self
    {
        if ($size < 1) {
            throw SourceException::invalidChunkSize($size);
        }

        return new self($this->queryBuilder, $size, $this->hydrationMode, $this->count);
    }

    /**
     * Changes the hydration mode.
     *
     * ⚠ Take care with `AbstractQuery::HYDRATE_OBJECT`: the clean-up after hydration only
     * empties the hydrator's own maps, THE ENTITIES GO ON BEING MANAGED in the UnitOfWork.
     * An entity export of hundreds of thousands of rows will exhaust memory. This class does
     * NOT call `detach()`/`clear()` of its own accord — pulling the caller's objects out from
     * under them breeds worse surprises. If you do not genuinely need the entity, stay on
     * `HYDRATE_ARRAY`; if you do need it, manage the memory yourself.
     *
     * @param AbstractQuery::HYDRATE_*|string $hydrationMode a string for named custom hydrators
     */
    public function hydrateAs(int|string $hydrationMode): self
    {
        return new self($this->queryBuilder, $this->chunkSize, $hydrationMode, $this->count);
    }

    /** Declares the total row count if it is known (it is for the progress indicator, it does not affect reading). */
    public function withCount(?int $count): self
    {
        return new self($this->queryBuilder, $this->chunkSize, $this->hydrationMode, $count);
    }

    public function rows(): iterable
    {
        if (null === $this->chunkSize) {
            yield from $this->stream();

            return;
        }

        yield from $this->paged($this->chunkSize);
    }

    public function count(): ?int
    {
        return $this->count;
    }

    /** Whether chunked mode's ORDER BY requirement is met — can be asked without calling `rows()`. */
    public function isSafeToChunk(): bool
    {
        return null === $this->chunkSize || $this->hasOrderBy();
    }

    // ---------------------------------------------------------------- internals

    /** @return iterable<int, mixed> */
    private function stream(): iterable
    {
        yield from $this->queryBuilder->getQuery()->toIterable([], $this->hydrationMode);
    }

    /** @return iterable<int, mixed> */
    private function paged(int $chunkSize): iterable
    {
        if (!$this->hasOrderBy()) {
            throw SourceException::chunkingWithoutOrder();
        }

        // If the caller has put a window of their own in place (e.g. "a preview of at most 50
        // rows"), chunked mode WOULD OVERRIDE it and the whole table would flow out. Rather
        // than overriding it silently, we refuse.
        $callerLimit = $this->queryBuilder->getMaxResults();
        if (null !== $callerLimit) {
            throw SourceException::chunkingWithCallerLimit($callerLimit);
        }

        // The caller's starting offset is preserved.
        $offset = $this->queryBuilder->getFirstResult();

        while (true) {
            // The QueryBuilder is cloned for EVERY page: writing `setFirstResult` onto the
            // caller's object would silently break code using that same builder somewhere else.
            $page = (clone $this->queryBuilder)
                ->setFirstResult($offset)
                ->setMaxResults($chunkSize)
                ->getQuery()
                ->getResult($this->hydrationMode);

            if (!is_array($page) || [] === $page) {
                return;
            }

            yield from $page;

            // THE OFFSET ADVANCES BY SQL ROW, NOT by the hydration result.
            //
            // `getResult()` returns the hydrated result, and under entity/object hydration the
            // repeated root rows of a joined query are collapsed into a SINGLE object. Saying
            // "if the number of returned rows < the page size then we are done" would SILENTLY
            // end the export on the first page whenever 2,000 SQL rows collapsed into 900
            // roots. That is why the one and only termination criterion is a page that is
            // REALLY EMPTY.
            $offset += $chunkSize;
        }
    }

    private function hasOrderBy(): bool
    {
        $orderBy = $this->queryBuilder->getDQLPart('orderBy');

        return is_array($orderBy) ? [] !== $orderBy : null !== $orderBy;
    }
}
