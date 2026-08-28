<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value;

use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;

/**
 * The unit that turns a raw value into a cell.
 *
 * There is one implementation per type; the registry matches them through `supports()`.
 * Adding a new type = writing a new formatter (in the code this replaces there were
 * `normalize()` methods copied into 8 separate places).
 */
interface ValueFormatter
{
    public function supports(FieldType $type): bool;

    /**
     * @param mixed $raw the raw value read from the row
     * @param mixed $row the field's context (for settings derived from the row, such as the currency)
     */
    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell;
}
