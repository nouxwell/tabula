<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use Throwable;

/**
 * The common interface of every exception this library throws.
 *
 * A consumer can tell library-originated failures apart from its own with a single
 * `catch (TabulaException $e)`.
 */
interface TabulaException extends Throwable
{
}
