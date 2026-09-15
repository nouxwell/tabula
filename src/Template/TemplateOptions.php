<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Template;

use Nouxwell\Tabula\Export\Writer\XlsxOptions;

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
     * @param string      $exampleWord   The word that introduces a column's example in its input
     *                                   message ("Example: 120.01.001"). A plain word or a
     *                                   translation key.
     * @param string      $requiredWord  The second line of that message on a required column.
     *                                   A plain word or a translation key.
     * @param bool        $protectHeader Lock the key and label rows against editing in Excel.
     *                                   Everything below them stays open: typing, pasting,
     *                                   filling down, deleting and inserting rows, sorting and
     *                                   filtering through the header buttons, column widths.
     *                                   The sheet is protected WITHOUT a password, so this stops
     *                                   an accidental edit rather than a deliberate one — Review ›
     *                                   Unprotect Sheet is one click. Off by default, because
     *                                   protection also takes things away (formatting cells,
     *                                   adding or removing columns); see the README.
     *
     * Both are WORDS by default, not translation keys, for the reason given at
     * `TabulaSettings::$boolTrueKey`: a translator hands back what it cannot translate, so a key
     * with no catalogue entry would pop up in Excel as the literal key. Plain words read fine
     * untranslated; a key the catalogue does define is still resolved.
     *
     * ★ A WORD, not a pattern such as "Example: %example%". A `%name%` inside a string handed to
     * the Symfony container is a PARAMETER REFERENCE: with that default the bridge's container
     * refused to compile ("non-existent parameter example"), and a user writing such a pattern in
     * `tabula.yaml` would hit the same wall. The layout "word: value" is fixed instead.
     *
     * The later parameters come after `$xlsx`, each appended in its own release, so that a
     * positional call written against an earlier signature keeps working.
     */
    public function __construct(
        public bool $includeKeyRow = true,
        public bool $hideKeyRow = true,
        public int $sampleRows = 0,
        public XlsxOptions $xlsx = new XlsxOptions(),
        public string $exampleWord = 'Example',
        public string $requiredWord = 'Required',
        public bool $protectHeader = false,
    ) {
    }
}
