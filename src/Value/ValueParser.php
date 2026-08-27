<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;

/**
 * Converts the raw value in a cell to the field's type — the reverse direction of `ValueFormatter`.
 *
 * Unlike the formatter it is NOT LENIENT, and it must not be: on export a broken cell can be
 * printed empty and skipped, but on import that same cell means writing wrong data into the
 * database. A value that cannot be parsed throws a `ParseException`; the import loop catches
 * it, turns it into a `RowError` carrying the row/field information, and the run carries on.
 */
interface ValueParser
{
    public function supports(FieldType $type): bool;

    /**
     * @param mixed $raw the cell exactly as it came from the reader (string, number, DateTime, null…)
     *
     * @throws ParseException if the value cannot be written to this field
     */
    public function parse(mixed $raw, Field $field, ParseContext $context): mixed;
}
