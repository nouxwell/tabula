<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Page;

/** Page orientation. */
enum Orientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';
}
