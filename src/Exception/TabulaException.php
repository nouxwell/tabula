<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use Throwable;

/**
 * Kütüphanenin fırlattığı tüm istisnaların ortak arayüzü.
 *
 * Tüketici tek bir `catch (TabulaException $e)` ile kütüphane kaynaklı hataları
 * kendi hatalarından ayırabilir.
 */
interface TabulaException extends Throwable
{
}
