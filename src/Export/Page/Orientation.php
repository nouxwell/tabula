<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Page;

/** Page orientation. */
enum Orientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';
}
