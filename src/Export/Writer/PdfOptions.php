<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Page;

/**
 * The options of the PDF writer.
 *
 * Unlike Xlsx and CSV, PDF has a PHYSICAL limit: the width of the paper. That is why the two main
 * settings here are not colour or decoration but geometry — the page (`Page`) and the column budget
 * (`ColumnBudget`). The remaining three settings are about readability just as much.
 *
 * The default is A4 LANDSCAPE. The system this replaces printed every list on A4 portrait; a
 * ten-column invoice list did not fit on the page, so the last columns ended up off the paper and
 * the user only noticed they were missing once they compared the result with the Excel output.
 * Landscape paper raises the usable width from 190 mm to 277 mm: 12 columns instead of 8 on the
 * same budget.
 */
final readonly class PdfOptions
{
    /**
     * The only family that SHIPS WITH Dompdf and covers Latin Extended-A.
     *
     * This is NOT a matter of taste. Dompdf's built-in "core" PDF fonts (Helvetica, Times, Courier)
     * are bound to the WinAnsi/Latin-1 encoding, and the letters "ş ğ ı İ" DO NOT EXIST in that
     * set — the character is printed either as a box or not at all. The package contains no other
     * family carrying those letters either; without installing a new TTF onto the system, the
     * DejaVu family is the only way to print Turkish. Whoever changes the family has to install
     * their chosen font into Dompdf separately (and verify that it covers Latin Extended-A);
     * otherwise the output is silently printed with letters missing.
     */
    private const string BUNDLED_UNICODE_FONT = 'DejaVu Sans';

    /** Paper geometry — dimensions, orientation and margins. */
    public Page $page;

    /** How the columns are fitted onto the page; the overflow strategy lives here too. */
    public ColumnBudget $budget;

    /**
     * @param string  $fontFamily   Must be a family that covers Latin Extended-A (see the note above)
     * @param float   $fontSizePt   8 pt by default: at the 22 mm minimum column width this is the
     *                              largest size at which a cell of two or three words still stays on
     *                              a single line. At 10 pt the same cell wraps onto three lines and
     *                              the row height doubles — so does the page count.
     * @param bool    $repeatHeader Whether the header row is repeated on EVERY page. If it is turned
     *                              off the header stays on the first page only; on long lists there
     *                              is no telling which column is which from the second page onwards.
     * @param ?string $title        The document title: written both into the PDF's heading (`<h1>`)
     *                              and into the "Title" field of the PDF file properties. When null
     *                              no title is printed and the sheet name takes over that job.
     */
    public function __construct(
        ?Page $page = null,
        ?ColumnBudget $budget = null,
        public string $fontFamily = self::BUNDLED_UNICODE_FONT,
        public float $fontSizePt = 8.0,
        public bool $repeatHeader = true,
        public ?string $title = null,
    ) {
        // Validate at CONSTRUCTION time. Dompdf DOES NOT THROW when it is given a zero or negative
        // font size: it computes the row height as zero and the table shrinks into an invisible
        // strip. To the user this comes back as an "empty PDF" whose cause cannot be worked out by
        // looking at the output.
        if ($fontSizePt <= 0) {
            throw WriterException::invalidFontSize($fontSizePt);
        }

        // The defaults are set up here, NOT in the signature: in PHP a parameter default has to be a
        // constant expression, and `Page::a4()->landscape()` is a static call. `Page`'s constructor
        // is private too (so that the fluent API has a single door), which means `new Page(...)`
        // cannot be written either. That is why null means "use the default".
        $this->page = $page ?? Page::a4()->landscape();
        $this->budget = $budget ?? ColumnBudget::fit();
    }
}
