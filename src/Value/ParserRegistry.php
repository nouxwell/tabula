<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Value\Parser\BoolParser;
use Balin\Tabula\Value\Parser\DateParser;
use Balin\Tabula\Value\Parser\EnumParser;
use Balin\Tabula\Value\Parser\MoneyParser;
use Balin\Tabula\Value\Parser\NumberParser;
use Balin\Tabula\Value\Parser\StringParser;

/**
 * Maps a type to a parser — the reverse-direction twin of `FormatterRegistry`.
 *
 * Lookups are cached per type; an import file holds tens of thousands of cells, and a linear
 * scan on every single cell is a measurable cost.
 *
 * The parser in front wins: a custom parser added through `with()` overrides the built-in
 * one. That way, teaching the library a project's own date dialect means writing a single
 * class rather than forking the library.
 */
final class ParserRegistry
{
    /** @var list<ValueParser> */
    private array $parsers;

    /** @var array<string, ValueParser> */
    private array $cache = [];

    public function __construct(ValueParser ...$parsers)
    {
        $this->parsers = array_values($parsers);
    }

    /** A registry wired up with the built-in parsers. */
    public static function default(): self
    {
        return new self(
            new StringParser(),
            new NumberParser(),
            new MoneyParser(),
            new BoolParser(),
            new DateParser(),
            new EnumParser(),
        );
    }

    /** Prepends the custom parsers TO THE FRONT, so they override the built-in ones. */
    public function with(ValueParser ...$parsers): self
    {
        return new self(...array_values($parsers), ...$this->parsers);
    }

    /**
     * The parser matching the field's type.
     *
     * The formatter twin takes a `FieldType`; this side takes the FIELD itself, because the
     * `ParseException::noParser()` thrown when no parser is found wants the field (which
     * column the error message is talking about is the only meaningful piece of information
     * for the user). The cache is still keyed per TYPE; caching per field would keep
     * needless copies in memory for the hundreds of fields that share a type.
     */
    public function for(Field $field): ValueParser
    {
        return $this->cache[$field->getType()->value] ??= $this->find($field);
    }

    private function find(Field $field): ValueParser
    {
        $type = $field->getType();

        foreach ($this->parsers as $parser) {
            if ($parser->supports($type)) {
                return $parser;
            }
        }

        throw ParseException::noParser($field);
    }
}
