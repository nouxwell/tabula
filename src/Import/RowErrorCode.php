<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Import;

/**
 * What kind of problem a `RowError` reports — the machine-readable half of the error.
 *
 * `RowError::$message` is a sentence in English, ready to show. An application whose users read
 * another language cannot show it, and before this code existed its only way out was to validate
 * every cell a second time in its own code — the duplication the schema is there to remove. With
 * the code and `RowError::$params` it words the message from its own catalogue instead:
 *
 *     $tabula->translator()->trans('import.error.'.$error->code->value, $error->params, $locale);
 *
 * The params are plain names (`value`, not `%value%`). The library's `Translator` port adds the
 * percent signs — `ArrayTranslator`, `PassthroughTranslator` and the Symfony bridge all do. Symfony's
 * own `TranslatorInterface` does not, and its third argument is the domain, not the locale.
 *
 * New codes may be added in a later release; existing values are never renamed. A `match` over
 * this enum should keep a `default` arm that falls back to `RowError::$message`.
 *
 * The codes name the FAILURE, not the field type: a money column and a quantity column that
 * cannot be read both report `NotANumber`, an enum and an options column both report
 * `NotAnOption`. The type is in `params['type']` for the application that wants to word them
 * apart.
 *
 * ⚠ Only a cell can fail with a code. A mistake in the schema or the set-up — no parser for a
 * type, an enum field pointing at a class that is not an enum — is not a row error: it stops the
 * run with an exception, so it never reaches this list.
 */
enum RowErrorCode: string
{
    /** A required field is empty. Params: `field`, `type`. */
    case Required = 'required';

    /** Not a number — number, quantity and money fields alike. Params: `field`, `type`, `value`. */
    case NotANumber = 'not_a_number';

    /**
     * A number, but not a whole one — or too large to be an integer at all.
     *
     * Params: `field`, `type`, `value`.
     */
    case NotAnInteger = 'not_an_integer';

    /** Not a date in the expected format. Params: `field`, `type`, `value`, `format`. */
    case NotADate = 'not_a_date';

    /** Not one of the accepted yes/no words. Params: `field`, `type`, `value`, `accepted`. */
    case NotABoolean = 'not_a_boolean';

    /**
     * Not one of the field's options — enum and options fields alike.
     *
     * Params: `field`, `type`, `value`, `options` (an empty string when the field has none).
     */
    case NotAnOption = 'not_an_option';
}
