<?php

declare(strict_types=1);

namespace Balin\Tabula\Source;

/**
 * Satır kaynağı.
 *
 * Tüm uygulamalar TEMBEL olmalıdır: `rows()` bir generator döndürür ve satırlar
 * tüketildikçe üretilir. Boru hattının tamamı akış tabanlı olduğu için bellek
 * satır sayısıyla birlikte büyümez.
 *
 * (Mevcut ERP motoru tüm satırları tek bir `Spreadsheet` nesnesine yığıp sonra
 * dizeye çeviriyordu; bu yüzden 10.000 satırlık sert bir tavan konmuştu.)
 */
interface DataSource
{
    /**
     * @return iterable<int, array<string, mixed>|object>
     */
    public function rows(): iterable;

    /**
     * Toplam satır sayısı biliniyorsa döner (ilerleme çubuğu için); bilinmiyorsa null.
     */
    public function count(): ?int;
}
