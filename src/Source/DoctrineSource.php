<?php

declare(strict_types=1);

namespace Balin\Tabula\Source;

use Balin\Tabula\Exception\SourceException;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;

/**
 * Doctrine `QueryBuilder` üzerinden satır kaynağı.
 *
 * İKİ okuma kipi vardır ve varsayılan olan daha güvenli olanıdır:
 *
 *  1. AKIŞ (varsayılan) — tek sorgu, `Query::toIterable()`. Doctrine satırları teker teker
 *     hidrasyona sokar; sayfalama aritmetiği hiç devreye girmediği için satır atlama/tekrar
 *     riski YOKTUR. Ayrıca `toIterable()` getirme-birleştirmeli (fetch-join) koleksiyonlarda
 *     Doctrine'in kendi korumasıyla hata verir. Dışa aktarmaların neredeyse tamamı için
 *     doğru seçim budur ve çağıranın koyduğu `setFirstResult`/`setMaxResults` penceresi korunur.
 *
 *  2. PARÇALI — `chunk(2000)`; her sayfa ayrı bir sorgu. Sürücü tüm sonucu tamponluyorsa ya da
 *     tek uzun sorgudan kaçınmak gerekiyorsa kullanılır. ORDER BY ŞART KOŞAR.
 *
 * Hidrasyon varsayılanı `HYDRATE_ARRAY`'dir: dışa aktarma projeksiyon okur, varlık grafiği
 * değil. `select('c.code', 'c.name')` gibi bir sorgu doğrudan dizi satırlar üretir ve
 * `Field::from()` bunları alan adlarıyla okur.
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

    /** Parçalı kipe geçer. Sorguda ORDER BY yoksa `rows()` fırlatır. */
    public function chunk(int $size): self
    {
        if ($size < 1) {
            throw SourceException::invalidChunkSize($size);
        }

        return new self($this->queryBuilder, $size, $this->hydrationMode, $this->count);
    }

    /**
     * Hidrasyon kipini değiştirir.
     *
     * ⚠ `AbstractQuery::HYDRATE_OBJECT` ile dikkat: hidrasyon sonrası temizlik yalnızca
     * hidratörün kendi haritalarını boşaltır, VARLIKLAR UnitOfWork'te YÖNETİLMEYE DEVAM EDER.
     * Yüz binlerce satırlık bir varlık dışa aktarımı belleği tüketir. Bu sınıf kendiliğinden
     * `detach()`/`clear()` ÇAĞIRMAZ — çağıranın nesnelerini altından çekmek daha kötü sürprizler
     * doğurur. Varlığa gerçekten ihtiyacın yoksa `HYDRATE_ARRAY`de kal; ihtiyacın varsa
     * belleği kendin yönet.
     *
     * @param AbstractQuery::HYDRATE_*|string $hydrationMode adlandırılmış özel hidratörler için dize
     */
    public function hydrateAs(int|string $hydrationMode): self
    {
        return new self($this->queryBuilder, $this->chunkSize, $hydrationMode, $this->count);
    }

    /** Toplam satır sayısı biliniyorsa bildirir (ilerleme göstergesi içindir, okumayı etkilemez). */
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

    /** Parçalı kipin ORDER BY şartını sağlayıp sağlamadığı — `rows()` çağırmadan sorulabilir. */
    public function isSafeToChunk(): bool
    {
        return null === $this->chunkSize || $this->hasOrderBy();
    }

    // ---------------------------------------------------------------- iç

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

        // Çağıran kendi penceresini koymuşsa (ör. "önizleme en fazla 50 satır") parçalı kip onu
        // EZERDİ ve tüm tablo dışarı akardı. Sessizce ezmektense reddediyoruz.
        $callerLimit = $this->queryBuilder->getMaxResults();
        if (null !== $callerLimit) {
            throw SourceException::chunkingWithCallerLimit($callerLimit);
        }

        // Çağıranın başlangıç ofseti korunur.
        $offset = $this->queryBuilder->getFirstResult();

        while (true) {
            // QueryBuilder HER sayfada klonlanır: çağıranın nesnesine `setFirstResult`
            // yazmak, aynı builder'ı başka bir yerde kullanan kodu sessizce bozardı.
            $page = (clone $this->queryBuilder)
                ->setFirstResult($offset)
                ->setMaxResults($chunkSize)
                ->getQuery()
                ->getResult($this->hydrationMode);

            if (!is_array($page) || [] === $page) {
                return;
            }

            yield from $page;

            // ÖFSET SQL SATIRINA GÖRE İLERLER, hidrasyon sonucuna göre DEĞİL.
            //
            // `getResult()` hidrate edilmiş sonucu döndürür ve varlık/nesne hidrasyonunda
            // birleştirmeli sorgularda tekrar eden kök satırlar TEK nesneye indirgenir.
            // "dönen satır sayısı < sayfa boyutu ise bitti" demek, 2000 SQL satırının 900
            // köke indiği bir sayfada dışa aktarımı SESSİZCE ilk sayfada bitirirdi.
            // Bu yüzden tek bitiş ölçütü GERÇEKTEN BOŞ sayfadır.
            $offset += $chunkSize;
        }
    }

    private function hasOrderBy(): bool
    {
        $orderBy = $this->queryBuilder->getDQLPart('orderBy');

        return is_array($orderBy) ? [] !== $orderBy : null !== $orderBy;
    }
}
