<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Port;

/**
 * The default, framework-free translator implementation — so the library can stand on its own.
 *
 * Catalogue: `['tr' => ['export.customer.code' => 'Kod']]`. Dotted keys are looked up both as
 * flat keys and as nested arrays, so a nested structure coming straight out of YAML can be
 * handed over as it is. When a key is not found, the key itself is returned — the output is
 * never left blank.
 */
final class ArrayTranslator implements Translator
{
    /**
     * @param array<string, array<string, mixed>> $catalogues locale => key/text map
     */
    public function __construct(
        private readonly array $catalogues = [],
        private readonly string $fallbackLocale = 'en',
    ) {
    }

    public function trans(string $key, array $params = [], ?string $locale = null): string
    {
        $text = $this->lookup($key, $locale ?? $this->fallbackLocale)
            ?? $this->lookup($key, $this->fallbackLocale)
            ?? $key;

        if ([] === $params) {
            return $text;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['%'.trim($name, '%').'%'] = (string) $value;
        }

        return strtr($text, $replacements);
    }

    private function lookup(string $key, string $locale): ?string
    {
        $catalogue = $this->catalogues[$locale] ?? null;

        if (null === $catalogue) {
            return null;
        }

        // Flat key: ['export.customer.code' => 'Kod']
        if (isset($catalogue[$key]) && is_string($catalogue[$key])) {
            return $catalogue[$key];
        }

        // Nested key: ['export' => ['customer' => ['code' => 'Kod']]]
        $node = $catalogue;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_string($node) ? $node : null;
    }
}
