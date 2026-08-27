<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Page;

/**
 * Standart kâğıt boyutları, DİKEY yönde milimetre olarak.
 *
 * Yatay çevirme `Page` tarafında yapılır; enum yalnız ham ölçüyü bilir.
 */
enum PageSize: string
{
    case A3 = 'a3';
    case A4 = 'a4';
    case A5 = 'a5';
    case Letter = 'letter';
    case Legal = 'legal';

    /** Dikey genişlik (mm). */
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

    /** Dikey yükseklik (mm). */
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
