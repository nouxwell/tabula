<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Settings;

/**
 * The whole configuration of the library in one place.
 *
 * The Symfony bridge fills this in from `config/packages/tabula.yaml`;
 * without a framework it is constructed directly.
 */
final readonly class TabulaSettings
{
    public function __construct(
        public NumberSettings $numbers = new NumberSettings(),
        public DateSettings $dates = new DateSettings(),
        public string $defaultLocale = 'en',
        /**
         * What a boolean cell says — a SINGLE family (the system this replaces had three parallel ones).
         *
         * ★ The defaults are WORDS, not translation keys, and that is the whole point. A
         * translator hands back whatever it cannot translate, so a key with no catalogue entry
         * ends up written into the cell verbatim: the old defaults printed the literal text
         * "tabula.bool.yes" in every boolean column of every export made without a catalogue.
         * Silent nonsense, in a library whose reason for existing is to prevent exactly that —
         * and the round trip broke too, since `BoolParser` has never heard of that string.
         *
         * Plain words survive both routes. Untranslated they read as "Yes"/"No", and `BoolParser`
         * accepts them coming back. Give a key here to translate them, and it works as before:
         * anything the translator DOES recognise is still resolved.
         */
        public string $boolTrueKey = 'Yes',
        public string $boolFalseKey = 'No',
        /** The text written into an empty cell. */
        public string $emptyText = '',
        /**
         * The maximum number of rows written to a single sheet.
         *
         * The default `SingleSheet` strategy uses this as overflow protection: once a sheet is
         * full it carries on as `Name (2)`. The default value is Excel's real ceiling
         * (1,048,576 rows, header included), so it never kicks in on a normal export.
         * When a `SheetStrategy` is given explicitly this value is not used — the strategy applies its own rule.
         */
        public int $maxRowsPerSheet = 1_048_575,
    ) {
    }
}
