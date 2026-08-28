<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Schema;

/**
 * Field types — a SINGLE vocabulary.
 *
 * The system this replaces carried two vocabularies that did not agree (the exporter's
 * `integer|float|numeric|string|date|list` against the schema side's `string|int|float|bool|date`),
 * and every value the two did not share silently fell back to text. Here one single enum holds
 * for export, for import and for template generation alike.
 */
enum FieldType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Money = 'money';
    case Quantity = 'quantity';
    case Bool = 'bool';
    case Date = 'date';
    case DateTime = 'datetime';
    case Enum = 'enum';

    /** A fixed set of options (becomes a drop-down list in the template). */
    case Options = 'options';

    /** Numeric types — they align right by default and are written to Excel as real numbers. */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Integer, self::Decimal, self::Money, self::Quantity => true,
            default => false,
        };
    }

    /** Types that carry a date. */
    public function isTemporal(): bool
    {
        return self::Date === $this || self::DateTime === $this;
    }

    /** Types that come from a set of options closed to the user (they get a validation list in the template). */
    public function isEnumerable(): bool
    {
        return self::Enum === $this || self::Options === $this || self::Bool === $this;
    }

    /** The default alignment for this type. */
    public function defaultAlign(): Align
    {
        return match (true) {
            $this->isNumeric() => Align::Right,
            self::Bool === $this, $this->isTemporal() => Align::Center,
            default => Align::Left,
        };
    }
}
