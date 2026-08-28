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
        /** Translation keys for boolean values — a SINGLE family (the system this replaces had three parallel families). */
        public string $boolTrueKey = 'tabula.bool.yes',
        public string $boolFalseKey = 'tabula.bool.no',
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
