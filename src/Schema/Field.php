<?php

declare(strict_types=1);

namespace Balin\Tabula\Schema;

use Balin\Tabula\Format;
use Closure;

/**
 * The SINGLE definition of a column.
 *
 * One and the same object does four jobs: it translates the Excel header, decides the cell
 * format, states the column priority in PDF, and (from Phase 4 on) validates the value on import.
 *
 * The object cannot be mutated from the outside: the fluent methods return a copy.
 *
 *     Field::money('balance')
 *         ->label('export.customer.balance')
 *         ->currency(fn (array $row) => $row['currencyCode'])
 *         ->decimals(2);
 */
final class Field
{
    private string|Closure|null $label = null;

    private string|Closure|null $from = null;

    private ?int $decimals = null;

    private string|Closure|null $currency = null;

    /** @var class-string|null */
    private ?string $enumClass = null;

    /** @var array<int|string, string>|Closure|null */
    private array|Closure|null $options = null;

    /** null = automatic width */
    private ?int $width = null;

    private Align $align = Align::Auto;

    private bool $required = false;

    private Priority $priority = Priority::Normal;

    /** @var list<Format>|null null = visible in every format */
    private ?array $only = null;

    private ?string $pattern = null;

    private ?Closure $formatter = null;

    private function __construct(
        private readonly string $key,
        private readonly FieldType $type,
    ) {
    }

    // ---------------------------------------------------------------- constructors

    public static function string(string $key): self
    {
        return new self($key, FieldType::String);
    }

    public static function integer(string $key): self
    {
        return new self($key, FieldType::Integer);
    }

    public static function decimal(string $key): self
    {
        return new self($key, FieldType::Decimal);
    }

    public static function money(string $key): self
    {
        return new self($key, FieldType::Money);
    }

    public static function quantity(string $key): self
    {
        return new self($key, FieldType::Quantity);
    }

    public static function bool(string $key): self
    {
        return new self($key, FieldType::Bool);
    }

    public static function date(string $key): self
    {
        return new self($key, FieldType::Date);
    }

    public static function dateTime(string $key): self
    {
        return new self($key, FieldType::DateTime);
    }

    /**
     * @param class-string $enumClass a PHP enum class; its value is turned into a translation key automatically
     */
    public static function enum(string $key, string $enumClass): self
    {
        $field = new self($key, FieldType::Enum);
        $field->enumClass = $enumClass;

        return $field;
    }

    /**
     * @param array<int|string, string>|Closure $options a fixed set, or one resolved at runtime
     */
    public static function options(string $key, array|Closure $options): self
    {
        $field = new self($key, FieldType::Options);
        $field->options = $options;

        return $field;
    }

    // ---------------------------------------------------------------- fluent settings

    /** A translation key, plain text, or fn(string $locale): string */
    public function label(string|Closure $label): self
    {
        return $this->with(static function (self $f) use ($label): void {
            $f->label = $label;
        });
    }

    /** An array key, a dotted path (`address.city`), a DQL alias, or fn(mixed $row): mixed */
    public function from(string|Closure $from): self
    {
        return $this->with(static function (self $f) use ($from): void {
            $f->from = $from;
        });
    }

    public function decimals(int $decimals): self
    {
        return $this->with(static function (self $f) use ($decimals): void {
            $f->decimals = $decimals;
        });
    }

    /** A fixed currency code, or fn(mixed $row): string */
    public function currency(string|Closure $currency): self
    {
        return $this->with(static function (self $f) use ($currency): void {
            $f->currency = $currency;
        });
    }

    public function width(?int $width): self
    {
        return $this->with(static function (self $f) use ($width): void {
            $f->width = $width;
        });
    }

    public function align(Align $align): self
    {
        return $this->with(static function (self $f) use ($align): void {
            $f->align = $align;
        });
    }

    public function required(bool $required = true): self
    {
        return $this->with(static function (self $f) use ($required): void {
            $f->required = $required;
        });
    }

    public function priority(Priority $priority): self
    {
        return $this->with(static function (self $f) use ($priority): void {
            $f->priority = $priority;
        });
    }

    /** Show the field only in the given formats. */
    public function only(Format ...$formats): self
    {
        return $this->with(static function (self $f) use ($formats): void {
            $f->only = array_values($formats);
        });
    }

    /** Date pattern (e.g. `d.m.Y`). */
    public function pattern(string $pattern): self
    {
        return $this->with(static function (self $f) use ($pattern): void {
            $f->pattern = $pattern;
        });
    }

    /** Take formatting over entirely: fn(mixed $raw, mixed $row): string */
    public function format(Closure $formatter): self
    {
        return $this->with(static function (self $f) use ($formatter): void {
            $f->formatter = $formatter;
        });
    }

    // ---------------------------------------------------------------- getters

    public function getKey(): string
    {
        return $this->key;
    }

    public function getType(): FieldType
    {
        return $this->type;
    }

    public function getLabel(): string|Closure|null
    {
        return $this->label;
    }

    public function getFrom(): string|Closure|null
    {
        return $this->from;
    }

    /** Where the value is read from — the key of the field itself when nothing was given explicitly. */
    public function getSource(): string|Closure
    {
        return $this->from ?? $this->key;
    }

    public function getDecimals(): ?int
    {
        return $this->decimals;
    }

    public function getCurrency(): string|Closure|null
    {
        return $this->currency;
    }

    /** @return class-string|null */
    public function getEnumClass(): ?string
    {
        return $this->enumClass;
    }

    /** @return array<int|string, string>|Closure|null */
    public function getOptions(): array|Closure|null
    {
        return $this->options;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    /** The final alignment, derived from the type. */
    public function getAlign(): Align
    {
        return $this->align->resolve($this->type);
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getPriority(): Priority
    {
        return $this->priority;
    }

    public function getPattern(): ?string
    {
        return $this->pattern;
    }

    public function getFormatter(): ?Closure
    {
        return $this->formatter;
    }

    /** @return list<Format>|null */
    public function getOnly(): ?array
    {
        return $this->only;
    }

    public function appliesTo(Format $format): bool
    {
        return null === $this->only || in_array($format, $this->only, true);
    }

    // ---------------------------------------------------------------- internals

    private function with(Closure $mutator): self
    {
        $clone = clone $this;
        $mutator($clone);

        return $clone;
    }
}
