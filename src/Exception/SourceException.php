<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Exception;

use InvalidArgumentException;

/** Thrown when a data source is set up incorrectly. */
final class SourceException extends InvalidArgumentException implements TabulaException
{
    public static function invalidChunkSize(int $size): self
    {
        return new self(sprintf('The chunk size must be at least 1, %d was given.', $size));
    }

    /**
     * Without a stable ordering, offset-based pagination SKIPS and REPEATS rows.
     *
     * A database gives no guarantee about row order when no `ORDER BY` is supplied; while
     * reading page by page with `LIMIT/OFFSET`, the same row can show up on two pages at once
     * or never show up at all. In an export this is silent data corruption that nobody
     * notices — which is why we stop out loud.
     */
    /**
     * The `setMaxResults` window put in place by the caller is incompatible with chunked mode.
     *
     * Chunked mode writes `setMaxResults($chunkSize)` for every page; the caller's limit is
     * silently overwritten, and a query set up as a "preview of at most 50 rows" streams the
     * entire table out. Rather than overwrite it silently, we refuse.
     */
    public static function chunkingWithCallerLimit(int $limit): self
    {
        return new self(sprintf(
            'The query already has setMaxResults(%d); chunked reading would overwrite it. Either do not use chunk() '
            .'(streaming mode respects the window set by the caller), or remove the limit from the query.',
            $limit,
        ));
    }

    public static function chunkingWithoutOrder(): self
    {
        return new self(
            'Chunked reading requires an ORDER BY in the query: without an ordering, LIMIT/OFFSET skips '
            .'and repeats rows. Add an ordering on a unique field to the query '
            .'(e.g. ->addOrderBy(\'c.id\', \'ASC\')), or read in streaming mode without chunk().',
        );
    }
}
