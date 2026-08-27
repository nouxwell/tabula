<?php

declare(strict_types=1);

namespace Balin\Tabula\Contract;

/**
 * Kendi çeviri anahtarını bilen enum.
 *
 * Zorunlu değildir: `EnumFormatter` sırayla (1) bu arayüzü, (2) mevcut ERP'deki
 * `label(): string` gelenegini, (3) enum'un `value`/`name` değerini dener.
 * Böylece 200'den fazla mevcut enum hiç değiştirilmeden çalışır.
 */
interface TranslatableEnum
{
    /** Çeviri kataloğunda aranacak anahtar (ör. `purchase_status.open`). */
    public function translationKey(): string;
}
