<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Page;

/**
 * Sayfa geometrisini SONRADAN kabul edebilen yazıcı.
 *
 * `Writer` arayüzü kâğıttan hiç haberdar değildir ve öyle kalmalıdır: CSV'nin ve xlsx'in
 * sayfa boyutu diye bir kavramı yoktur, `open()/startSheet()/writeRow()` imzalarına `Page`
 * sızdırmak iki yazıcıyı da hiç kullanmayacakları bir kavrama bağlar, üstelik "bu yazıcı
 * sayfa ayarını yok sayıyor" gibi sessiz bir yalana kapı açardı.
 *
 * `ExportBuilder` bu yüzden dışa aktarma başına sayfa ayarını yazıcıya BU arayüz üzerinden
 * iter — arayüzü desteklemeyen yazıcıya hiç dokunmadan:
 *
 *     if ($writer instanceof PageAware) {
 *         $writer = $writer->withPage($page, $budget);
 *     }
 *
 * Yani kâğıt bilgisi `Writer` sözleşmesine değil, yalnız onu gerçekten kullanan yazıcıya
 * ulaşır.
 *
 * null geçilen argüman "elimdekini koru" demektir; böylece yalnız sayfayı büyütüp bütçeye
 * dokunmamak (ya da tersi) mümkün olur. `withPage(null, null)` da geçerlidir ve hiçbir şeyi
 * değiştirmez.
 *
 * Dönüş `static`tir çünkü ayar YERİNDE DEĞİŞTİRİLMEZ, yeni bir örnek döner: aynı yazıcı
 * örneği (ör. `ExportBuilder::writer()` ile elle verilmiş bir örnek) birden çok dışa
 * aktarmada paylaşılıyor olabilir ve birinin A3'ü diğerinin çıktısına sızmamalıdır.
 */
interface PageAware
{
    public function withPage(?Page $page, ?ColumnBudget $budget): static;
}
