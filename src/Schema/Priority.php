<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Schema;

/**
 * How important a field is within the PDF column budget.
 *
 * It decides which columns survive once the page is physically too narrow:
 * `Always` is never dropped (and can be repeated as an anchor across column groups),
 * `Optional` is the first to be dropped.
 */
enum Priority: string
{
    case Always = 'always';
    case Normal = 'normal';
    case Optional = 'optional';

    /** Drop order — the larger the value, the earlier it is dropped. */
    public function weight(): int
    {
        return match ($this) {
            self::Always => 0,
            self::Normal => 1,
            self::Optional => 2,
        };
    }
}
