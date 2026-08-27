<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Page;

use Balin\Tabula\Exception\ExportException;

/**
 * PDF sayfasının geometrisi — TEK doğruluk kaynağı.
 *
 * Mevcut ERP'nin en sinsi hatası buradaydı: sayfa boyutu hem PHP'de (`Dompdf::setPaper()`)
 * hem şablonun `@page` CSS kuralında tanımlıydı ve Dompdf render anında CSS'i uyguladığı için
 * SESSİZCE CSS kazanıyordu. `setPaper()` çağrısı fiilen dekoratifti; journal şablonu A5 yatay
 * derken aynı yolun PHP'si A4 yatay diyordu ve kimse farkı görmüyordu.
 *
 * Bu yüzden burada `@page` kuralını KÜTÜPHANE üretir (`cssPageRule()`); şablona elle
 * `@page` yazılmaz. İki kaynak varsa biri mutlaka yalan söyler.
 */
final readonly class Page
{
    private function __construct(
        private float $portraitWidthMm,
        private float $portraitHeightMm,
        public Orientation $orientation,
        public float $marginTopMm,
        public float $marginRightMm,
        public float $marginBottomMm,
        public float $marginLeftMm,
    ) {
    }

    public static function of(PageSize $size): self
    {
        return new self($size->widthMm(), $size->heightMm(), Orientation::Portrait, 10.0, 10.0, 10.0, 10.0);
    }

    public static function a3(): self
    {
        return self::of(PageSize::A3);
    }

    public static function a4(): self
    {
        return self::of(PageSize::A4);
    }

    public static function a5(): self
    {
        return self::of(PageSize::A5);
    }

    public static function letter(): self
    {
        return self::of(PageSize::Letter);
    }

    /**
     * Ölçüler DİKEY yönde verilir; `landscape()` sonradan çevirir.
     *
     * Taban sınır 1 mm'dir, sıfırdan büyük olması değil: `cssPageRule()` ölçüyü iki ondalıkla
     * yazdığı için 0.004 mm gibi bir değer doğrulamayı geçip kurala `0mm` olarak düşerdi ve
     * Dompdf sessizce KENDİ varsayılan kâğıdına dönerdi. Bir milimetreden küçük sayfa zaten
     * anlamsız; en küçük gerçek kullanım (etiket) bile 25 mm civarındadır.
     */
    public static function custom(float $widthMm, float $heightMm): self
    {
        if ($widthMm < 1.0 || $heightMm < 1.0) {
            throw ExportException::invalidPageSize($widthMm, $heightMm);
        }

        return new self($widthMm, $heightMm, Orientation::Portrait, 10.0, 10.0, 10.0, 10.0);
    }

    // ---------------------------------------------------------------- akıcı ayarlar

    public function landscape(): self
    {
        return $this->withOrientation(Orientation::Landscape);
    }

    public function portrait(): self
    {
        return $this->withOrientation(Orientation::Portrait);
    }

    /** Dört kenara aynı boşluk. */
    public function margins(float $mm): self
    {
        return $this->marginsOf($mm, $mm, $mm, $mm);
    }

    public function marginsOf(float $top, float $right, float $bottom, float $left): self
    {
        foreach ([$top, $right, $bottom, $left] as $margin) {
            if ($margin < 0) {
                throw ExportException::negativeMargin($margin);
            }
        }

        return new self(
            $this->portraitWidthMm,
            $this->portraitHeightMm,
            $this->orientation,
            $top,
            $right,
            $bottom,
            $left,
        );
    }

    // ---------------------------------------------------------------- ölçüler

    /** Yön uygulanmış genişlik. */
    public function widthMm(): float
    {
        return Orientation::Landscape === $this->orientation
            ? $this->portraitHeightMm
            : $this->portraitWidthMm;
    }

    /** Yön uygulanmış yükseklik. */
    public function heightMm(): float
    {
        return Orientation::Landscape === $this->orientation
            ? $this->portraitWidthMm
            : $this->portraitHeightMm;
    }

    /** Kolonların paylaşacağı gerçek genişlik: sayfa eni eksi sol/sağ boşluk. */
    public function usableWidthMm(): float
    {
        return $this->widthMm() - $this->marginLeftMm - $this->marginRightMm;
    }

    /**
     * Şablona gömülecek `@page` kuralı.
     *
     * Kural buradan üretilir ki PHP ile CSS'in çelişmesi MÜMKÜN olmasın.
     */
    public function cssPageRule(): string
    {
        return sprintf(
            '@page { size: %smm %smm; margin: %smm %smm %smm %smm; }',
            $this->trim($this->widthMm()),
            $this->trim($this->heightMm()),
            $this->trim($this->marginTopMm),
            $this->trim($this->marginRightMm),
            $this->trim($this->marginBottomMm),
            $this->trim($this->marginLeftMm),
        );
    }

    private function withOrientation(Orientation $orientation): self
    {
        return new self(
            $this->portraitWidthMm,
            $this->portraitHeightMm,
            $orientation,
            $this->marginTopMm,
            $this->marginRightMm,
            $this->marginBottomMm,
            $this->marginLeftMm,
        );
    }

    /** `210` yazsın, `210.0` değil — CSS'te ikisi de geçerli ama kural okunur kalsın. */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
