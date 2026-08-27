<?php

declare(strict_types=1);

namespace Balin\Tabula\Value\Parser;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Value\Formatter\NumberFormatter;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ValueParser;
use Stringable;

/**
 * The parser for money fields.
 *
 * Number resolution is SHARED with `NumberFormatter::parse()` (see `NumberParser`); the only
 * extra job of this class is STRIPPING THE SYMBOL. The export puts the symbol into the
 * cell's format code, not into the text — but while filling in the template the user pastes
 * "1.234,56 ₺", or copies "$1,234.56" out of another system.
 *
 * Stripping the symbol by hand may look unnecessary: `NumberFormatter` already deletes every
 * character that is neither a digit nor a separator. But it is dangerous precisely because
 * it deletes them — a symbol that contains a dot (notations such as `S/.`, or the forms used
 * together with codes like `Kč`) leaves the string "1.234,56." behind after the cleanup; the
 * right-most dot no longer counts as the decimal point and the value is read as 123456, that
 * is, a HUNDRED TIMES too large. That is the most expensive mistake that can be made in
 * accounting data, which is why the symbol is stripped before it reaches the number.
 *
 * The minus sign, the parentheses and the trailing minus are PRESERVED: sign detection is
 * done inside `NumberFormatter` on the raw text, and deleting them here would silently turn
 * a debit into a credit.
 */
final class MoneyParser implements ValueParser
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Money === $type;
    }

    public function parse(mixed $raw, Field $field, ParseContext $context): mixed
    {
        if (StringParser::isBlank($raw)) {
            return null;
        }

        $numbers = $context->settings->numbers;
        $candidate = $raw;

        if ($candidate instanceof Stringable) {
            $candidate = (string) $candidate;
        }

        if (is_string($candidate)) {
            $candidate = $this->stripSymbols($candidate, $field, $numbers);
        }

        // `preferLocalized: true` — see NumberParser. On the money side this was even more
        // critical: once the symbol was stripped, the text "1.234 ₺" turned into "1.234",
        // fell into the canonical shortcut and was read as 1.234 instead of 1234. In other
        // words OUR OWN export broke OUR OWN import, and because a valid float came out of
        // it no error showed up anywhere.
        $amount = NumberFormatter::parse($candidate, $numbers, preferLocalized: true);

        // The error message shows the RAW cell, not the intermediate form with the symbol
        // stripped off.
        if (null === $amount) {
            throw ParseException::notANumber($field, StringParser::describe($raw));
        }

        // Money is always a `float`: the same column returning `int` on one row and `float`
        // on the next produces silent type surprises on the receiving side (see NumberParser).
        return (float) $amount;
    }

    private function stripSymbols(string $text, Field $field, NumberSettings $numbers): string
    {
        $text = StringParser::clean($text);

        foreach ($this->symbolCandidates($field, $numbers) as $symbol) {
            $quoted = preg_quote($symbol, '/');
            // Only the LEADING and the TRAILING occurrence is stripped; anything mixed into
            // the middle of the number is the job of `NumberFormatter`'s cleanup step anyway.
            $text = preg_replace('/^\s*'.$quoted.'\s*|\s*'.$quoted.'\s*$/iu', '', $text) ?? $text;
        }

        return trim($text);
    }

    /**
     * The symbol candidates to strip — longest first.
     *
     * The order matters: if the candidate "US$" is not tried before "$", a lone "US" is left
     * behind. ALL configured symbols (and their codes) go onto the list, because a single
     * listing can hold TRY and USD rows side by side; the field's default currency does not
     * have to be the one in the cell.
     *
     * @return list<string>
     */
    private function symbolCandidates(Field $field, NumberSettings $numbers): array
    {
        $candidates = array_merge(
            array_values($numbers->currencySymbols),
            array_keys($numbers->currencySymbols),
        );

        $code = $this->currencyCode($field);
        if (null !== $code) {
            $candidates[] = $code;
            $symbol = $numbers->symbolFor($code);
            if (null !== $symbol) {
                $candidates[] = $symbol;
            }
        }

        $separators = [$numbers->decimalSeparator, $numbers->thousandSeparator];

        $candidates = array_filter(
            $candidates,
            static function (string $candidate) use ($separators): bool {
                $candidate = trim($candidate);

                // A candidate that could be part of the number is not stripped: if the
                // configuration holds "." or "," as a symbol, deleting it would multiply the
                // amount by a thousand.
                return '' !== $candidate
                    && !in_array($candidate, $separators, true)
                    && 1 !== preg_match('/^[0-9.,\s]+$/u', $candidate);
            },
        );

        $candidates = array_values(array_unique(array_map(trim(...), $candidates)));

        usort($candidates, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $candidates;
    }

    /**
     * The field's fixed currency code.
     *
     * A currency given as a closure (`->currency(fn ($row) => $row['currencyCode'])`) CANNOT
     * be resolved here: the closure expects a row, and during import there is no row yet.
     * Calling the closure with `null` would produce a `TypeError`, so it is skipped instead
     * (both the closure and `null` are filtered out by a single `is_string` check) — the
     * only thing lost is the convenience of stripping the symbol, the number itself is still
     * read correctly (the symbol falls away either through the other configured candidates
     * or through `NumberFormatter`'s cleanup step).
     */
    private function currencyCode(Field $field): ?string
    {
        $currency = $field->getCurrency();

        if (!is_string($currency)) {
            return null;
        }

        $code = trim($currency);

        return '' === $code ? null : $code;
    }
}
