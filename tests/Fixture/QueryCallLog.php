<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Fixture;

/**
 * The log of the calls that go to a fake `Doctrine\ORM\Query`.
 *
 * The only thing that can be exercised without a database is not the RESULT of the query but
 * WHAT WAS ASKED OF IT: which offset/limit window was opened, which hydration mode was
 * requested. Without this log, questions such as "did the chunk size stay 10?" could only be
 * answered indirectly (are we in chunked mode?).
 */
final class QueryCallLog
{
    /** @var list<array{int, int|null}> [firstResult, maxResults] for each page */
    public array $windows = [];

    /** @var list<string|int> the hydration mode requested for each read */
    public array $hydrations = [];
}
