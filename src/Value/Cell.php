<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Schema\Align;

/**
 * A single formatted cell.
 *
 * It carries BOTH representations at once and lets the writer decide which one to use:
 *  - `value`  → the REAL value written to Excel (float/int/bool/string), together with
 *               `numberFormat`. That way the cell stays a number in Excel; it can be summed
 *               and sorted.
 *  - `text`   → the localised, visible text that CSV and PDF write.
 *
 * The system this replaces made no such distinction: Excel was given a number while
 * PDF and Twig were given a pre-formatted string, and CSV did not format decimals at all.
 */
final readonly class Cell
{
    public function __construct(
        public mixed $value,
        public string $text,
        public ?string $numberFormat = null,
        public Align $align = Align::Left,
    ) {
    }

    public static function text(string $text, Align $align = Align::Left): self
    {
        return new self($text, $text, null, $align);
    }

    public static function number(int|float $value, string $text, ?string $numberFormat = null, Align $align = Align::Right): self
    {
        return new self($value, $text, $numberFormat, $align);
    }

    public static function empty(string $text = '', Align $align = Align::Left): self
    {
        return new self(null, $text, null, $align);
    }

    public function isEmpty(): bool
    {
        return null === $this->value && '' === $this->text;
    }
}
