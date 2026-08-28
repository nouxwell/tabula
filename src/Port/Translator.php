<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Port;

/**
 * The translation port.
 *
 * The core knows nothing about Symfony; the bridge package wires this interface to the Symfony
 * Translator. The locale is passed explicitly ON EVERY CALL — implicit resolution is not safe,
 * because inside a queue worker there is no such thing as "the locale from the request".
 */
interface Translator
{
    /**
     * @param array<string, string|int|float> $params
     */
    public function trans(string $key, array $params = [], ?string $locale = null): string;
}
