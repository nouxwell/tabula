<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Source;

use Balin\Tabula\Source\ArraySource;
use Balin\Tabula\Source\CallableSource;
use Balin\Tabula\Source\DataSource;
use Balin\Tabula\Source\IteratorSource;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The contract of the row sources.
 *
 * The engine of the system this replaces piled every row into a single `Spreadsheet` object
 * and then turned it into strings; that is why a hard ceiling of 10,000 rows had been put in
 * place. The sources here are LAZY: `rows()` returns a generator and the rows are produced as
 * they are consumed. The tests below verify that laziness and the ability to be read twice.
 */
#[CoversClass(ArraySource::class)]
#[CoversClass(IteratorSource::class)]
#[CoversClass(CallableSource::class)]
final class SourcesTest extends TestCase
{
    // ---------------------------------------------------------------- ArraySource

    #[Test]
    public function arraySourceYieldsTheGivenRows(): void
    {
        $source = ArraySource::of([['id' => 1], ['id' => 2]]);

        self::assertSame([['id' => 1], ['id' => 2]], self::collect($source));
        self::assertSame(2, $source->count());
    }

    #[Test]
    public function arraySourceCanBeIteratedRepeatedly(): void
    {
        $source = ArraySource::of([['id' => 1], ['id' => 2]]);

        self::assertSame(self::collect($source), self::collect($source));
    }

    #[Test]
    public function arraySourceMaterialisesAGenerator(): void
    {
        // A generator is single-use; ArraySource turns it into an array inside the
        // constructor, so that the source can afterwards be read over and over again.
        $source = ArraySource::of(self::generateRows(3));

        self::assertSame(3, $source->count());
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], self::collect($source));
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], self::collect($source));
    }

    #[Test]
    public function arraySourceReindexesTheRows(): void
    {
        // Even when a keyed array is given, the rows are numbered 0..n.
        $source = ArraySource::of(['first' => ['id' => 1], 'second' => ['id' => 2]]);

        self::assertSame([['id' => 1], ['id' => 2]], self::collect($source));
    }

    #[Test]
    public function arraySourceCountsAnEmptySetAsZeroNotNull(): void
    {
        $source = ArraySource::of([]);

        self::assertSame(0, $source->count());
        self::assertSame([], self::collect($source));
    }

    // ---------------------------------------------------------------- IteratorSource

    /**
     * This is the one and only reason why the API takes a closure that PRODUCES a generator
     * rather than a generator itself: an exhausted generator cannot be read a second time.
     * Because the pipeline can walk the source more than once (e.g. paginating first, writing
     * afterwards), this behaviour is part of the contract.
     */
    #[Test]
    public function iteratorSourceCanBeIteratedTwiceBecauseTheFactoryMakesAFreshGenerator(): void
    {
        $factoryCalls = 0;
        $source = IteratorSource::of(static function () use (&$factoryCalls): Generator {
            ++$factoryCalls;

            yield ['id' => 1];
            yield ['id' => 2];
        });

        $first = self::collect($source);
        $second = self::collect($source);

        self::assertSame([['id' => 1], ['id' => 2]], $first);
        self::assertSame($first, $second, 'The second walk must not come back empty.');
        self::assertSame(2, $factoryCalls, 'A fresh generator must be produced for every walk.');
    }

    #[Test]
    public function iteratorSourceDoesNotCallTheFactoryUntilIterationStarts(): void
    {
        $factoryCalls = 0;
        $source = IteratorSource::of(static function () use (&$factoryCalls): Generator {
            ++$factoryCalls;

            yield ['id' => 1];
        });

        $rows = $source->rows();
        self::assertSame(0, $factoryCalls, 'Calling rows() on its own must not pull the data.');

        self::consume($rows);
        self::assertSame(1, $factoryCalls);
    }

    #[Test]
    public function iteratorSourceAcceptsAnyIterableFromTheFactory(): void
    {
        $source = IteratorSource::of(static fn (): array => [['id' => 1], ['id' => 2]]);

        self::assertSame([['id' => 1], ['id' => 2]], self::collect($source));
    }

    #[Test]
    public function iteratorSourceCountIsUnknownUnlessGiven(): void
    {
        self::assertNull(IteratorSource::of(static fn (): array => [])->count());
        self::assertSame(42, IteratorSource::of(static fn (): array => [], 42)->count());
    }

    // ---------------------------------------------------------------- CallableSource

    #[Test]
    public function callableSourcePagesUntilAShortPage(): void
    {
        $calls = [];
        $source = CallableSource::of(
            static function (int $page, int $limit) use (&$calls): array {
                $calls[] = [$page, $limit];

                return match ($page) {
                    1 => [['id' => 1], ['id' => 2]],
                    2 => [['id' => 3]],
                    default => [],
                };
            },
            2,
        );

        self::assertSame([1, 2, 3], self::ids($source));

        // The third page is never asked for: a page that came back only partly filled is the last page.
        self::assertSame([[1, 2], [2, 2]], $calls);
    }

    #[Test]
    public function callableSourceAsksOneMoreTimeWhenTheLastPageIsExactlyFull(): void
    {
        $calls = [];
        $source = CallableSource::of(
            static function (int $page, int $limit) use (&$calls): array {
                $calls[] = [$page, $limit];

                return 1 === $page ? [['id' => 1], ['id' => 2]] : [];
            },
            2,
        );

        self::assertSame([1, 2], self::ids($source));

        // Whether there is anything after an exactly full page can only be found out with an empty page.
        self::assertSame([[1, 2], [2, 2]], $calls);
    }

    #[Test]
    public function callableSourceStopsEarlyWhenTheTotalIsKnown(): void
    {
        $calls = [];
        $source = CallableSource::of(
            static function (int $page, int $limit) use (&$calls): array {
                $calls[] = [$page, $limit];

                return [['id' => $page]];
            },
            1,
            2,
        );

        self::assertSame([1, 2], self::ids($source));
        self::assertSame([[1, 1], [2, 1]], $calls, 'If the total is known, no extra round must be made.');
        self::assertSame(2, $source->count());
    }

    #[Test]
    public function callableSourceHonoursTheFirstPageNumber(): void
    {
        $calls = [];
        $source = CallableSource::of(
            static function (int $page, int $limit) use (&$calls): array {
                $calls[] = [$page, $limit];

                return [];
            },
            5,
            null,
            0,
        );

        self::assertSame([], self::collect($source));
        self::assertSame([[0, 5]], $calls, 'For an API that paginates from zero, the first page must be allowed to be 0.');
    }

    #[Test]
    public function callableSourceAcceptsAGeneratorAsAPage(): void
    {
        $source = CallableSource::of(
            static function (int $page, int $limit): Generator {
                if (1 === $page) {
                    yield ['id' => 1];
                    yield ['id' => 2];
                }
            },
            2,
        );

        self::assertSame([1, 2], self::ids($source));
    }

    #[Test]
    public function callableSourceDoesNotFetchUntilIterationStarts(): void
    {
        $calls = [];
        $source = CallableSource::of(
            static function (int $page, int $limit) use (&$calls): array {
                $calls[] = [$page, $limit];

                return [];
            },
            3,
        );

        $rows = $source->rows();
        self::assertSame([], $calls, 'Calling rows() on its own must not issue a query.');

        self::consume($rows);
        self::assertSame([[1, 3]], $calls);
    }

    #[Test]
    public function callableSourceRejectsAPageSizeBelowOne(): void
    {
        // Had the page size been 0, `rows()` would have gone into an infinite loop; the error
        // is raised at set-up time.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The page size must be at least 1.');

        CallableSource::of(static fn (int $page, int $limit): array => [], 0);
    }

    #[Test]
    public function callableSourceCountIsUnknownUnlessGiven(): void
    {
        self::assertNull(CallableSource::of(static fn (int $page, int $limit): array => [])->count());
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<array<string, mixed>|object> */
    private static function collect(DataSource $source): array
    {
        $rows = [];

        foreach ($source->rows() as $row) {
            $rows[] = $row;
        }

        return $rows;
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

    /** @param iterable<mixed> $rows */
    private static function consume(iterable $rows): void
    {
        foreach ($rows as $ignored) {
            // Consuming is all it takes to measure the laziness.
        }
    }

    /** @return Generator<int, array{id: int}> */
    private static function generateRows(int $count): Generator
    {
        for ($i = 1; $i <= $count; ++$i) {
            yield ['id' => $i];
        }
    }
}
