<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use ArrayAccess;
use Balin\Tabula\Schema\Field;
use Closure;

/**
 * Bir satırdan alanın ham değerini okur.
 *
 * Desteklenen kaynak biçimleri (`Field::from()`):
 *  - Kapanış:      fn(mixed $row): mixed
 *  - Düz anahtar:  `code`            → dizi anahtarı, nesne özelliği ya da getCode()
 *  - Nokta yolu:   `address.city`    → iç içe dizi/nesne
 *  - DQL takma adı: `c.code`         → önce düz anahtar olarak denenir (projeksiyonlarda
 *                                      Doctrine `c.code` yerine `code` döndürür), bulunamazsa yol olarak
 *
 * Yol boyunca `null` görülürse `null` döner — eksik ilişki hata değildir, boş hücredir.
 */
final class ValueResolver
{
    public function resolve(Field $field, mixed $row): mixed
    {
        $source = $field->getSource();

        if ($source instanceof Closure) {
            return $source($row);
        }

        // Doğrudan eşleşme: en sık durum, yol yürümeden bitir.
        $direct = $this->readSegment($row, $source);
        if (null !== $direct) {
            return $direct;
        }

        if (!str_contains($source, '.')) {
            return null;
        }

        // Nokta yolu ya da DQL takma adı.
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
