<?php

declare(strict_types=1);

namespace Balin\Tabula\Template;

use Balin\Tabula\Export\Writer\XlsxOptions;

/**
 * The generation settings of an import template.
 *
 * The visual settings (header colour, required-column fill, freezing, filtering) come from
 * `XlsxOptions` DELIBERATELY: the template must be a mirror of the exported file. Had the two
 * been produced from separate settings, the user would ask "why does the file I downloaded
 * look different from the template I fill in" — and the one learned visual cue, the red header
 * of the required columns, would have needed maintaining in two places.
 */
final readonly class TemplateOptions
{
    /**
     * @param bool        $includeKeyRow Whether the canonical key row (row 1) is written.
     *                                   Turning it off condemns the file to matching BY LABEL,
     *                                   meaning a change in the translation breaks the
     *                                   template — that was exactly the fatal flaw of the
     *                                   system this replaces. Turn it off only when handing
     *                                   the template to another system as a feed.
     * @param bool        $hideKeyRow    Whether the key row is hidden in Excel. A hidden row
     *                                   REMAINS in the file; the user does not see the
     *                                   technical keys, the import still does.
     * @param int         $sampleRows    How many pre-formatted empty rows are created beneath
     *                                   the header. 0 (the default) = none; values below zero
     *                                   count as "none" too.
     * @param XlsxOptions $xlsx          Header appearance — the settings object SHARED with export
     */
    public function __construct(
        public bool $includeKeyRow = true,
        public bool $hideKeyRow = true,
        public int $sampleRows = 0,
        public XlsxOptions $xlsx = new XlsxOptions(),
    ) {
    }
}
