<?php

declare(strict_types=1);

namespace Balin\Tabula\Schema;

/**
 * PDF kolon bütçesinde alanın önem sırası.
 *
 * Sayfa fiziksel olarak dar kaldığında hangi kolonların kalacağını belirler:
 * `Always` asla düşmez (ve kolon grupları arasında çapa olarak tekrarlanabilir),
 * `Optional` ilk elenendir.
 */
enum Priority: string
{
    case Always = 'always';
    case Normal = 'normal';
    case Optional = 'optional';

    /** Eleme sırası — büyük değer önce elenir. */
    public function weight(): int
    {
        return match ($this) {
            self::Always => 0,
            self::Normal => 1,
            self::Optional => 2,
        };
    }
}
