<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Page;

/**
 * PDF yazıcısının ayarları.
 *
 * Xlsx ve CSV'nin aksine PDF'in FİZİKSEL bir sınırı vardır: kâğıdın eni. Bu yüzden
 * buradaki iki ana ayar renk/süsleme değil, geometridir — sayfa (`Page`) ve kolon
 * bütçesi (`ColumnBudget`). Geri kalan üç ayar da doğrudan okunabilirliğe bakar.
 *
 * Varsayılan A4 YATAY'dır. Mevcut ERP tüm listeleri A4 dikey basıyordu; on kolonlu bir
 * fatura listesi sayfaya sığmadığı için son kolonlar kâğıdın dışında kalıyor, kullanıcı
 * da eksik olduğunu ancak Excel çıktısıyla karşılaştırınca fark ediyordu. Yatay kâğıt
 * kullanılabilir eni 190 mm'den 277 mm'ye çıkarır: aynı bütçeyle 8 kolon yerine 12.
 */
final readonly class PdfOptions
{
    /**
     * Dompdf ile birlikte GELEN ve Latin Extended-A'yı kapsayan tek aile.
     *
     * Bu bir üslup tercihi DEĞİLDİR. Dompdf'in yerleşik "çekirdek" PDF yazı tipleri
     * (Helvetica, Times, Courier) WinAnsi/Latin-1 kodlamasına bağlıdır ve o kümede
     * "ş ğ ı İ" harfleri YOKTUR — karakter ya kutu olarak ya da hiç basılmaz. Paket
     * içinde bu harfleri taşıyan başka aile de bulunmaz; sisteme yeni bir TTF kurmadan
     * Türkçe basmanın tek yolu DejaVu ailesidir. Aileyi değiştirecek olan, seçtiği
     * yazı tipini Dompdf'e ayrıca kurmak (ve Latin Extended-A kapsadığını doğrulamak)
     * zorundadır; aksi hâlde çıktı sessizce eksik harfle basılır.
     */
    private const string BUNDLED_UNICODE_FONT = 'DejaVu Sans';

    /** Kâğıt geometrisi — ölçüler, yön ve kenar boşlukları. */
    public Page $page;

    /** Kolonların sayfaya nasıl sığdırılacağı; taşma stratejisi de burada. */
    public ColumnBudget $budget;

    /**
     * @param string  $fontFamily   Latin Extended-A kapsayan bir aile olmalı (bkz. yukarıdaki not)
     * @param float   $fontSizePt   Varsayılan 8 pt: 22 mm'lik asgari kolon genişliğinde iki-üç
     *                              kelimelik bir hücrenin tek satırda kaldığı en büyük punto.
     *                              10 pt'de aynı hücre üç satıra sarar ve satır yüksekliği
     *                              ikiye katlanır — sayfa sayısı da öyle.
     * @param bool    $repeatHeader Başlık satırı HER sayfada tekrarlansın mı. Kapatılırsa
     *                              başlık yalnız ilk sayfada kalır; uzun listelerde ikinci
     *                              sayfadan itibaren hangi kolonun ne olduğu anlaşılmaz.
     * @param ?string $title        Belge başlığı: hem PDF üstbilgisine (`<h1>`) hem de PDF
     *                              dosya özelliklerinin "Title" alanına yazılır. null ise
     *                              başlık basılmaz ve sayfa adı bu görevi üstlenir.
     */
    public function __construct(
        ?Page $page = null,
        ?ColumnBudget $budget = null,
        public string $fontFamily = self::BUNDLED_UNICODE_FONT,
        public float $fontSizePt = 8.0,
        public bool $repeatHeader = true,
        public ?string $title = null,
    ) {
        // KURULUM anında doğrula. Dompdf sıfır ya da negatif punto verildiğinde istisna
        // FIRLATMAZ: satır yüksekliğini sıfır hesaplar ve tablo görünmez bir şeride iner.
        // Kullanıcıya bu "boş PDF" olarak döner ve nedeni çıktıya bakarak anlaşılamaz.
        if ($fontSizePt <= 0) {
            throw WriterException::invalidFontSize($fontSizePt);
        }

        // Varsayılanlar imzada DEĞİL burada kurulur: PHP'de parametre varsayılanı sabit
        // ifade olmak zorundadır ve `Page::a4()->landscape()` bir statik çağrıdır.
        // `Page`in kurucusu da private (akıcı API tek kapıdan geçsin diye), yani
        // `new Page(...)` da yazılamaz. Bu yüzden null "varsayılanı kullan" demektir.
        $this->page = $page ?? Page::a4()->landscape();
        $this->budget = $budget ?? ColumnBudget::fit();
    }
}
