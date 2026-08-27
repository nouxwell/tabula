<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use InvalidArgumentException;

/** Veri kaynağı yanlış kurulduğunda fırlatılır. */
final class SourceException extends InvalidArgumentException implements TabulaException
{
    public static function invalidChunkSize(int $size): self
    {
        return new self(sprintf('Parça boyutu en az 1 olmalı, %d verildi.', $size));
    }

    /**
     * Ofset tabanlı sayfalama, kararlı bir sıralama olmadan satır ATLAR ve TEKRARLAR.
     *
     * Veritabanı `ORDER BY` verilmediğinde satır sırasını garanti etmez; `LIMIT/OFFSET`
     * ile sayfa sayfa okurken aynı satır iki sayfada birden çıkabilir ya da hiç çıkmayabilir.
     * Dışa aktarmada bu, kimsenin fark etmediği sessiz bir veri bozulmasıdır — o yüzden
     * yüksek sesle duruyoruz.
     */
    /**
     * Çağıranın koyduğu `setMaxResults` penceresi parçalı kiple bağdaşmaz.
     *
     * Parçalı kip her sayfada `setMaxResults($chunkSize)` yazar; çağıranın sınırı sessizce
     * ezilir ve "en fazla 50 satırlık önizleme" olarak kurulmuş bir sorgu tüm tabloyu dışarı
     * akıtır. Sessizce ezmektense reddediyoruz.
     */
    public static function chunkingWithCallerLimit(int $limit): self
    {
        return new self(sprintf(
            'Sorguda zaten setMaxResults(%d) var; parçalı okuma bunu ezerdi. Ya chunk() kullanmayın '
            .'(akış kipi çağıranın penceresine saygı duyar) ya da sınırı sorgudan kaldırın.',
            $limit,
        ));
    }

    public static function chunkingWithoutOrder(): self
    {
        return new self(
            'Parçalı okuma için sorguda ORDER BY şart: sıralama olmadan LIMIT/OFFSET satır atlar '
            .'ve tekrar eder. Sorguya benzersiz bir alan üzerinden sıralama ekleyin '
            .'(ör. ->addOrderBy(\'c.id\', \'ASC\')) ya da chunk() kullanmadan akış modunda okuyun.',
        );
    }
}
