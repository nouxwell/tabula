<?php

declare(strict_types=1);

namespace Balin\Tabula\Port;

/**
 * Hiçbir şey çevirmez, anahtarı olduğu gibi döndürür.
 *
 * Testlerde ve etiketlerin zaten düz metin verildiği durumlarda kullanılır.
 */
final class PassthroughTranslator implements Translator
{
    public function trans(string $key, array $params = [], ?string $locale = null): string
    {
        if ([] === $params) {
            return $key;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['%'.trim($name, '%').'%'] = (string) $value;
        }

        return strtr($key, $replacements);
    }
}
