<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Grid;

use BackedEnum;
use Closure;
use Nouxwell\Tabula\Exception\GridException;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use UnitEnum;

/**
 * Checks the rows a grid sends against the schema and its rules, as the browser checked them, and
 * says what is wrong in the grid's own terms: a `GridIssue` per cell, bound to the row's id.
 *
 * ★ THE VALUES ARE THE GRID'S, NOT A USER'S TEXT. A grid sends a number as decimal text with a
 * point ("1234.5"), a date as ISO text ("2026-12-31", "2026-12-31T09:30:00"), yes and no as true
 * and false, an option as its value and a lookup as its record's id. They are read as exactly that,
 * never through the import's parsers: those read what a person typed, in the person's language,
 * and with Turkish settings "1234.5" would be twelve thousand.
 *
 * Passing here is passing the schema's rules, nothing more: whether a product exists, is active,
 * or may be priced by this user is the application's to check, after this.
 */
final class RowChecker
{
    /** How many other rows a "same value" issue points at; its `count` param says how many in all. */
    private const MAX_TARGETS = 10;

    /** JavaScript's Number.MAX_SAFE_INTEGER: past it, a number the grid sent is no longer exact. */
    private const LARGEST_SAFE_FLOAT = 9007199254740991.0;

    private const ISO_DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';
    private const ISO_DATE_TIME = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})$/';

    /**
     * @param iterable<int|string, mixed> $rows each `{rowId, values}`, as the grid sends them
     *
     * @return list<GridIssue> by row in the order sent, then by field in the schema's order
     *
     * @throws GridException when a row is not in the grid's shape: the request as a whole is wrong
     */
    public function check(GridSchema $grid, iterable $rows): array
    {
        $fields = array_filter(
            $grid->getSchema()->getFields(),
            static fn (Field $field): bool => $grid->isSubmitted($field->getKey()),
        );

        /** @var array<string, array<string, list<GridIssue>>> $found row id => field key => issues */
        $found = [];
        /** @var array<string, array<string, list<string>>> $groups field key => value key => row ids */
        $groups = [];
        /** @var array<string, array<string, string>> $shown field key => value key => the value as shown */
        $shown = [];

        foreach ($rows as $index => $row) {
            [$rowId, $values] = self::shape($index, $row, $found);
            $found[$rowId] = [];

            foreach ($fields as $key => $field) {
                $value = $values[$key] ?? null;
                $issues = $this->checkValue($grid, $field, $rowId, $value);

                if ([] !== $issues) {
                    $found[$rowId][$key] = $issues;
                }

                // Only a value with nothing else wrong is compared, as the browser compares them: a
                // price below its minimum is that, not also a second copy of another row's price.
                if ([] === $issues && null !== $value && '' !== $value && self::isUnique($grid, $key)) {
                    $valueKey = self::uniqueKey($value, null !== $grid->lookupOf($key));
                    $groups[$key][$valueKey][] = $rowId;
                    $shown[$key][$valueKey] = self::describe($value);
                }
            }
        }

        foreach ($groups as $key => $byValue) {
            foreach ($byValue as $valueKey => $rowIds) {
                if (\count($rowIds) < 2) {
                    continue;
                }

                $value = $shown[$key][$valueKey];

                foreach ($rowIds as $rowId) {
                    $others = \array_slice(array_values(array_filter($rowIds, static fn (string $id): bool => $id !== $rowId)), 0, self::MAX_TARGETS);
                    $found[$rowId][$key][] = new GridIssue(
                        $rowId,
                        $key,
                        GridIssueCode::NotUnique,
                        sprintf('"%s" appears %d times in this column; it may appear once.', $value, \count($rowIds)),
                        ['field' => $key, 'value' => $value, 'count' => (string) \count($rowIds)],
                        array_map(static fn (string $id): array => ['rowId' => $id, 'field' => $key], $others),
                    );
                }
            }
        }

        // In the order the rows came, and each row's cells in the schema's order.
        $issues = [];

        foreach ($found as $byField) {
            foreach (array_keys($fields) as $key) {
                foreach ($byField[$key] ?? [] as $issue) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /** @return list<GridIssue> */
    private function checkValue(GridSchema $grid, Field $field, string $rowId, mixed $value): array
    {
        $key = $field->getKey();
        $type = self::typeOf($grid, $field);

        if (null === $value || '' === $value) {
            return $field->isRequired()
                ? [new GridIssue($rowId, $key, GridIssueCode::Required, 'This field is required and cannot be left empty.', ['field' => $key, 'type' => $type])]
                : [];
        }

        // A value not of its type is only that: a minimum means nothing for "abc".
        $problem = $this->typeProblem($grid, $field, $value);

        if (null !== $problem) {
            [$code, $message, $extra] = $problem;

            return [new GridIssue($rowId, $key, $code, $message, ['field' => $key, 'type' => $type, 'value' => self::describe($value), ...$extra])];
        }

        $issues = [];

        foreach ($grid->rulesFor($key) as $rule) {
            $issue = $this->checkRule($rule, $rowId, $key, $type, $value);

            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @return array{GridIssueCode, string, array<string, string>}|null
     */
    private function typeProblem(GridSchema $grid, Field $field, mixed $value): ?array
    {
        $text = self::describe($value);

        if (null !== $grid->lookupOf($field->getKey())) {
            return \is_int($value) || (\is_string($value) && '' !== trim($value))
                ? null
                : [GridIssueCode::NotMatched, sprintf('"%s" is not matched to a record; pick one from the list.', $text), []];
        }

        switch ($field->getType()) {
            case FieldType::Integer:
                if (self::isInteger($value)) {
                    return null;
                }

                return (\is_string($value) || \is_float($value)) && null !== Decimal::normalize($value)
                    ? [GridIssueCode::NotAnInteger, sprintf('"%s" is not a whole number.', $text), []]
                    : [GridIssueCode::NotANumber, sprintf('"%s" could not be read as a number.', $text), []];
            case FieldType::Decimal:
            case FieldType::Money:
            case FieldType::Quantity:
                return (\is_int($value) || \is_float($value) || \is_string($value)) && null !== Decimal::normalize($value)
                    ? null
                    : [GridIssueCode::NotANumber, sprintf('"%s" could not be read as a number.', $text), []];
            case FieldType::Bool:
                return \is_bool($value)
                    ? null
                    : [GridIssueCode::NotABoolean, sprintf('"%s" is not a Yes/No value. Accepted: true, false', $text), ['accepted' => 'true, false']];
            case FieldType::Date:
            case FieldType::DateTime:
                $withTime = FieldType::DateTime === $field->getType();
                $format = $withTime ? 'Y-m-d\TH:i:s' : 'Y-m-d';

                return \is_string($value) && self::isIsoDate($value, $withTime)
                    ? null
                    : [GridIssueCode::NotADate, sprintf('"%s" could not be read as a date; expected format: %s', $text, $format), ['format' => $format]];
            case FieldType::Enum:
            case FieldType::Options:
                $allowed = array_map(strval(...), self::optionValues($field));

                if ((\is_int($value) || \is_string($value)) && \in_array((string) $value, $allowed, true)) {
                    return null;
                }

                $options = implode(', ', $allowed);

                return [
                    GridIssueCode::NotAnOption,
                    sprintf('"%s" is not in the list. Options: %s', $text, '' === $options ? '(no options defined)' : $options),
                    ['options' => $options],
                ];
            case FieldType::String:
                return null;
        }
    }

    private function checkRule(Rule $rule, string $rowId, string $key, string $type, mixed $value): ?GridIssue
    {
        if ($rule->isNumeric()) {
            $number = Decimal::normalize(\is_bool($value) || !\is_scalar($value) ? '' : $value);

            if (null === $number) {
                return null;
            }

            $limit = (string) $rule->value;
            $order = Decimal::compare($number, $limit);

            if (Rule::MIN === $rule->kind && $order < 0) {
                return new GridIssue($rowId, $key, GridIssueCode::MinValue, sprintf('%s is below the minimum of %s.', $number, $limit), ['field' => $key, 'type' => $type, 'value' => $number, 'min' => $limit]);
            }

            if (Rule::MAX === $rule->kind && $order > 0) {
                return new GridIssue($rowId, $key, GridIssueCode::MaxValue, sprintf('%s is above the maximum of %s.', $number, $limit), ['field' => $key, 'type' => $type, 'value' => $number, 'max' => $limit]);
            }

            return null;
        }

        if ($rule->isLength() && \is_string($value)) {
            // Characters a reader sees, not bytes: "📦" is one.
            $length = mb_strlen($value);
            $limit = (int) $rule->value;

            if (Rule::MIN_LENGTH === $rule->kind && $length < $limit) {
                return new GridIssue($rowId, $key, GridIssueCode::MinLength, sprintf('"%s" is shorter than %d characters.', $value, $limit), ['field' => $key, 'type' => $type, 'value' => $value, 'min' => (string) $limit, 'length' => (string) $length]);
            }

            if (Rule::MAX_LENGTH === $rule->kind && $length > $limit) {
                return new GridIssue($rowId, $key, GridIssueCode::MaxLength, sprintf('"%s" is longer than %d characters.', $value, $limit), ['field' => $key, 'type' => $type, 'value' => $value, 'max' => (string) $limit, 'length' => (string) $length]);
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $seen
     *
     * @return array{string, array<array-key, mixed>}
     */
    private static function shape(int|string $index, mixed $row, array $seen): array
    {
        if (!\is_array($row)) {
            throw GridException::notARow($index);
        }

        $rowId = $row['rowId'] ?? null;

        if (!\is_string($rowId) || '' === $rowId) {
            throw GridException::noRowId($index);
        }

        if (\array_key_exists($rowId, $seen)) {
            throw GridException::duplicateRowId($rowId);
        }

        $values = $row['values'] ?? [];

        if (!\is_array($values)) {
            throw GridException::notARow($index);
        }

        return [$rowId, $values];
    }

    /** The type as the grid names it: an enum is an options field there, and a lookup is its own. */
    private static function typeOf(GridSchema $grid, Field $field): string
    {
        if (null !== $grid->lookupOf($field->getKey())) {
            return 'lookup';
        }

        return match ($field->getType()) {
            FieldType::Enum, FieldType::Options => 'options',
            default => $field->getType()->value,
        };
    }

    /**
     * A whole number, within what the browser takes for one: a float only while JavaScript holds it
     * exactly, and digits only while PHP can, as an integer column's value must fit an int here.
     */
    private static function isInteger(mixed $value): bool
    {
        if (\is_int($value)) {
            return true;
        }

        if (\is_float($value)) {
            return floor($value) === $value && abs($value) <= self::LARGEST_SAFE_FLOAT;
        }

        if (!\is_string($value) || 1 !== preg_match('/^[+-]?(\d+)$/', $value, $match)) {
            return false;
        }

        return Decimal::compare((string) Decimal::normalize($match[1]), (string) \PHP_INT_MAX) <= 0;
    }

    private static function isUnique(GridSchema $grid, string $key): bool
    {
        foreach ($grid->rulesFor($key) as $rule) {
            if (Rule::UNIQUE === $rule->kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * What makes two values the same, as the browser tells them: a record by its id, and the id's
     * kind with it, so 7 and "7" are two records; a number however it is written, "12.50" and 12.5
     * alike; anything else as it is.
     */
    private static function uniqueKey(mixed $value, bool $lookup): string
    {
        if ($lookup) {
            return 'id:'.(\is_int($value) ? 'number' : 'string').':'.self::describe($value);
        }

        $number = \is_int($value) || \is_float($value) || \is_string($value) ? Decimal::normalize($value) : null;

        return null !== $number ? 'number:'.$number : 'value:'.json_encode($value);
    }

    /** A value as text, for a message and its params. */
    private static function describe(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value), \is_float($value) => Decimal::normalize($value) ?? (string) $value,
            \is_string($value) => $value,
            \is_array($value) && isset($value['label']) && \is_string($value['label']) => $value['label'],
            default => (string) json_encode($value),
        };
    }

    /** A real day, and for a date-time a real time of it: "2026-02-30" and "24:00:00" are neither. */
    private static function isIsoDate(string $value, bool $withTime): bool
    {
        if (!$withTime) {
            return 1 === preg_match(self::ISO_DATE, $value, $parts)
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
        }

        return 1 === preg_match(self::ISO_DATE_TIME, $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && (int) $parts[4] < 24
            && (int) $parts[5] < 60
            && (int) $parts[6] < 60;
    }

    /**
     * The values an options or enum field takes: an option's key, an enum case's value, or its name
     * when the enum has no values. An options closure is called with a null row, as the import calls
     * it: a list that depends on a row has nothing to offer here.
     *
     * @return list<int|string>
     */
    private static function optionValues(Field $field): array
    {
        if (FieldType::Enum === $field->getType()) {
            $class = $field->getEnumClass();

            if (null === $class || !enum_exists($class)) {
                return [];
            }

            return array_map(
                static fn (UnitEnum $case): int|string => $case instanceof BackedEnum ? $case->value : $case->name,
                $class::cases(),
            );
        }

        $options = $field->getOptions();

        if ($options instanceof Closure) {
            $options = $options(null);
        }

        return \is_array($options) ? array_keys($options) : [];
    }
}
