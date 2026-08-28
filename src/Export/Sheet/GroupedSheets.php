<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Sheet;

use BackedEnum;
use Closure;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Value\FormatContext;
use Nouxwell\Tabula\Value\ValueResolver;
use UnitEnum;

/**
 * A sheet per field value — every warehouse/category in its own tab.
 *
 * CAUTION: the strategy does not reorder rows. For a group to end up on a single sheet, the data
 * source must already arrive SORTED by that field; otherwise the same name is opened more than
 * once.
 *
 * Reading the value is delegated to `ValueResolver` — a second path walk is NOT WRITTEN here.
 * (A hand-written copy missed the resolver's "try the flat key first" step and therefore fell
 * silently back to the fallback name on Doctrine projection rows such as `['c.code' => 'X']`.)
 */
final readonly class GroupedSheets implements SheetStrategy
{
    private ValueResolver $resolver;

    /** The carrier field that hands path-based reading over to the resolver; null if a closure was given. */
    private ?Field $field;

    /**
     * @param string|Closure(mixed): mixed $by field key / dotted path, or a closure that produces a value from the row
     */
    public function __construct(
        private string|Closure $by,
        private string $fallbackName = 'Other',
    ) {
        $this->resolver = new ValueResolver();
        $this->field = $by instanceof Closure ? null : Field::string('__sheet')->from($by);
    }

    public function sheetFor(int $rowIndex, mixed $row, FormatContext $context): string
    {
        if (null === $this->field) {
            /** @var Closure $by no carrier field is set up when a closure is given */
            $by = $this->by;

            return $this->toSheetName($by($row));
        }

        return $this->toSheetName($this->resolver->resolve($this->field, $row));
    }

    private function toSheetName(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof UnitEnum) {
            $value = $value->name;
        }

        // `false` IS A SPECIAL CASE: `(string) false` is the empty string, and an empty name falls
        // back to the fallback name. The "no" group and the "has no value" group would then be
        // merged onto the SAME sheet — data loss.
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (null === $value || !is_scalar($value)) {
            return $this->fallbackName;
        }

        $name = (string) $value;

        return '' === trim($name) ? $this->fallbackName : $name;
    }
}
