<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Fixture;

/**
 * Imitates a widespread convention of the system this replaces: the enum carries a `label()`
 * method that returns its own TRANSLATION KEY. `EnumFormatter` has to recognise that
 * convention even without the `TranslatableEnum` interface — more than 200 existing enums are
 * written this way.
 */
enum Status: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return 'status.'.$this->value;
    }
}
