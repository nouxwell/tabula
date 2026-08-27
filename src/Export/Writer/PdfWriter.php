<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Value\Cell;
use Dompdf\Dompdf;
use Dompdf\Exception as DompdfException;
use Dompdf\Options;

/**
 * A PDF writer built on Dompdf.
 *
 * In PDF two things are fundamentally different from xlsx and CSV:
 *
 *  1. THE PAPER HAS A WIDTH. If the columns do not fit they have to be cut somewhere; that decision
 *     is made by `ColumnBudget::split()` and is called here EXACTLY ONCE, inside `startSheet()`.
 *     Every returned group is one "page set": first all the rows are printed with the columns of
 *     group 1, then the same rows are printed again from the start with the columns of group 2.
 *  2. THE OUTPUT IS PRODUCED AT THE VERY LAST MOMENT. Dompdf takes the HTML as a whole; it cannot be
 *     streamed row by row.
 *
 * Because of (2) the rows have to be accumulated. What accumulates is NOT a `Cell` OBJECT but a
 * ready-made `<tr>` HTML STRING: the same O(rows) growth, but only the text itself instead of
 * ~200 bytes per object. More importantly, this is what allows the source to be read in A SINGLE
 * PASS. Re-querying the source per group — which is exactly what a "second pass" means — would
 * produce a PDF in which group 1 contained different rows from group 2 because the data changed
 * between the two queries, i.e. a PDF that CONTRADICTS ITSELF. That is exactly what the many-column
 * reports of the system this replaces did, and it was the source of the "the PDF shows a different
 * amount" complaint.
 *
 * The lifecycle and the behaviour on violations are identical to `CsvWriter`/`XlsxWriter`.
 */
final class PdfWriter implements PageAware, Writer
{
    /** The target given to `open()`; a PDF is always a SINGLE file. */
    private ?string $path = null;

    private bool $opened = false;

    /** @var list<string> the file paths written */
    private array $paths = [];

    /** The HTML body of the finished sheets — the document is built from this in `close()`. */
    private string $body = '';

    /**
     * How many sections have been appended to the body so far.
     *
     * All but the first start on a new page; the counter is kept for the WHOLE document, not per
     * sheet — that way the first group of the second sheet also lands on fresh paper.
     */
    private int $sectionCount = 0;

    /**
     * Is there an active sheet?
     *
     * The group count cannot be used for this: on a column-less sheet `split()` returns an empty
     * list, but the sheet is still OPEN and `writeRow()` must not throw `noActiveSheet`.
     */
    private bool $inSheet = false;

    private string $sheetName = '';

    /**
     * For each group, the positions of that group's columns in the ORIGINAL column list.
     *
     * The matching is done by key (`Column::$key` is preserved when a column is copied into a
     * group) and is set up once in `startSheet()`; no key lookup happens inside `writeRow()`.
     *
     * @var list<list<int>>
     */
    private array $positions = [];

    /**
     * For each group, the ready-made `<td …>` opening tags (with the alignment class embedded).
     *
     * They are computed up front so that they are not regenerated per row: fifty thousand rows × ten
     * columns = half a million `match` calls, every one of them returning the same answer.
     *
     * @var list<list<string>>
     */
    private array $cellTags = [];

    /**
     * For each group, the ready-made `<colgroup>` + `<thead>` block.
     *
     * @var list<string>
     */
    private array $tableHead = [];

    /**
     * For each group, the accumulated `<tr>` string.
     *
     * @var list<string>
     */
    private array $rows = [];

    public function __construct(
        private readonly PdfOptions $options = new PdfOptions(),
    ) {
    }

    /**
     * Returns a COPY with the page setting changed (see `PageAware`).
     *
     * The copy is a fresh writer: the body of a half-finished document cannot carry on with the new
     * setting, and it should not carry on either.
     */
    public function withPage(?Page $page, ?ColumnBudget $budget): static
    {
        if (null === $page && null === $budget) {
            return $this;
        }

        return new self(new PdfOptions(
            page: $page ?? $this->options->page,
            budget: $budget ?? $this->options->budget,
            fontFamily: $this->options->fontFamily,
            fontSizePt: $this->options->fontSizePt,
            repeatHeader: $this->options->repeatHeader,
            title: $this->options->title,
        ));
    }

    public function open(string $path): void
    {
        if ($this->opened) {
            throw WriterException::alreadyOpen();
        }

        // The target is checked at the VERY FIRST step — for the same reason as in `XlsxWriter`:
        // since the file is only written in `close()`, an unwritable path blows up AFTER every row
        // has been processed, and the user would wait for minutes and then get a "permission
        // denied" error.
        $this->guardTarget($path);

        $this->path = $path;
        $this->opened = true;
        $this->body = '';
        $this->sectionCount = 0;
        $this->paths = [];
        $this->resetSheet();
    }

    /**
     * @param list<Column> $columns
     */
    public function startSheet(string $name, array $columns): void
    {
        if (!$this->opened) {
            throw WriterException::notOpened();
        }

        // If a new sheet is started before the previous one has seen `finishSheet()`, we finish it
        // silently — the same behaviour as the other two writers.
        $this->finalizeSheet();

        $columns = array_values($columns);

        // Splitting is the SINGLE SOURCE OF TRUTH and is called here ONCE. Re-deriving the grouping
        // inside the writer (e.g. by looking at the width) would silently ignore `ColumnBudget`'s
        // anchor and priority rules.
        $groups = $this->options->budget->split($columns, $this->options->page);

        // Key → original position. A group's columns DO NOT PRESERVE the original order (the anchors
        // are pulled to the front), which is why the matching has to rely on the key rather than on
        // the order.
        $indexByKey = [];
        foreach ($columns as $index => $column) {
            $indexByKey[$column->key] = $index;
        }

        $this->positions = [];
        $this->cellTags = [];
        $this->tableHead = [];
        $this->rows = [];

        foreach ($groups as $group) {
            $widths = self::widthsOf($group);

            $head = '<colgroup>';
            foreach ($widths as $width) {
                $head .= '<col style="width:'.$width.'%">';
            }
            $head .= '</colgroup><thead><tr>';

            $positions = [];
            $tags = [];

            foreach ($group as $column) {
                $class = self::alignClass($column->align);

                $head .= '<th'.$class.'>'.self::escape($column->label).'</th>';
                $tags[] = '<td'.$class.'>';

                // -1 means "this column has no counterpart". It should never happen, since the
                // pipeline always builds the groups from the column list it passed in itself; if it
                // does happen, the cell is printed empty and the export does not stop.
                $positions[] = $indexByKey[$column->key] ?? -1;
            }

            $this->tableHead[] = $head.'</tr></thead>';
            $this->cellTags[] = $tags;
            $this->positions[] = $positions;
            $this->rows[] = '';
        }

        $this->sheetName = $name;
        $this->inSheet = true;
    }

    /**
     * @param list<Cell> $cells in the same order as the columns
     */
    public function writeRow(array $cells): void
    {
        if (!$this->inSheet) {
            throw WriterException::noActiveSheet();
        }

        // The buffer is not grown in place with `.=` but rebuilt from scratch: a tiny array with as
        // many elements as there are groups (one or two in practice), and in return the `list`
        // guarantee.
        $rows = [];

        foreach ($this->positions as $group => $positions) {
            $tags = $this->cellTags[$group];
            $row = '<tr>';

            foreach ($positions as $slot => $position) {
                // What gets printed is NOT the typed `value` but the localised `text`: a PDF is an
                // image, it has no such thing as a cell type. Had we written the raw float, "1234.5"
                // would have come out.
                $cell = $cells[$position] ?? null;

                $row .= $tags[$slot].(null === $cell ? '' : self::escape($cell->text)).'</td>';
            }

            $rows[] = $this->rows[$group].$row.'</tr>';
        }

        $this->rows = $rows;
    }

    public function finishSheet(): void
    {
        if (!$this->inSheet) {
            throw WriterException::noActiveSheet();
        }

        $this->finalizeSheet();
    }

    /**
     * Builds the document, hands it to Dompdf and writes it to disk.
     *
     * Like `XlsxWriter::close()`, THIS METHOD CAN THROW TOO: since all the work is done here, an
     * error here means "the file was never written", and swallowed silently the caller would offer
     * the user a file that does not exist.
     *
     * @return list<string> the file paths written
     */
    public function close(): array
    {
        if (!$this->opened) {
            // Already closed: so that it can be called repeatedly and used inside a `finally`.
            return $this->paths;
        }

        $this->finalizeSheet();

        $path = $this->path ?? throw WriterException::notOpened();
        $html = $this->document();

        try {
            $dompdf = new Dompdf($this->dompdfOptions());

            // The encoding is given EXPLICITLY. Without it Dompdf looks at the `<meta>` tag, and if
            // it cannot find that either it assumes Latin-1; Turkish letters are mangled as early as
            // the parsing stage.
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->render();

            $pdf = $dompdf->output();

            if (null === $pdf || '' === $pdf) {
                throw ExportException::unwritableTarget($path, 'Dompdf produced empty output');
            }

            if (false === file_put_contents($path, $pdf)) {
                throw ExportException::unwritableTarget($path, 'could not write the file (the disk may be full or the permission may have been lost)');
            }
        } catch (DompdfException $exception) {
            // Dompdf throws its own exception descending from `\Exception`; if we do not wrap it,
            // the `catch (TabulaException)` block that surrounds the export misses it.
            throw ExportException::unwritableTarget($path, $exception->getMessage());
        } finally {
            // Release the memory whatever happens: the accumulated HTML body can run to tens of
            // megabytes at fifty thousand rows and would stay held in long-lived workers
            // (messenger).
            $this->opened = false;
            $this->path = null;
            $this->body = '';
            $this->sectionCount = 0;
            $this->resetSheet();
        }

        $this->paths = [$path];

        return $this->paths;
    }

    // ---------------------------------------------------------------- document assembly

    /**
     * Commits the active sheet into the body.
     *
     * Unlike `finishSheet()`, it returns silently when there is no sheet — `startSheet()` and
     * `close()` call it with a "tidy up whatever the state is" intent.
     */
    private function finalizeSheet(): void
    {
        if (!$this->inSheet) {
            return;
        }

        $caption = $this->captionFor($this->sheetName);

        foreach ($this->tableHead as $group => $head) {
            // The first section starts on the page it is already on; the later ones on fresh paper.
            // The page break is given with a CSS class, not with `:first-child`: Dompdf's selector
            // support is limited and the price of guessing here would be an "empty first page".
            $break = $this->sectionCount > 0 ? ' class="set"' : '';
            ++$this->sectionCount;

            $this->body .= '<section'.$break.'>'
                // The sheet name only sits above the first table; the later groups are the
                // continuation of the same sheet and their rows are recognised from the anchor
                // columns.
                .(0 === $group ? $caption : '')
                .'<table>'.$head.'<tbody>'.$this->rows[$group].'</tbody></table>'
                .'</section>';
        }

        $this->resetSheet();
    }

    /** The HTML document, in one piece. */
    private function document(): string
    {
        $title = null === $this->options->title ? '' : trim($this->options->title);
        $escaped = self::escape($title);

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            // `<title>` is not decoration: Dompdf writes it into the "Title" field of the PDF file
            // properties, which means it shows up in the reader's tab and in search results.
            .('' === $title ? '' : '<title>'.$escaped.'</title>')
            .'<style>'.$this->css().'</style>'
            .'</head><body>'
            .('' === $title ? '' : '<h1>'.$escaped.'</h1>')
            .$this->body
            .'</body></html>';
    }

    /**
     * The styling of the document.
     *
     * The `@page` rule comes from `Page::cssPageRule()` and is the SINGLE source of the paper size.
     * `Dompdf::setPaper()` is DELIBERATELY NOT CALLED: if Dompdf sees the `size` value in the CSS
     * during rendering it overrides `setPaper()` itself (see `Dompdf::render()`), which means code
     * that defines the size in two places SILENTLY obeys the CSS. This was the most insidious bug of
     * the system this replaces — the PHP side said A4 landscape while the template said A5
     * landscape, and the `setPaper()` call was effectively decorative. A single source: the CSS.
     */
    private function css(): string
    {
        $options = $this->options;
        $size = $options->fontSizePt;

        return $options->page->cssPageRule()
            .sprintf(
                'body{margin:0;color:#000;font-family:%s;font-size:%spt;}',
                self::cssFontFamily($options->fontFamily),
                self::number($size),
            )
            .'h1{margin:0 0 3mm;font-size:'.self::number($size + 4.0).'pt;}'
            .'h2{margin:0 0 2mm;font-size:'.self::number($size + 2.0).'pt;}'
            // `display:block` is in Dompdf's own stylesheet as well; we still write it, because if
            // `section` stayed inline the page break would never be applied and all the groups would
            // pile up onto the same sheet of paper — silently.
            .'section{display:block;}section.set{page-break-before:always;}'
            // `table-layout:fixed` is mandatory: with automatic layout Dompdf distributes the column
            // widths ACCORDING TO THE CONTENT, and one single long description crushes every other
            // column until it cannot be read. With fixed layout the `<col>` percentages apply.
            .'table{table-layout:fixed;width:100%;border-collapse:collapse;}'
            // Repeating the header on every page is what `table-header-group` does; if the setting is
            // off, `table-row-group` is written EXPLICITLY, otherwise Dompdf's own default would
            // repeat it anyway and the setting would be good for nothing.
            .($options->repeatHeader ? 'thead{display:table-header-group;}' : 'thead{display:table-row-group;}')
            // Changing paper in the middle of a row leaves half of that row on one page and half of
            // it on the next in cells that wrap.
            .'tr{page-break-inside:avoid;}'
            // `word-wrap:break-word`: a long value that does not fit (e.g. a code with no spaces)
            // must not spill out of its column and print over its neighbour.
            .'th,td{border:0.5pt solid #bfbfbf;padding:1mm 1.2mm;text-align:left;vertical-align:top;word-wrap:break-word;}'
            .'th{background-color:#f2f2f2;font-weight:bold;}'
            .'.r{text-align:right;}.c{text-align:center;}';
    }

    /** Dompdf's runtime options. */
    private function dompdfOptions(): Options
    {
        $options = new Options();

        // The same family is written in the CSS as well; `defaultFont` is the safety net that kicks
        // in when the name over there cannot be resolved. If neither of them points at DejaVu,
        // Turkish letters turn into boxes.
        $options->setDefaultFont($this->options->fontFamily);

        // Remote resources OFF. We produce the template from start to finish ourselves, not a single
        // byte needs to be pulled in from outside; left on, an address embedded in a cell's text
        // could make the server issue a request during rendering (SSRF).
        $options->setIsRemoteEnabled(false);

        // `<script type="text/php">` evaluation OFF. We escape the text anyway, but leaving the
        // interpreter open at all in a document fed from data is not the right stance.
        $options->setIsPhpEnabled(false);

        // Debug output OFF: left on, Dompdf prints layout boxes and a CSS dump into the HTML, i.e. it
        // produces a "broken" PDF.
        //
        // NOTE: in Dompdf 3.x there IS NO setting called `isHtmlParserDebugEnabled` (it was removed
        // together with 0.8's `isHtml5ParserEnabled`). Passing it as an array key would not have
        // worked either: `Options::set()` SILENTLY swallows a key it does not recognise, via a
        // `method_exists()` check — the very same bug as `setPaper()`, in another disguise. That is
        // why the two debug switches that really do exist are turned off explicitly.
        $options->setDebugCss(false);
        $options->setDebugLayout(false);

        return $options;
    }

    /**
     * Writes the sheet name above the table as a heading.
     *
     * A PDF has no tabs; in output split up by `GroupedSheets`, if the sheet name (e.g. a customer
     * title) is not written, that information is lost COMPLETELY. If the name is identical to the
     * document title it is not printed: in the default single-sheet export the sheet name is already
     * the schema's title, and there is no point in writing the same text twice in a row.
     */
    private function captionFor(string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            return '';
        }

        $title = null === $this->options->title ? '' : trim($this->options->title);

        if ('' !== $title && mb_strtolower($name) === mb_strtolower($title)) {
            return '';
        }

        return '<h2>'.self::escape($name).'</h2>';
    }

    private function resetSheet(): void
    {
        $this->inSheet = false;
        $this->sheetName = '';
        $this->positions = [];
        $this->cellTags = [];
        $this->tableHead = [];
        $this->rows = [];
    }

    /**
     * Verifies at `open()` time that the target path is writable.
     *
     * Identical to its twin in `XlsxWriter`; both need the same early check because both write the
     * file in `close()`. We did not extract it into something shared: the writers should stay
     * independent of each other, so that one of them does not change the other's behaviour.
     */
    private function guardTarget(string $path): void
    {
        if ('' === trim($path)) {
            throw ExportException::unwritableTarget($path, 'the target path is empty');
        }

        if (is_file($path)) {
            if (!is_writable($path)) {
                throw ExportException::unwritableTarget($path, 'the file exists and is read-only');
            }

            return;
        }

        $directory = \dirname($path);

        if (!is_dir($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('the "%s" directory does not exist', $directory));
        }

        if (!is_writable($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('no write permission for the "%s" directory', $directory));
        }
    }

    // ---------------------------------------------------------------- small helpers

    /**
     * Distributes a group's column widths as PERCENTAGES.
     *
     * `Column::$width` is in Excel's character unit and has no millimetre equivalent; here it is used
     * only as a RELATIVE weight. A column that was given no width takes a share equal to the average
     * of the ones that were — and if none was given they all come down to an equal share. The total
     * is exactly 100 in every case; with an incomplete total `table-layout:fixed` narrows the last
     * column and the right edge of the table does not hold.
     *
     * @param list<Column> $group
     *
     * @return list<string> the percentages, in the same order as the columns
     */
    private static function widthsOf(array $group): array
    {
        $count = count($group);

        if (0 === $count) {
            return [];
        }

        $sized = [];
        foreach ($group as $column) {
            if (null !== $column->width && $column->width > 0) {
                $sized[] = $column->width;
            }
        }

        // If no width was given at all the average becomes 1.0 and all the weights are equalised;
        // there is no need to write a separate branch for the two cases.
        $average = [] === $sized ? 1.0 : array_sum($sized) / count($sized);

        $weights = [];
        foreach ($group as $column) {
            $weights[] = null !== $column->width && $column->width > 0 ? (float) $column->width : $average;
        }

        $total = array_sum($weights);
        $percents = [];
        $used = 0.0;

        foreach ($weights as $index => $weight) {
            // The last column absorbs the rounding remainder, so the total stays exactly 100.
            if ($index === $count - 1) {
                $percents[] = self::number(100.0 - $used);

                break;
            }

            $percent = round($weight / $total * 100.0, 4);
            $used += $percent;
            $percents[] = self::number($percent);
        }

        return $percents;
    }

    /**
     * Converts an `Align` into an alignment class.
     *
     * `Column::$align` is resolved by this point, so `Auto` never arrives; `default` still aligns
     * left (the CSS default) — the export does not stop because of a column that cannot be aligned.
     */
    private static function alignClass(Align $align): string
    {
        return match ($align) {
            Align::Right => ' class="r"',
            Align::Center => ' class="c"',
            default => '',
        };
    }

    /**
     * Makes cell/header text safe for HTML.
     *
     * `ENT_QUOTES`: the text may go not only into node content but, one day, into an attribute as
     * well. `ENT_SUBSTITUTE`: without the flag, one single cell containing INVALID UTF-8 is turned
     * into the EMPTY STRING by `htmlspecialchars` and the data is lost silently — which is exactly
     * what happens when the database holds a broken record left over from latin1.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Makes the font family safe to embed into CSS.
     *
     * The name comes from configuration; a value containing quotes or braces could close the
     * `<style>` block and turn the rest of the rules into rubbish.
     */
    private static function cssFontFamily(string $family): string
    {
        $clean = str_replace(['"', "'", '\\', ';', '{', '}', '<', '>'], '', $family);

        return '"'.$clean.'",sans-serif';
    }

    /** Print `8`, not `8.0000` — the rule should stay readable (see `Page::trim()`). */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
