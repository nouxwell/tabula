<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use ArrayAccess;
use Balin\Tabula\Schema\Field;
use Closure;

/**
 * Reads a field's raw value out of a row.
 *
 * Supported source shapes (`Field::from()`):
 *  - Closure:      fn(mixed $row): mixed
 *  - Plain key:    `code`            → array key, object property or getCode()
 *  - Dotted path:  `address.city`    → nested array/object
 *  - DQL alias:    `c.code`          → tried as a plain key first (in projections Doctrine
 *                                      returns `code` instead of `c.code`), then as a path
 *
 * If a `null` turns up along the path, `null` is returned — a missing relation is not an
 * error, it is an empty cell.
 */
final class ValueResolver
{
    public function resolve(Field $field, mixed $row): mixed
    {
        $source = $field->getSource();

        if ($source instanceof Closure) {
            return $source($row);
        }

        // Direct hit: the most common case, done without walking a path.
        $direct = $this->readSegment($row, $source);
        if (null !== $direct) {
            return $direct;
        }

        if (!str_contains($source, '.')) {
            return null;
        }

        // A dotted path or a DQL alias.
        $node = $row;
        foreach (explode('.', $source) as $segment) {
            if (null === $node) {
                return null;
            }

            $node = $this->readSegment($node, $segment);
        }

        return $node;
    }

    private function readSegment(mixed $node, string $segment): mixed
    {
        if (is_array($node)) {
            return $node[$segment] ?? null;
        }

        if (!is_object($node)) {
            return null;
        }

        if ($node instanceof ArrayAccess && $node->offsetExists($segment)) {
            return $node->offsetGet($segment);
        }

        foreach ($this->accessorNames($segment) as $method) {
            if (method_exists($node, $method) && is_callable([$node, $method])) {
                return $node->{$method}();
            }
        }

        if (property_exists($node, $segment) && isset($node->{$segment})) {
            return $node->{$segment};
        }

        return null;
    }

    /** @return list<string> */
    private function accessorNames(string $segment): array
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $segment)));

        return ['get'.$studly, 'is'.$studly, $segment];
    }
}
