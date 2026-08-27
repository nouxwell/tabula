<?php

declare(strict_types=1);

namespace Balin\Tabula\Schema;

/**
 * Hücre hizalaması.
 *
 * `Auto` varsayılandır ve alanın tipinden türetilir (bkz. FieldType::defaultAlign()):
 * sayı sağa, boole ve tarih ortaya, metin sola.
 */
enum Align: string
{
    case Auto = 'auto';
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';

    /** `Auto` ise tipten türet, değilse olduğu gibi bırak. */
    public function resolve(FieldType $type): self
    {
        return self::Auto === $this ? $type->defaultAlign() : $this;
    }
}
