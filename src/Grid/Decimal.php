<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Grid;

/**
 * Decimal numbers written as text, compared without ever becoming a float.
 *
 * The same rules as @nouxwell/tabula-core's `normalizeDecimal` and `compareDecimal`, so a limit
 * means the same in the browser and here. No extension is needed: bcmath may not be installed.
 *
 * @internal
 */
final class Decimal
{
    private const PATTERN = '/^([+-]?)(\d+)(?:\.(\d+))?$/';

    /**
     * One way of writing each number: no sign on zero, no leading zeros, no trailing zeros after the
     * point. `"-0012.3400"` becomes `"-12.34"`. Null for anything else: exponents, separators, text.
     */
    public static function normalize(string|int|float $input): ?string
    {
        if (\is_float($input)) {
            if (!is_finite($input)) {
                return null;
            }

            // Spelled out, never with an exponent; trailing zeros go below.
            $input = rtrim(rtrim(sprintf('%.15F', $input), '0'), '.');
        }

        if (1 !== preg_match(self::PATTERN, trim((string) $input), $match)) {
            return null;
        }

        $whole = ltrim($match[2], '0');
        $whole = '' === $whole ? '0' : $whole;
        $fraction = rtrim($match[3] ?? '', '0');
        $body = '' === $fraction ? $whole : $whole.'.'.$fraction;

        if ('0' === $body) {
            return '0';
        }

        return '-' === $match[1] ? '-'.$body : $body;
    }

    /**
     * -1, 0 or 1 as `$a` is below, equal to or above `$b`. Both must be as `normalize()` writes them.
     */
    public static function compare(string $a, string $b): int
    {
        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');

        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }

        $magnitude = self::compareMagnitude(ltrim($a, '-'), ltrim($b, '-'));

        return $negativeA ? -$magnitude : $magnitude;
    }

    private static function compareMagnitude(string $a, string $b): int
    {
        [$wholeA, $fractionA] = array_pad(explode('.', $a, 2), 2, '');
        [$wholeB, $fractionB] = array_pad(explode('.', $b, 2), 2, '');

        // No leading zeros, so the longer whole part is the larger number.
        if (\strlen($wholeA) !== \strlen($wholeB)) {
            return \strlen($wholeA) <=> \strlen($wholeB);
        }

        if ($wholeA !== $wholeB) {
            return strcmp($wholeA, $wholeB) <=> 0;
        }

        $length = max(\strlen($fractionA), \strlen($fractionB));

        return strcmp(str_pad($fractionA, $length, '0'), str_pad($fractionB, $length, '0')) <=> 0;
    }
}
