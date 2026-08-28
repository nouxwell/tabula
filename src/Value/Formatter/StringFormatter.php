<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value\Formatter;

use BackedEnum;
use DateTimeInterface;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Value\Cell;
use Nouxwell\Tabula\Value\FormatContext;
use Nouxwell\Tabula\Value\ValueFormatter;
use Stringable;
use UnitEnum;

/**
 * Text fields.
 *
 * It looks like "just cast it to a string", but this is exactly where the system this
 * replaces used to break: Doctrine columns declared with `enumType` return an ENUM
 * INSTANCE even in scalar DQL projections; the `(string)` cast blew up with "Object of class
 * ... could not be converted to string", and in the try/catch-wrapped variants the cell was
 * silently left empty. That is why enum instances are handled EXPLICITLY here (even for a
 * text field).
 */
final class StringFormatter implements ValueFormatter
{
    public function supports(FieldType $type): bool
    {
        return FieldType::String === $type;
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        // If the field brought its own formatter there is nothing to argue about: it wins.
        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        if (null === $raw) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        return Cell::text($this->stringify($raw, $field, $context), $align);
    }

    private function stringify(mixed $raw, Field $field, FormatContext $context): string
    {
        if (is_string($raw)) {
            return $raw;
        }

        // Doctrine enumType columns: a scalar select yields an enum instance, not a string.
        if ($raw instanceof BackedEnum) {
            return (string) $raw->value;
        }

        if ($raw instanceof UnitEnum) {
            return $raw->name;
        }

        if (is_bool($raw)) {
            // `(string) false` produces an empty string; in a text column that means
            // silently swallowing data (the classic "empty cell" complaint about the
            // legacy export).
            $settings = $context->settings;

            return $context->trans($raw ? $settings->boolTrueKey : $settings->boolFalseKey);
        }

        if (is_int($raw) || is_float($raw)) {
            return (string) $raw;
        }

        if (is_array($raw)) {
            // Keep Turkish characters and paths readable: no `ç` or `\/` escaping.
            $json = json_encode($raw, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

            return false === $json ? '' : $json;
        }

        if (is_object($raw) && ($raw instanceof Stringable || method_exists($raw, '__toString'))) {
            return (string) $raw;
        }

        // A date landing in a text field is a schema mistake, but the value can still be
        // salvaged; it is written in a neutral ISO-like form (guessing a pattern is not this
        // class's job).
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d H:i:s');
        }

        // For a value that lands here we produce no silent assumption — but we DO NOT THROW
        // EITHER: a 40,000-row report must not die because of a single broken cell. Every
        // other formatter (Number/Date/Enum) follows the same rule; this class used to be
        // the one exception. A schema mistake shows up as an empty cell, not as a crashed
        // export.
        return '';
    }
}
