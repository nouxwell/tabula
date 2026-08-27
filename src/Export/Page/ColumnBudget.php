<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Page;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Schema\Priority;

/**
 * Kaç kolonun sayfaya sığdığını HESAPLAR ve sığmayanları ne yapacağına karar verir.
 *
 * Bir PDF'in fiziksel genişliği vardır, dolayısıyla kolon sayısı bir tercih değil,
 * hesaplanabilir bir bütçedir:
 *
 *     bütçe = floor( (sayfa eni − sol/sağ boşluk) ÷ asgari kolon genişliği )
 *
 * A4 yatayda 297 − 2×10 = 277 mm; 22 mm asgari genişlikle 12 kolon. Aynı şemayı A3 yataya
 * taşımak bütçeyi 18'e çıkarır — sayfa boyutunu değiştirmek kolon sayısını KENDİLİĞİNDEN
 * büyütür, elle ayar gerekmez.
 *
 * Bölme işi bilinçli olarak RENDER'DAN AYRILDI: `split()` saf bir fonksiyondur, Dompdf'e
 * ihtiyaç duymaz ve tek başına test edilebilir. Yazıcı yalnızca dönen grupları basar.
 */
final readonly class ColumnBudget
{
    private function __construct(
        private float $minWidthMm,
        private ?int $maxColumns,
        /** @var list<string> */
        private array $anchorKeys,
        private Overflow $overflow,
    ) {
    }

    /** Varsayılan: 22 mm asgari kolon, sert tavan yok, taşmada sayfa takımına böl. */
    public static function fit(): self
    {
        return new self(22.0, null, [], Overflow::NextPageSet);
    }

    /** Hiç bölme, hepsini sığdırmaya çalış. */
    public static function unlimited(): self
    {
        return new self(22.0, null, [], Overflow::Shrink);
    }

    /** Okunabilirlik tabanı: bir kolon bundan dar basılmaz. */
    public function minWidth(float $mm): self
    {
        if ($mm <= 0) {
            throw ExportException::invalidMinColumnWidth($mm);
        }

        return new self($mm, $this->maxColumns, $this->anchorKeys, $this->overflow);
    }

    /** Genişlik elverse bile bu sayıdan fazla kolon basma. */
    public function max(?int $columns): self
    {
        if (null !== $columns && $columns < 1) {
            throw ExportException::invalidMaxColumns($columns);
        }

        return new self($this->minWidthMm, $columns, $this->anchorKeys, $this->overflow);
    }

    /**
     * Her grupta tekrarlanacak kolonlar (ör. kod ve ünvan).
     *
     * İkinci gruba bakan okuyucu hangi satırda olduğunu ancak bunlarla bilir.
     * Dışa aktarımda YER ALMAYAN bir anahtar sessizce yok sayılır: kullanıcı o kolonu
     * seçmemişse çapa yapılacak bir şey yoktur, bu bir hata değil.
     */
    public function anchor(string ...$keys): self
    {
        return new self($this->minWidthMm, $this->maxColumns, array_values($keys), $this->overflow);
    }

    public function overflow(Overflow $overflow): self
    {
        return new self($this->minWidthMm, $this->maxColumns, $this->anchorKeys, $overflow);
    }

    // ---------------------------------------------------------------- hesap

    /** Sayfaya sığan kolon sayısı. */
    public function capacity(Page $page): int
    {
        $usable = $page->usableWidthMm();
        $fits = (int) floor($usable / $this->minWidthMm);

        if ($fits < 1) {
            throw ExportException::pageTooNarrow($usable, $this->minWidthMm);
        }

        return null === $this->maxColumns ? $fits : min($fits, $this->maxColumns);
    }

    /**
     * Kolonları sayfaya sığacak gruplara böler.
     *
     * Dönen her grup bir "sayfa takımı"dır: tüm satırlar önce 1. grubun kolonlarıyla basılır,
     * sonra 2. grubun kolonlarıyla baştan basılır.
     *
     * @param list<Column> $columns
     *
     * @return list<list<Column>> en az bir grup
     */
    public function split(array $columns, Page $page): array
    {
        if ([] === $columns) {
            return [];
        }

        // Shrink hiç hesap yapmaz: sığdırma işi tamamen render'a bırakılır.
        if (Overflow::Shrink === $this->overflow) {
            return [$columns];
        }

        $capacity = $this->capacity($page);

        if (count($columns) <= $capacity) {
            return [$columns];
        }

        return Overflow::Drop === $this->overflow
            ? [$this->dropByPriority($columns, $capacity)]
            : $this->intoPageSets($columns, $capacity);
    }

    /**
     * Önceliğe göre ele; kalanların ÖZGÜN SIRASI korunur.
     *
     * @param list<Column> $columns
     *
     * @return list<Column>
     */
    private function dropByPriority(array $columns, int $capacity): array
    {
        // `Always` ASLA düşmez — bu hem `Overflow::Drop`un hem `Priority`nin yazılı sözü.
        // Zorunlu kolonlar tek başına bütçeyi aşıyorsa sözü tutmanın yolu YOK: kesip
        // sessizce eksik bir belge basmaktansa duruyoruz. Çıktıda olması ŞART denen bir
        // kolonun kimseye haber verilmeden kaybolması, bu kütüphanenin ortadan kaldırmak
        // için var olduğu hata sınıfının ta kendisi.
        $mandatory = 0;
        foreach ($columns as $column) {
            if (Priority::Always === $column->priority) {
                ++$mandatory;
            }
        }

        if ($mandatory > $capacity) {
            throw ExportException::mandatoryColumnsExceedBudget($mandatory, $capacity);
        }

        $indexed = [];
        foreach ($columns as $index => $column) {
            $indexed[] = ['index' => $index, 'column' => $column];
        }

        // Önce Always, sonra Normal, en son Optional. Eşit öncelikte özgün sıra korunur
        // (usort kararlı olmasa da `index` karşılaştırması bunu garantiler).
        usort($indexed, static function (array $a, array $b): int {
            $byPriority = $a['column']->priority->weight() <=> $b['column']->priority->weight();

            return 0 !== $byPriority ? $byPriority : $a['index'] <=> $b['index'];
        });

        $kept = array_slice($indexed, 0, $capacity);

        // Elemeden sonra kolonları tekrar okunabilir sıraya sok.
        usort($kept, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_map(static fn (array $entry): Column => $entry['column'], $kept);
    }

    /**
     * Çapa kolonlar her grupta tekrarlanacak şekilde gruplara böler.
     *
     * @param list<Column> $columns
     *
     * @return list<list<Column>>
     */
    private function intoPageSets(array $columns, int $capacity): array
    {
        $anchors = [];
        $rest = [];

        foreach ($columns as $column) {
            if (in_array($column->key, $this->anchorKeys, true)) {
                $anchors[] = $column;
                continue;
            }

            $rest[] = $column;
        }

        $slots = $capacity - count($anchors);

        if ($slots < 1) {
            throw ExportException::anchorsFillTheBudget(count($anchors), $capacity);
        }

        // `$rest` burada asla boş olamaz: buraya yalnız `count($columns) > $capacity` iken
        // gelinir, dolayısıyla tüm kolonlar çapa olsaydı `$slots` negatif çıkar ve yukarıdaki
        // guard çoktan fırlatmış olurdu. Bu yüzden boş-grup yedeği YOK — olmayan bir durumu
        // anlatan ölü kod, okuyanı yanıltmaktan başka işe yaramaz.
        $groups = [];

        foreach (array_chunk($rest, $slots) as $chunk) {
            $groups[] = [...$anchors, ...$chunk];
        }

        return $groups;
    }
}
