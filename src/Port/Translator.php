<?php

declare(strict_types=1);

namespace Balin\Tabula\Port;

/**
 * Çeviri portu.
 *
 * Çekirdek Symfony'yi tanımaz; köprü paketi bu arayüzü Symfony Translator'a bağlar.
 * Locale HER ÇAĞRIDA açıkça geçirilir — kuyruk işçisinde "istekten gelen locale" diye
 * bir şey olmadığı için örtük çözümleme güvenli değildir.
 */
interface Translator
{
    /**
     * @param array<string, string|int|float> $params
     */
    public function trans(string $key, array $params = [], ?string $locale = null): string;
}
