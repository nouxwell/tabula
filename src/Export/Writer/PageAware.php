<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Page;

/**
 * A writer that can accept page geometry AFTER THE FACT.
 *
 * The `Writer` interface knows nothing at all about paper, and it must stay that way: CSV and xlsx
 * have no concept of a page size, leaking `Page` into the `open()/startSheet()/writeRow()`
 * signatures would tie both writers to a concept they will never use, and on top of that it would
 * open the door to a silent lie such as "this writer ignores the page setting".
 *
 * That is why `ExportBuilder` pushes the per-export page setting to the writer through THIS
 * interface — without touching a writer that does not support it at all:
 *
 *     if ($writer instanceof PageAware) {
 *         $writer = $writer->withPage($page, $budget);
 *     }
 *
 * So the paper information reaches only the writer that actually uses it, not the `Writer`
 * contract.
 *
 * A null argument means "keep what I have"; that is what makes it possible to enlarge only the
 * page without touching the budget (or the other way round). `withPage(null, null)` is valid too
 * and changes nothing.
 *
 * The return type is `static` because the setting IS NOT APPLIED IN PLACE, a new instance is
 * returned: the same writer instance (e.g. one handed over by hand through
 * `ExportBuilder::writer()`) may be shared across several exports, and one export's A3 must not
 * bleed into another one's output.
 */
interface PageAware
{
    public function withPage(?Page $page, ?ColumnBudget $budget): static;
}
