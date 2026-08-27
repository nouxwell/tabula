<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Source;

use Balin\Tabula\Exception\SourceException;
use Balin\Tabula\Exception\TabulaException;
use Balin\Tabula\Source\DataSource;
use Balin\Tabula\Source\DoctrineSource;
use Balin\Tabula\Tests\Fixture\QueryCallLog;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The part of `DoctrineSource`'s contract that DOES NOT TOUCH the database.
 *
 * There is no `pdo_*` driver in this environment, so a real query cannot be run. Nor is one
 * needed: the risky part of the class is not the SQL but the SET-UP logic — which mode it is
 * in, when chunked mode checks the ORDER BY requirement, and whether the fluent methods
 * overwrite one another's settings. All of it is observable without running a query:
 *
 *  - The `QueryBuilder` is built with a fake `EntityManagerInterface`; `select()/from()/
 *    orderBy()` only accumulate DQL fragments IN MEMORY and never ask for a connection.
 *  - For the pagination arithmetic, `createQuery()` returns a fake `Query` that hands the page
 *    back as a ready-made array; that way the offset progression and the limit value can be
 *    measured.
 */
#[CoversClass(DoctrineSource::class)]
#[CoversClass(SourceException::class)]
final class DoctrineSourceTest extends TestCase
{
    /**
     * Because the query is never run, the class given to `from()` does not have to be a real
     * Doctrine entity — the DQL only accumulates as text and is never parsed. All the same,
     * since the signature asks for a `class-string`, an existing class name is used.
     */
    private const string ENTITY = stdClass::class;

    // ---------------------------------------------------------------- set-up validation

    #[Test]
    public function itIsADataSource(): void
    {
        self::assertInstanceOf(DataSource::class, DoctrineSource::of($this->unorderedQuery()));
    }

    /**
     * Had the chunk size been 0, `paged()` would have read the same page for ever; had it been
     * negative, Doctrine would have silently ignored `setMaxResults()` and fetched the whole
     * table. The error is raised at SET-UP time, not when the reading starts — while the
     * caller's stack is still in view.
     */
    #[Test]
    #[DataProvider('chunkSizesBelowOne')]
    public function rejectsAChunkSizeBelowOne(int $size, string $expectedMessage): void
    {
        $source = DoctrineSource::of($this->orderedQuery());

        $this->expectException(SourceException::class);
        $this->expectExceptionMessage($expectedMessage);

        $source->chunk($size);
    }

    /** @return Generator<string, array{int, string}> */
    public static function chunkSizesBelowOne(): Generator
    {
        // The message must state both the MINIMUM value and the value that was given; saying
        // only "invalid" makes tracking down a number that came from configuration needlessly
        // hard.
        yield 'zero' => [0, 'The chunk size must be at least 1, 0 was given.'];
        yield 'negative' => [-1, 'The chunk size must be at least 1, -1 was given.'];
    }

    #[Test]
    public function theChunkSizeErrorIsPartOfTheLibrarysExceptionFamily(): void
    {
        // Being catchable on the application side with a single `catch (TabulaException)` matters.
        $this->expectException(TabulaException::class);

        DoctrineSource::of($this->orderedQuery())->chunk(0);
    }

    #[Test]
    public function theSmallestValidChunkSizeIsAccepted(): void
    {
        $source = DoctrineSource::of($this->orderedQuery())->chunk(1);

        self::assertTrue($source->isSafeToChunk());
    }

    // ---------------------------------------------------------------- isSafeToChunk()

    /**
     * In streaming mode the pagination arithmetic NEVER comes into play: a single query, row
     * by row hydration. Without an ordering no row can be skipped either, so no ORDER BY is
     * looked for.
     */
    #[Test]
    public function streamingIsSafeEvenWithoutAnOrderBy(): void
    {
        self::assertTrue(DoctrineSource::of($this->unorderedQuery())->isSafeToChunk());
    }

    #[Test]
    public function chunkingAnUnorderedQueryIsNotSafe(): void
    {
        self::assertFalse(DoctrineSource::of($this->unorderedQuery())->chunk(100)->isSafeToChunk());
    }

    #[Test]
    public function chunkingIsSafeWhenTheQueryWasBuiltWithOrderBy(): void
    {
        $queryBuilder = $this->unorderedQuery()->orderBy('c.id', 'ASC');

        self::assertTrue(DoctrineSource::of($queryBuilder)->chunk(100)->isSafeToChunk());
    }

    /**
     * `addOrderBy()` fills the same DQL fragment but calls `add()` in APPEND mode (orderBy()
     * replaces, addOrderBy() appends). The guard must look at whether the fragment is filled,
     * not at how it got filled, which is why both are exercised separately.
     */
    #[Test]
    public function chunkingIsSafeWhenTheQueryWasBuiltWithAddOrderBy(): void
    {
        $queryBuilder = $this->unorderedQuery()->addOrderBy('c.id', 'ASC');

        self::assertTrue(DoctrineSource::of($queryBuilder)->chunk(100)->isSafeToChunk());
    }

    /**
     * The `QueryBuilder` is held BY REFERENCE, not copied. That is deliberate: the caller may
     * create the source before finishing the set-up of the query. Consequently
     * `isSafeToChunk()` answers for the builder's state AT THAT MOMENT, not for its state at
     * the time the source was constructed.
     */
    #[Test]
    public function safetyReflectsTheBuildersCurrentStateNotItsStateAtConstructionTime(): void
    {
        $queryBuilder = $this->unorderedQuery();
        $source = DoctrineSource::of($queryBuilder)->chunk(100);

        self::assertFalse($source->isSafeToChunk());

        $queryBuilder->addOrderBy('c.id', 'ASC');

        self::assertTrue($source->isSafeToChunk());
    }

    // ---------------------------------------------------------------- the rows() guard

    /**
     * THE REASON THIS TEST EXISTS: `rows()` is a generator, so its body DOES NOT RUN when it is
     * called. That the guard stays silent here is therefore not a bug but the expected
     * behaviour — and that is exactly why the next test insists on a real walk.
     */
    #[Test]
    public function callingRowsWithoutIteratingRunsNothingAtAll(): void
    {
        $source = DoctrineSource::of($this->unorderedQuery())->chunk(500);

        $rows = $source->rows();

        self::assertInstanceOf(Generator::class, $rows, 'rows() must stay lazy: the body runs once the walk starts.');
    }

    /**
     * The real contract: unordered chunked reading blows up when the FIRST row is asked for and
     * does not produce so much as a SINGLE row. The test walking with a `foreach` is
     * essential — merely writing `expectException` and never consuming the generator would
     * stay green even if the guard were deleted altogether.
     */
    #[Test]
    public function chunkingWithoutAnOrderByThrowsAsSoonAsIterationStarts(): void
    {
        $source = DoctrineSource::of($this->unorderedQuery())->chunk(500);

        $rows = $source->rows();

        try {
            foreach ($rows as $ignored) {
                self::fail('Unordered chunked reading must not produce so much as a single row.');
            }

            self::fail('A SourceException should have been thrown once the walk started.');
        } catch (SourceException $exception) {
            self::assertStringContainsString('ORDER BY', $exception->getMessage());
            self::assertStringContainsString('addOrderBy', $exception->getMessage());
        }
    }

    #[Test]
    public function streamingNeverRunsTheOrderByGuard(): void
    {
        // No ordering AND no chunk(): streaming mode produces rows without ever passing the guard.
        $log = new QueryCallLog();
        $entityManager = $this->streamingEntityManager([['id' => 1], ['id' => 2]], $log);

        self::assertSame([1, 2], self::ids(DoctrineSource::of($this->unorderedQuery($entityManager))));
    }

    // ---------------------------------------------------------------- immutability of the fluent methods

    #[Test]
    public function chunkReturnsANewInstanceAndLeavesTheOriginalStreaming(): void
    {
        $original = DoctrineSource::of($this->unorderedQuery());

        $chunked = $original->chunk(10);

        self::assertNotSame($original, $chunked);
        self::assertTrue($original->isSafeToChunk(), 'The original source must stay in streaming mode.');
        self::assertFalse($chunked->isSafeToChunk(), 'The derived source must move into chunked mode.');
    }

    #[Test]
    public function withCountReturnsANewInstanceAndLeavesTheOriginalTotalUnknown(): void
    {
        $original = DoctrineSource::of($this->unorderedQuery());

        $counted = $original->withCount(42);

        self::assertNotSame($original, $counted);
        self::assertNull($original->count());
        self::assertSame(42, $counted->count());
    }

    #[Test]
    public function hydrateAsReturnsANewInstance(): void
    {
        $original = DoctrineSource::of($this->unorderedQuery());

        self::assertNotSame($original, $original->hydrateAs(AbstractQuery::HYDRATE_OBJECT));
    }

    /**
     * In a fluent chain each method must change ONLY ITS OWN setting. An order-dependent bug
     * (say, `withCount()` resetting the chunk size) only becomes visible when you look from the
     * other end of the chain.
     */
    #[Test]
    public function withCountPreservesTheChunkedMode(): void
    {
        $source = DoctrineSource::of($this->unorderedQuery())->chunk(10)->withCount(5);

        self::assertFalse($source->isSafeToChunk(), 'Chunked mode must be preserved after withCount().');
        self::assertSame(5, $source->count());
    }

    #[Test]
    public function chunkPreservesTheKnownTotal(): void
    {
        $source = DoctrineSource::of($this->orderedQuery())->withCount(7)->chunk(10);

        self::assertSame(7, $source->count());
        self::assertTrue($source->isSafeToChunk());
    }

    #[Test]
    public function hydrateAsPreservesBothTheChunkedModeAndTheTotal(): void
    {
        $source = DoctrineSource::of($this->unorderedQuery())
            ->chunk(10)
            ->withCount(7)
            ->hydrateAs(AbstractQuery::HYDRATE_OBJECT);

        self::assertFalse($source->isSafeToChunk(), 'Chunked mode must be preserved after hydrateAs().');
        self::assertSame(7, $source->count());
    }

    #[Test]
    public function withCountNullClearsAPreviouslyKnownTotal(): void
    {
        $source = DoctrineSource::of($this->unorderedQuery())->withCount(9)->withCount(null);

        self::assertNull($source->count());
    }

    // ---------------------------------------------------------------- count()

    #[Test]
    public function theTotalIsUnknownUnlessGiven(): void
    {
        // The source does NOT work the total out for itself: a second COUNT query is a cost the
        // export does not have to pay. The caller who knows it declares it.
        self::assertNull(DoctrineSource::of($this->unorderedQuery())->count());
    }

    // ---------------------------------------------------------------- chunk arithmetic

    /**
     * `isSafeToChunk()` only says "we are in chunked mode"; it does not say that the chunk SIZE
     * is still 10 by the end of the chain. Thanks to the fake `Query` the limit value that
     * reaches the query can be read directly, so the assertion stops being an indirect one.
     */
    #[Test]
    public function theChunkSizeSurvivesTheRestOfTheChainAndReachesTheQuery(): void
    {
        $log = new QueryCallLog();
        $entityManager = $this->pagingEntityManager([[['id' => 1], ['id' => 2]], [['id' => 3]]], $log);

        $source = DoctrineSource::of($this->orderedQuery($entityManager))
            ->chunk(2)
            ->withCount(5)
            ->hydrateAs(AbstractQuery::HYDRATE_ARRAY);

        self::assertSame([1, 2, 3], self::ids($source));
        self::assertSame([[0, 2], [2, 2], [4, 2]], $log->windows, 'The limit must stay 2 and the offset must advance by the chunk size.');
        self::assertSame(5, $source->count());
    }

    #[Test]
    public function pagingAsksOneMoreTimeWhenTheLastPageIsExactlyFull(): void
    {
        $log = new QueryCallLog();
        $entityManager = $this->pagingEntityManager(
            [[['id' => 1], ['id' => 2]], [['id' => 3], ['id' => 4]], []],
            $log,
        );

        self::assertSame([1, 2, 3, 4], self::ids(DoctrineSource::of($this->orderedQuery($entityManager))->chunk(2)));

        // Whether there is anything after an exactly full page can only be found out with an empty page.
        self::assertSame([[0, 2], [2, 2], [4, 2]], $log->windows);
    }

    /**
     * CRITICAL: a short page is NOT PROOF that the data has run out.
     *
     * `getResult()` returns the HYDRATED result; in queries with a join, repeated root rows are
     * collapsed into a single object, so 2,000 SQL rows can come down to 900 roots. On such a
     * page the rule "rows returned < chunk size ⇒ we are done" ends the export SILENTLY on the
     * first page — without raising an error either. The only valid end-of-data criterion is a
     * GENUINELY EMPTY page.
     */
    #[Test]
    public function aShortPageDoesNotEndTheExportBecauseHydrationCanCollapseRows(): void
    {
        $log = new QueryCallLog();
        // Page 1 asked for 2 rows but hydration brought it down to 1; the data is STILL going.
        $entityManager = $this->pagingEntityManager([[['id' => 1]], [['id' => 2], ['id' => 3]], []], $log);

        self::assertSame(
            [1, 2, 3],
            self::ids(DoctrineSource::of($this->orderedQuery($entityManager))->chunk(2)),
            'Had we stopped on the short page, rows 2 and 3 would have vanished silently.',
        );

        self::assertSame([[0, 2], [2, 2], [4, 2]], $log->windows, 'The offset must advance by SQL rows, not by the result of hydration.');
    }

    #[Test]
    public function pagingStopsOnlyOnAGenuinelyEmptyPage(): void
    {
        $log = new QueryCallLog();
        $entityManager = $this->pagingEntityManager([[['id' => 1]], []], $log);

        self::assertSame([1], self::ids(DoctrineSource::of($this->orderedQuery($entityManager))->chunk(2)));
        self::assertCount(2, $log->windows, 'One more round is made until an empty page is seen — that is the price of correctness.');
    }

    /**
     * Pagination MUST NOT WRITE `setFirstResult`/`setMaxResults` onto the caller's
     * `QueryBuilder`: code that uses the same builder afterwards (for a COUNT query, say) would
     * break silently.
     */
    #[Test]
    public function pagingLeavesTheCallersQueryBuilderUntouched(): void
    {
        $log = new QueryCallLog();
        $entityManager = $this->pagingEntityManager([[['id' => 1], ['id' => 2]], [['id' => 3]]], $log);
        $queryBuilder = $this->orderedQuery($entityManager);

        self::assertSame([1, 2, 3], self::ids(DoctrineSource::of($queryBuilder)->chunk(2)));

        self::assertSame(0, $queryBuilder->getFirstResult());
        self::assertNull($queryBuilder->getMaxResults());
    }

    // ---------------------------------------------------------------- hydration mode

    #[Test]
    public function pagingHydratesAsAnArrayByDefault(): void
    {
        // An export reads a projection, not an entity graph: the default is HYDRATE_ARRAY.
        $log = new QueryCallLog();
        $entityManager = $this->pagingEntityManager([[['id' => 1]]], $log);

        self::ids(DoctrineSource::of($this->orderedQuery($entityManager))->chunk(2));

        // Two rounds: because a short page does not count as the end, the empty page is asked for too.
        self::assertSame([AbstractQuery::HYDRATE_ARRAY, AbstractQuery::HYDRATE_ARRAY], $log->hydrations);
    }

    #[Test]
    public function hydrateAsReachesThePagedQuery(): void
    {
        $log = new QueryCallLog();
        $entityManager = $this->pagingEntityManager([[['id' => 1]]], $log);

        self::ids(
            DoctrineSource::of($this->orderedQuery($entityManager))
                ->chunk(2)
                ->hydrateAs(AbstractQuery::HYDRATE_SCALAR),
        );

        self::assertSame([AbstractQuery::HYDRATE_SCALAR, AbstractQuery::HYDRATE_SCALAR], $log->hydrations);
    }

    #[Test]
    public function streamingIssuesASingleQueryAndHydratesAsAnArrayByDefault(): void
    {
        $log = new QueryCallLog();
        $entityManager = $this->streamingEntityManager([['id' => 1], ['id' => 2]], $log);

        self::assertSame([1, 2], self::ids(DoctrineSource::of($this->unorderedQuery($entityManager))));
        self::assertSame([AbstractQuery::HYDRATE_ARRAY], $log->hydrations);
    }

    #[Test]
    public function hydrateAsReachesTheStreamedQuery(): void
    {
        $log = new QueryCallLog();
        $entityManager = $this->streamingEntityManager([['id' => 1]], $log);

        self::ids(
            DoctrineSource::of($this->unorderedQuery($entityManager))->hydrateAs(AbstractQuery::HYDRATE_SCALAR),
        );

        self::assertSame([AbstractQuery::HYDRATE_SCALAR], $log->hydrations);
    }

    // ---------------------------------------------------------------- helpers

    /** A query with no ORDER BY — the set-up chunked mode refuses. */
    private function unorderedQuery(?EntityManagerInterface $entityManager = null): QueryBuilder
    {
        // The tests that exercise the query set-up NEVER touch the EntityManager; using a stub
        // instead of a mock that has no expectations also avoids PHPUnit's "useless mock"
        // warning.
        $queryBuilder = new QueryBuilder($entityManager ?? $this->createStub(EntityManagerInterface::class));

        return $queryBuilder->select('c.code', 'c.name')->from(self::ENTITY, 'c');
    }

    private function orderedQuery(?EntityManagerInterface $entityManager = null): QueryBuilder
    {
        return $this->unorderedQuery($entityManager)->orderBy('c.id', 'ASC');
    }

    /**
     * The fake `EntityManager` that makes the chunk arithmetic observable.
     *
     * `QueryBuilder::getQuery()` calls `setParameters()`, `setFirstResult()` and
     * `setMaxResults()` in turn; the fake `Query` holds on to those values and, when
     * `getResult()` is called, writes the current window into the log and hands back the next
     * page. That way the pagination behaviour is measured without ever running a query.
     *
     * @param list<list<array<string, mixed>>> $pages the pages to be returned in order
     */
    private function pagingEntityManager(array $pages, QueryCallLog $log): EntityManagerInterface
    {
        $firstResult = 0;
        $maxResults = null;
        $pageIndex = 0;

        $query = $this->createStub(Query::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('setFirstResult')->willReturnCallback(
            static function (int $value) use ($query, &$firstResult): Query {
                $firstResult = $value;

                return $query;
            },
        );
        $query->method('setMaxResults')->willReturnCallback(
            static function (?int $value) use ($query, &$maxResults): Query {
                $maxResults = $value;

                return $query;
            },
        );
        $query->method('getResult')->willReturnCallback(
            /** @return list<array<string, mixed>> */
            static function (string|int $hydrationMode) use ($pages, $log, &$firstResult, &$maxResults, &$pageIndex): array {
                $log->windows[] = [$firstResult, $maxResults];
                $log->hydrations[] = $hydrationMode;

                return $pages[$pageIndex++] ?? [];
            },
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturn($query);

        return $entityManager;
    }

    /**
     * The fake `EntityManager` for streaming mode. `createQuery()` may be called at most ONCE —
     * streaming mode is by definition a single query, and a second call would mean the mode has
     * been broken.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function streamingEntityManager(array $rows, QueryCallLog $log): EntityManagerInterface
    {
        $query = $this->createStub(Query::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('setFirstResult')->willReturnSelf();
        $query->method('setMaxResults')->willReturnSelf();
        $query->method('toIterable')->willReturnCallback(
            /** @return list<array<string, mixed>> */
            static function (iterable $parameters, string|int|null $hydrationMode) use ($rows, $log): array {
                $log->hydrations[] = $hydrationMode ?? AbstractQuery::HYDRATE_OBJECT;

                return $rows;
            },
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('createQuery')->willReturn($query);

        return $entityManager;
    }

    /** @return list<mixed> */
    private static function ids(DataSource $source): array
    {
        $ids = [];

        foreach ($source->rows() as $row) {
            self::assertIsArray($row);
            $ids[] = $row['id'];
        }

        return $ids;
    }
}
