<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Settings;

/** Where the currency symbol sits relative to the number. */
enum SymbolPosition: string
{
    case Before = 'before';
    case After = 'after';
    case None = 'none';
}
