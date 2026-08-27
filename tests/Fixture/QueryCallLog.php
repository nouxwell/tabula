<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Fixture;

/**
 * Sahte bir `Doctrine\ORM\Query`'ye giden çağrıların defteri.
 *
 * Veritabanı olmadan sınanabilen tek şey sorgunun SONUCU değil, sorguya NE İSTENDİĞİDİR:
 * hangi offset/limit penceresi açıldı, hangi hidrasyon kipi istendi. Bu defter olmasa
 * "parça boyutu 10 kaldı mı" gibi sorular yalnızca dolaylı olarak (parçalı kipteyiz mi)
 * yanıtlanabilirdi.
 */
final class QueryCallLog
{
    /** @var list<array{int, int|null}> her sayfa için [firstResult, maxResults] */
    public array $windows = [];

    /** @var list<string|int> her okuma için istenen hidrasyon kipi */
    public array $hydrations = [];
}
