<?php

declare(strict_types=1);

namespace Balin\Tabula\Settings;

/** Para birimi simgesinin sayıya göre konumu. */
enum SymbolPosition: string
{
    case Before = 'before';
    case After = 'after';
    case None = 'none';
}
