<?php

declare(strict_types=1);

namespace Balin\Tabula\Port;

/**
 * Çerçevesiz varsayılan çeviri uygulaması — kütüphanenin tek başına çalışabilmesi için.
 *
 * Katalog: `['tr' => ['export.customer.code' => 'Kod']]`. Noktalı anahtarlar hem düz hem
 * iç içe dizi olarak aranır, böylece YAML'dan gelen iç içe yapı da doğrudan verilebilir.
 * Anahtar bulunamazsa anahtarın kendisi döner — çıktı hiçbir zaman boş kalmaz.
 */
final class ArrayTranslator implements Translator
{
    /**
     * @param array<string, array<string, mixed>> $catalogues locale => anahtar/metin haritası
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

        // Düz anahtar: ['export.customer.code' => 'Kod']
        if (isset($catalogue[$key]) && is_string($catalogue[$key])) {
            return $catalogue[$key];
        }

        // İç içe anahtar: ['export' => ['customer' => ['code' => 'Kod']]]
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
