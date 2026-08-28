<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Page;

/**
 * Standard paper sizes, in millimetres, in PORTRAIT orientation.
 *
 * Flipping to landscape happens over in `Page`; the enum only knows the raw dimensions.
 */
enum PageSize: string
{
    case A3 = 'a3';
    case A4 = 'a4';
    case A5 = 'a5';
    case Letter = 'letter';
    case Legal = 'legal';

    /** Portrait width (mm). */
    public function widthMm(): float
    {
        return match ($this) {
            self::A3 => 297.0,
            self::A4 => 210.0,
            self::A5 => 148.0,
            self::Letter => 215.9,
            self::Legal => 215.9,
        };
    }

    /** Portrait height (mm). */
    public function heightMm(): float
    {
        return match ($this) {
            self::A3 => 420.0,
            self::A4 => 297.0,
            self::A5 => 210.0,
            self::Letter => 279.4,
            self::Legal => 355.6,
        };
    }
}
