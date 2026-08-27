<?php

declare(strict_types=1);

namespace Balin\Tabula\Schema;

/**
 * Cell alignment.
 *
 * `Auto` is the default and is derived from the type of the field (see FieldType::defaultAlign()):
 * numbers to the right, booleans and dates to the center, text to the left.
 */
enum Align: string
{
    case Auto = 'auto';
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';

    /** If this is `Auto`, derive it from the type; otherwise leave it as it is. */
    public function resolve(FieldType $type): self
    {
        return self::Auto === $this ? $type->defaultAlign() : $this;
    }
}
