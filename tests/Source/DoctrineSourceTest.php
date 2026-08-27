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
 * `DoctrineSource`'un veritabanına DOKUNMAYAN sözleşmesi.
 *
 * Bu ortamda `pdo_*` sürücüsü yok, yani gerçek bir sorgu çalıştırılamaz. Buna ihtiyaç da
 * yok: sınıfın riskli kısmı SQL değil, KURULUM mantığıdır — hangi kipte olduğu, parçalı
 * kipin ORDER BY şartını ne zaman denetlediği ve akıcı metotların birbirinin ayarını
 * ezip ezmediği. Hepsi sorgu çalıştırmadan gözlemlenebilir:
 *
 *  - `QueryBuilder` sahte bir `EntityManagerInterface` ile kurulur; `select()/from()/
 *    orderBy()` DQL parçalarını yalnızca BELLEKTE biriktirir, bağlantı istemez.
 *  - Sayfalama aritmetiği için `createQuery()` sahte bir `Query` döndürür ve sayfayı
 *    hazır dizi olarak verir; böylece offset ilerlemesi ve limit değeri ölçülebilir.
 */
#[CoversClass(DoctrineSource::class)]
#[CoversClass(SourceException::class)]
final class DoctrineSourceTest extends TestCase
{
    /**
     * Sorgu hiç çalıştırılmadığı için `from()`'a verilen sınıfın gerçek bir Doctrine
     * varlığı olması gerekmez — DQL yalnızca metin olarak birikir, ayrıştırılmaz. Yine de
     * imza `class-string` istediğinden var olan bir sınıf adı kullanılıyor.
     */
    private const string ENTITY = stdClass::class;

    // ---------------------------------------------------------------- kurulum doğrulaması

    #[Test]
    public function itIsADataSource(): void
    {
        self::assertInstanceOf(DataSource::class, DoctrineSource::of($this->unorderedQuery()));
    }

    /**
     * Parça boyutu 0 olsaydı `paged()` aynı sayfayı sonsuza dek okurdu, negatif olsaydı
     * Doctrine `setMaxResults()`'ı sessizce yok sayıp tüm tabloyu getirirdi. Hata okuma
     * başladığında değil KURULUMDA verilir — çağıranın yığını hâlâ görünürken.
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
        // Mesaj hem ASGARİ değeri hem de verilen değeri söylemeli; yalnızca "geçersiz"
        // demek, yapılandırmadan gelen bir sayıyı ayıklamayı gereksiz yere zorlaştırır.
        yield 'sıfır' => [0, 'Parça boyutu en az 1 olmalı, 0 verildi.'];
        yield 'negatif' => [-1, 'Parça boyutu en az 1 olmalı, -1 verildi.'];
    }

    #[Test]
    public function theChunkSizeErrorIsPartOfTheLibrarysExceptionFamily(): void
    {
        // Uygulama tarafında tek `catch (TabulaException)` ile yakalanabilmesi önemli.
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
     * Akış kipinde sayfalama aritmetiği HİÇ devreye girmez: tek sorgu, satır satır
     * hidrasyon. Sıralama olmadan da satır atlanamaz, o yüzden ORDER BY aranmaz.
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
     * `addOrderBy()` aynı DQL parçasını doldurur ama `add()`'i EKLEME kipinde çağırır
     * (orderBy() değiştirir, addOrderBy() ekler). Muhafız parçanın nasıl dolduğuna değil
     * dolu olup olmadığına bakmalı, o yüzden ikisi de ayrı ayrı sınanır.
     */
    #[Test]
    public function chunkingIsSafeWhenTheQueryWasBuiltWithAddOrderBy(): void
    {
        $queryBuilder = $this->unorderedQuery()->addOrderBy('c.id', 'ASC');

        self::assertTrue(DoctrineSource::of($queryBuilder)->chunk(100)->isSafeToChunk());
    }

    /**
     * `QueryBuilder` referansla tutulur, kopyalanmaz. Bu bilinçli: çağıran sorguyu
     * kurmayı bitirmeden kaynağı oluşturabilir. Dolayısıyla `isSafeToChunk()` builder'ın
     * O ANKİ hâlini yanıtlar, kaynağın kurulduğu andaki hâlini değil.
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

    // ---------------------------------------------------------------- rows() muhafızı

    /**
     * BU TESTİN VARLIK SEBEBİ: `rows()` bir generator'dır, çağrıldığında gövdesi
     * ÇALIŞMAZ. Yani muhafızın burada sessiz kalması hata değil, beklenen davranıştır —
     * ve tam da bu yüzden bir sonraki test gerçek gezintiyi zorunlu kılar.
     */
    #[Test]
    public function callingRowsWithoutIteratingRunsNothingAtAll(): void
    {
        $source = DoctrineSource::of($this->unorderedQuery())->chunk(500);

        $rows = $source->rows();

        self::assertInstanceOf(Generator::class, $rows, 'rows() tembel kalmalı: gövde gezinti başlayınca çalışır.');
    }

    /**
     * Asıl sözleşme: sıralamasız parçalı okuma İLK satır istendiğinde patlar ve TEK BİR
     * satır bile üretmez. Testin `foreach` ile gezinmesi şart — yalnızca `expectException`
     * yazıp generator'ı hiç tüketmemek, muhafız tamamen silinse bile yeşil kalırdı.
     */
    #[Test]
    public function chunkingWithoutAnOrderByThrowsAsSoonAsIterationStarts(): void
    {
        $source = DoctrineSource::of($this->unorderedQuery())->chunk(500);

        $rows = $source->rows();

        try {
            foreach ($rows as $ignored) {
                self::fail('Sıralamasız parçalı okumada tek bir satır bile üretilmemeli.');
            }

            self::fail('Gezinti başladığında SourceException fırlatılmalıydı.');
        } catch (SourceException $exception) {
            self::assertStringContainsString('ORDER BY', $exception->getMessage());
            self::assertStringContainsString('addOrderBy', $exception->getMessage());
        }
    }

    #[Test]
    public function streamingNeverRunsTheOrderByGuard(): void
    {
        // Sıralama YOK ve chunk() da yok: akış kipi muhafıza hiç uğramadan satır üretir.
        $log = new QueryCallLog();
        $entityManager = $this->streamingEntityManager([['id' => 1], ['id' => 2]], $log);

        self::assertSame([1, 2], self::ids(DoctrineSource::of($this->unorderedQuery($entityManager))));
    }

    // ---------------------------------------------------------------- akıcı metotların değişmezliği

    #[Test]
    public function chunkReturnsANewInstanceAndLeavesTheOriginalStreaming(): void
    {
        $original = DoctrineSource::of($this->unorderedQuery());

        $chunked = $original->chunk(10);

        self::assertNotSame($original, $chunked);
        self::assertTrue($original->isSafeToChunk(), 'Özgün kaynak akış kipinde kalmalı.');
        self::assertFalse($chunked->isSafeToChunk(), 'Türetilen kaynak parçalı kipe geçmeli.');
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
     * Akıcı zincirde her metot yalnızca KENDİ ayarını değiştirmeli. Sıra bağımlı bir hata
     * (ör. `withCount()`'un parça boyutunu sıfırlaması) ancak zincirin diğer ucundan
     * bakılınca görülür.
     */
    #[Test]
    public function withCountPreservesTheChunkedMode(): void
    {
        $source = DoctrineSource::of($this->unorderedQuery())->chunk(10)->withCount(5);

        self::assertFalse($source->isSafeToChunk(), 'Parçalı kip withCount() sonrası korunmalı.');
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

        self::assertFalse($source->isSafeToChunk(), 'Parçalı kip hydrateAs() sonrası korunmalı.');
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
        // Kaynak toplamı KENDİ hesaplamaz: ikinci bir COUNT sorgusu, dışa aktarmanın
        // ödemek zorunda olmadığı bir maliyettir. Bilen çağıran bildirir.
        self::assertNull(DoctrineSource::of($this->unorderedQuery())->count());
    }

    // ---------------------------------------------------------------- parça aritmetiği

    /**
     * `isSafeToChunk()` yalnızca "parçalı kipteyiz" der; parça BOYUTUNUN zincirin sonuna
     * kadar 10 kaldığını söylemez. Sahte `Query` sayesinde sorguya giden limit değeri
     * doğrudan okunabiliyor, dolayısıyla iddia dolaylı olmaktan çıkıyor.
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
        self::assertSame([[0, 2], [2, 2], [4, 2]], $log->windows, 'Limit 2 kalmalı, offset parça boyutu kadar ilerlemeli.');
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

        // Tam dolu bir sayfadan sonra devam edilip edilmeyeceği ancak boş sayfayla anlaşılır.
        self::assertSame([[0, 2], [2, 2], [4, 2]], $log->windows);
    }

    /**
     * KRİTİK: kısa bir sayfa, verinin bittiğinin KANITI DEĞİLDİR.
     *
     * `getResult()` HİDRATE EDİLMİŞ sonucu döndürür; birleştirmeli sorgularda tekrar eden kök
     * satırlar tek nesneye indirgendiği için 2000 SQL satırı 900 köke inebilir. "Dönen satır
     * sayısı < parça boyutu ⇒ bitti" kuralı böyle bir sayfada dışa aktarımı SESSİZCE ilk
     * sayfada bitirir — hata da vermez. Tek geçerli bitiş ölçütü GERÇEKTEN BOŞ sayfadır.
     */
    #[Test]
    public function aShortPageDoesNotEndTheExportBecauseHydrationCanCollapseRows(): void
    {
        $log = new QueryCallLog();
        // 1. sayfa 2 satır istedi ama hidrasyon 1'e indirdi; veri HÂLÂ devam ediyor.
        $entityManager = $this->pagingEntityManager([[['id' => 1]], [['id' => 2], ['id' => 3]], []], $log);

        self::assertSame(
            [1, 2, 3],
            self::ids(DoctrineSource::of($this->orderedQuery($entityManager))->chunk(2)),
            'Kısa sayfada durulsaydı 2. ve 3. satır sessizce kaybolurdu.',
        );

        self::assertSame([[0, 2], [2, 2], [4, 2]], $log->windows, 'Öfset hidrasyon sonucuna göre değil, SQL satırına göre ilerlemeli.');
    }

    #[Test]
    public function pagingStopsOnlyOnAGenuinelyEmptyPage(): void
    {
        $log = new QueryCallLog();
        $entityManager = $this->pagingEntityManager([[['id' => 1]], []], $log);

        self::assertSame([1], self::ids(DoctrineSource::of($this->orderedQuery($entityManager))->chunk(2)));
        self::assertCount(2, $log->windows, 'Boş sayfa görülene kadar bir tur daha atılır — bu, doğruluğun bedeli.');
    }

    /**
     * Sayfalama çağıranın `QueryBuilder`'ına `setFirstResult`/`setMaxResults` YAZMAMALI:
     * aynı builder'ı sonradan (ör. bir COUNT sorgusu için) kullanan kod sessizce bozulurdu.
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

    // ---------------------------------------------------------------- hidrasyon kipi

    #[Test]
    public function pagingHydratesAsAnArrayByDefault(): void
    {
        // Dışa aktarma projeksiyon okur, varlık grafiği değil: varsayılan HYDRATE_ARRAY.
        $log = new QueryCallLog();
        $entityManager = $this->pagingEntityManager([[['id' => 1]]], $log);

        self::ids(DoctrineSource::of($this->orderedQuery($entityManager))->chunk(2));

        // İki tur: kısa sayfa bitiş sayılmadığı için boş sayfa da sorulur.
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

    // ---------------------------------------------------------------- yardımcılar

    /** ORDER BY'sız sorgu — parçalı kipin reddettiği kurulum. */
    private function unorderedQuery(?EntityManagerInterface $entityManager = null): QueryBuilder
    {
        // Sorgu kurulumunu sınayan testler EntityManager'a HİÇ dokunmaz; beklentisi olmayan
        // bir mock yerine stub kullanmak PHPUnit'in "gereksiz mock" uyarısını da önler.
        $queryBuilder = new QueryBuilder($entityManager ?? $this->createStub(EntityManagerInterface::class));

        return $queryBuilder->select('c.code', 'c.name')->from(self::ENTITY, 'c');
    }

    private function orderedQuery(?EntityManagerInterface $entityManager = null): QueryBuilder
    {
        return $this->unorderedQuery($entityManager)->orderBy('c.id', 'ASC');
    }

    /**
     * Parça aritmetiğini gözlemlenebilir kılan sahte `EntityManager`.
     *
     * `QueryBuilder::getQuery()` sırayla `setParameters()`, `setFirstResult()` ve
     * `setMaxResults()` çağırır; sahte `Query` bu değerleri tutar ve `getResult()`
     * çağrıldığında o anki pencereyi deftere yazıp sıradaki sayfayı verir. Böylece sorgu
     * hiç çalıştırılmadan sayfalama davranışı ölçülür.
     *
     * @param list<list<array<string, mixed>>> $pages sırayla döndürülecek sayfalar
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
     * Akış kipi için sahte `EntityManager`. `createQuery()` en fazla BİR kez çağrılabilir —
     * akış kipinin tanımı zaten tek sorgudur, ikinci bir çağrı kipin bozulduğu anlamına gelir.
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
