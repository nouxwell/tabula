<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Grid;

/**
 * What a `GridIssue` reports: the codes the browser's own checks report, so the grid words an
 * issue from the backend as it words its own.
 *
 * The first six are `RowErrorCode`'s, with its params. New codes may be added in a later release;
 * existing values are never renamed.
 */
enum GridIssueCode: string
{
    /** Params: `field`, `type`. */
    case Required = 'required';

    /** Params: `field`, `type`, `value`. */
    case NotANumber = 'not_a_number';

    /** Params: `field`, `type`, `value`. */
    case NotAnInteger = 'not_an_integer';

    /** Params: `field`, `type`, `value`, `format`. */
    case NotADate = 'not_a_date';

    /** Params: `field`, `type`, `value`, `accepted`. */
    case NotABoolean = 'not_a_boolean';

    /** Params: `field`, `type`, `value`, `options`. */
    case NotAnOption = 'not_an_option';

    /** A lookup holding a name rather than a record: no id to write. Params: `field`, `type`, `value`. */
    case NotMatched = 'not_matched';

    /** Params: `field`, `type`, `value`, `min`. */
    case MinValue = 'min_value';

    /** Params: `field`, `type`, `value`, `max`. */
    case MaxValue = 'max_value';

    /** Params: `field`, `type`, `value`, `min`, `length`. */
    case MinLength = 'min_length';

    /** Params: `field`, `type`, `value`, `max`, `length`. */
    case MaxLength = 'max_length';

    /** The same value in more than one row. Params: `field`, `value`, `count`. */
    case NotUnique = 'not_unique';
}
