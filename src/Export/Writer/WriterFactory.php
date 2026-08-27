<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Format;

/**
 * Biçime göre yazıcı üretir.
 *
 * `ExportBuilder` yazıcıyı doğrudan `new`lemek yerine buradan ister; böylece ayraç/BOM gibi
 * yazıcı ayarları uygulamanın yapılandırmasından gelebilir ve her çağrı yerinde elle
 * `->writer(new CsvWriter(...))` yazmak gerekmez.
 *
 * HER ÇAĞRIDA TAZE bir yazıcı dönmelidir: yazıcılar durum taşır (açık dosya tanıtıcısı,
 * aktif sayfa) ve paylaşılan bir örnek eşzamanlı iki dışa aktarmada birbirinin dosyasına yazar.
 */
interface WriterFactory
{
    public function for(Format $format): Writer;
}
