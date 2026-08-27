<?php

declare(strict_types=1);

namespace Balin\Tabula\Contract;

/**
 * An enum that knows its own translation key.
 *
 * Implementing it is not mandatory: `EnumFormatter` tries, in order, (1) this interface,
 * (2) the `label(): string` convention of the system this replaces, (3) the `value`/`name`
 * of the enum. That way more than 200 pre-existing enums work without being touched at all.
 */
interface TranslatableEnum
{
    /** The key to look up in the translation catalogue (e.g. `purchase_status.open`). */
    public function translationKey(): string;
}
