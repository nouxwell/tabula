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
 * Satır kaynaklarının sözleşmesi.
 *
 * Eski ERP motoru tüm satırları tek bir `Spreadsheet` nesnesine yığıp sonra dizeye
 * çeviriyordu; bu yüzden 10.000 satırlık sert bir tavan konmuştu. Buradaki kaynaklar
 * TEMBELDİR: `rows()` bir generator döndürür ve satırlar tüketildikçe üretilir.
 * Aşağıdaki testler bu tembelliği ve iki kez okunabilirliği doğrular.
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
        // Generator tek kullanımlıktır; ArraySource onu kurucu içinde diziye çevirir,
        // böylece kaynak sonradan defalarca okunabilir.
        $source = ArraySource::of(self::generateRows(3));

        self::assertSame(3, $source->count());
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], self::collect($source));
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], self::collect($source));
    }

    #[Test]
    public function arraySourceReindexesTheRows(): void
    {
        // Anahtarlı dizi verilse bile satırlar 0..n olarak sıralanır.
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
     * API'nin doğrudan generator değil, generator ÜRETEN kapanış almasının tek sebebi budur:
     * tükenmiş bir generator ikinci kez okunamaz. Boru hattı kaynağı birden çok kez
     * gezebildiği için (ör. önce sayfa bölme, sonra yazma) bu davranış sözleşmenin parçasıdır.
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
        self::assertSame($first, $second, 'İkinci gezinti boş dönmemeli.');
        self::assertSame(2, $factoryCalls, 'Her gezinti için taze bir generator üretilmeli.');
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
        self::assertSame(0, $factoryCalls, 'rows() çağrısı tek başına veriyi çekmemeli.');

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

        // Üçüncü sayfa hiç istenmez: eksik dolu sayfa son sayfadır.
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

        // Tam dolu sayfadan sonra devam edip edilmeyeceği ancak boş sayfayla anlaşılır.
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
        self::assertSame([[1, 1], [2, 1]], $calls, 'Toplam biliniyorsa fazladan tur atılmamalı.');
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
        self::assertSame([[0, 5]], $calls, 'Sıfır tabanlı sayfalayan API için ilk sayfa 0 olabilmeli.');
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
        self::assertSame([], $calls, 'rows() çağrısı tek başına sorgu atmamalı.');

        self::consume($rows);
        self::assertSame([[1, 3]], $calls);
    }

    #[Test]
    public function callableSourceRejectsAPageSizeBelowOne(): void
    {
        // Sayfa boyutu 0 olsaydı `rows()` sonsuz döngüye girerdi; hata kurulumda verilir.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sayfa boyutu en az 1 olmalı.');

        CallableSource::of(static fn (int $page, int $limit): array => [], 0);
    }

    #[Test]
    public function callableSourceCountIsUnknownUnlessGiven(): void
    {
        self::assertNull(CallableSource::of(static fn (int $page, int $limit): array => [])->count());
    }

    // ---------------------------------------------------------------- yardımcılar

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
            // Tembelliği ölçmek için tüketmek yeterli.
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
