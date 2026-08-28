<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Port;

/**
 * Translates nothing; returns the key exactly as it came in.
 *
 * Used in tests, and wherever the labels are already given as plain text.
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
