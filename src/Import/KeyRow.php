<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Import;

use Nouxwell\Tabula\Value\Parser\StringParser;

/**
 * Is a given row the hidden key row a template writes, or is it a header of translated labels?
 *
 * This exists to be called from OUTSIDE the library. An application that already has its own
 * reader — and most do, long before they meet this package — has to answer the same question
 * before it knows which row the data starts on, and answering it by hand means writing this
 * rule again somewhere else. Two copies of a rule drift, and when they do, the reader stops
 * being able to read the very files the writer produces.
 *
 * `HeaderMap` uses this same method, so there is one rule rather than two that merely agree
 * today.
 *
 * The rule is smaller than it looks, and each clause is there because of a specific way of
 * getting it wrong:
 *
 *  - A BLANK cell does not break the decision. A column the user added to the template has no
 *    counterpart in the hidden row; refusing the file over it would punish them for filling it
 *    in the way people actually do.
 *  - Every non-blank cell must be a known key. One that is not means row 1 is a header, and
 *    guessing otherwise would take a row of data for a header and drop it.
 *  - Matching is CASE SENSITIVE. A key is a canonical identifier, not prose; a schema may carry
 *    `code` and `Code` as separate fields, and folding case would conflate them. The tolerance
 *    that exists on the label side is there for the opposite reason.
 *  - ★ A wholly blank row is NOT a key row. "Every non-blank cell matched" is vacuously true of
 *    an empty row, so without this the first two rows of a file that simply starts with a blank
 *    line would be eaten as headers.
 */
final class KeyRow
{
    private function __construct()
    {
    }

    /**
     * @param list<mixed>  $cells     the row as read, column order preserved
     * @param list<string> $knownKeys every field key the caller recognises
     *
     * @return array<int, string>|null column index => key, or null when this is not a key row
     */
    public static function detect(array $cells, array $knownKeys): ?array
    {
        if ([] === $knownKeys) {
            return null;
        }

        $known = array_flip($knownKeys);
        $keys = [];

        foreach ($cells as $index => $cell) {
            if (StringParser::isBlank($cell)) {
                continue;
            }

            $text = StringParser::describe($cell);

            if (!isset($known[$text])) {
                return null;
            }

            $keys[$index] = $text;
        }

        return [] === $keys ? null : $keys;
    }

    /**
     * The same question when only a yes or no is needed.
     *
     * @param list<mixed>  $cells
     * @param list<string> $knownKeys
     */
    public static function matches(array $cells, array $knownKeys): bool
    {
        return null !== self::detect($cells, $knownKeys);
    }
}
