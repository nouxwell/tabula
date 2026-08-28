<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Page;

use Nouxwell\Tabula\Exception\ExportException;

/**
 * The geometry of a PDF page — the SINGLE source of truth.
 *
 * The most insidious bug of the system this replaces lived right here: the page size was defined
 * both in PHP (`Dompdf::setPaper()`) and in the template's `@page` CSS rule, and because Dompdf
 * applies the CSS at render time, the CSS SILENTLY won. The `setPaper()` call was effectively
 * decorative; the journal template said A5 landscape while the PHP on the very same path said A4
 * landscape, and nobody ever saw the difference.
 *
 * That is why the `@page` rule is produced by the LIBRARY here (`cssPageRule()`); `@page` is never
 * written into the template by hand. Where there are two sources, one of them is bound to lie.
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
     * The dimensions are given in PORTRAIT orientation; `landscape()` flips them afterwards.
     *
     * The floor is 1 mm rather than merely "greater than zero": because `cssPageRule()` prints the
     * measurement with two decimals, a value such as 0.004 mm would pass validation and land in the
     * rule as `0mm`, and Dompdf would silently fall back to ITS OWN default paper. A page smaller
     * than a millimetre is meaningless anyway; even the smallest real-world use (a label) is around
     * 25 mm.
     */
    public static function custom(float $widthMm, float $heightMm): self
    {
        if ($widthMm < 1.0 || $heightMm < 1.0) {
            throw ExportException::invalidPageSize($widthMm, $heightMm);
        }

        return new self($widthMm, $heightMm, Orientation::Portrait, 10.0, 10.0, 10.0, 10.0);
    }

    // ---------------------------------------------------------------- fluent settings

    public function landscape(): self
    {
        return $this->withOrientation(Orientation::Landscape);
    }

    public function portrait(): self
    {
        return $this->withOrientation(Orientation::Portrait);
    }

    /** The same margin on all four sides. */
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

    // ---------------------------------------------------------------- dimensions

    /** The width with the orientation applied. */
    public function widthMm(): float
    {
        return Orientation::Landscape === $this->orientation
            ? $this->portraitHeightMm
            : $this->portraitWidthMm;
    }

    /** The height with the orientation applied. */
    public function heightMm(): float
    {
        return Orientation::Landscape === $this->orientation
            ? $this->portraitWidthMm
            : $this->portraitHeightMm;
    }

    /** The real width the columns get to share: page width minus the left/right margins. */
    public function usableWidthMm(): float
    {
        return $this->widthMm() - $this->marginLeftMm - $this->marginRightMm;
    }

    /**
     * The `@page` rule to be embedded into the template.
     *
     * The rule is produced here so that PHP and the CSS CANNOT contradict each other.
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

    /** Print `210`, not `210.0` — both are valid in CSS, but the rule should stay readable. */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
