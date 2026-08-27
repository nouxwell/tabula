<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Page;

/**
 * Kolonlar sayfaya sığmadığında ne yapılacağı.
 *
 * Mevcut ERP'de bu seçim hiç YOKTU: kolon sınırı da yoktu, `table-layout: fixed` altında
 * kolonlar okunamayacak kadar eziliyordu ve tek çare başlığı `<br>` ile elle bölen bir
 * yamaydı. Üç davranış da bilinçli bir tercih olmalı.
 */
enum Overflow: string
{
    /**
     * Kolonları GRUPLARA böl; her grup kendi sayfa takımına basılır.
     *
     * Çapa kolonlar (`ColumnBudget::anchor()`) her grupta tekrarlanır, böylece ikinci
     * gruba bakan okuyucu hangi satırda olduğunu bilir. Geniş tabloların kâğıda basılma
     * biçimi budur; hiçbir veri kaybolmaz.
     */
    case NextPageSet = 'next_page_set';

    /**
     * Düşük öncelikli kolonları ELE, tek grupta kal.
     *
     * `Priority::Optional` önce, sonra `Normal` düşer; `Always` asla düşmez.
     * Tek sayfalık özet çıktılarda doğru seçim — ama VERİ KAYBEDER, bilinçli seçilmeli.
     */
    case Drop = 'drop';

    /**
     * Hiç bölme, hepsini sığdırmaya çalış.
     *
     * Asgari genişlik kuralı yok sayılır; çok kolonda okunaksız olur. Kolon sayısının
     * gerçekten sınırlı olduğu ve bölünmesinin istenmediği çıktılar için.
     */
    case Shrink = 'shrink';
}
