<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Grid;

use Nouxwell\Tabula\Exception\SchemaException;

/**
 * A rule a field's value keeps, beyond its type and whether it is required.
 *
 * The browser checks it as the user types, and `RowChecker` checks it again before anything is
 * written: the browser's checks are for the user's sake, and promise nothing. Both read the same
 * rule, written once here, which is the point.
 *
 * Limits are decimal TEXT (`Rule::min('0.30')`), never floats: as a float, 0.1 + 0.2 is not 0.3, and
 * a rule saying "at least 0.30" would refuse a sum that meets it.
 */
final readonly class Rule
{
    public const MIN = 'min';
    public const MAX = 'max';
    public const MIN_LENGTH = 'minLength';
    public const MAX_LENGTH = 'maxLength';
    public const UNIQUE = 'unique';

    /**
     * @param self::MIN|self::MAX|self::MIN_LENGTH|self::MAX_LENGTH|self::UNIQUE $kind
     */
    private function __construct(
        public string $kind,
        public string|int|null $value,
    ) {
    }

    /** At least this number, as decimal text or a whole number. */
    public static function min(string|int $value): self
    {
        return new self(self::MIN, self::limit(self::MIN, $value));
    }

    /** At most this number, as decimal text or a whole number. */
    public static function max(string|int $value): self
    {
        return new self(self::MAX, self::limit(self::MAX, $value));
    }

    /** At least this many characters, as a reader counts them: an emoji is one. */
    public static function minLength(int $length): self
    {
        return new self(self::MIN_LENGTH, self::length(self::MIN_LENGTH, $length));
    }

    /** At most this many characters. */
    public static function maxLength(int $length): self
    {
        return new self(self::MAX_LENGTH, self::length(self::MAX_LENGTH, $length));
    }

    /** No two rows hold the same value in the field. */
    public static function unique(): self
    {
        return new self(self::UNIQUE, null);
    }

    /** Whether the rule is about a number, rather than about text or rows. */
    public function isNumeric(): bool
    {
        return self::MIN === $this->kind || self::MAX === $this->kind;
    }

    /** Whether the rule is about the length of text. */
    public function isLength(): bool
    {
        return self::MIN_LENGTH === $this->kind || self::MAX_LENGTH === $this->kind;
    }

    /**
     * As the browser's module schema writes it.
     *
     * @return array{rule: string, value?: string|int}
     */
    public function toArray(): array
    {
        return null === $this->value ? ['rule' => $this->kind] : ['rule' => $this->kind, 'value' => $this->value];
    }

    private static function limit(string $kind, string|int $value): string
    {
        return Decimal::normalize($value) ?? throw SchemaException::invalidRule($kind, sprintf('needs a number written as decimal text, such as "0.30"; got "%s"', $value));
    }

    private static function length(string $kind, int $length): int
    {
        if ($length < 0) {
            throw SchemaException::invalidRule($kind, sprintf('needs a length of 0 or more; got %d', $length));
        }

        return $length;
    }
}
