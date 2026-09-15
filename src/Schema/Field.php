<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Schema;

use Closure;
use Nouxwell\Tabula\Format;

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

    /** null = no example; see example() */
    private mixed $example = null;

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

    /** A fixed currency code, fn(mixed $row): string, or null to remove one set earlier */
    public function currency(string|Closure|null $currency): self
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

    /** Take formatting over entirely: fn(mixed $raw, mixed $row): string — or null to remove one set earlier */
    public function format(?Closure $formatter): self
    {
        return $this->with(static function (self $f) use ($formatter): void {
            $f->formatter = $formatter;
        });
    }

    /**
     * A sample value, shown in the template as the input message Excel pops up when a cell of
     * the column is selected.
     *
     * Give it a value OF THE FIELD'S OWN TYPE and it is formatted the way the export formats that
     * type: `1250.5` on a decimal column reads "1.250,50" under Turkish number settings, a
     * `DateTimeImmutable` follows the date pattern, `true` on a bool column is the translated
     * yes-word, an options column takes the option KEY. That is the point of passing a value
     * rather than finished text — the example follows the same settings as the file.
     *
     * A template has no data row. On text, number, money and date columns the example therefore
     * leaves out what needs one: a `format()` closure is not applied, and money carries no
     * currency symbol — just like the column's own cell format. On a bool, enum or options
     * column it goes through the same call as the dropdown list, `format()` closure included and
     * called with a null row, so it reads exactly like its entry in the list. Templates use the
     * built-in formatters; a custom `FormatterRegistry` reaches the export only.
     *
     * On a text column a string is resolved like a label: a translation key, or plain text that
     * passes through untouched. `fn(string $locale): string` is used verbatim.
     *
     * The example is never written into a cell. A sample row in the data area gets imported as
     * a real record the moment a user forgets to delete it; a message cannot.
     */
    public function example(mixed $example): self
    {
        return $this->with(static function (self $f) use ($example): void {
            $f->example = $example;
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

    public function getExample(): mixed
    {
        return $this->example;
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
