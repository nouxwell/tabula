<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Exception;

use Nouxwell\Tabula\Format;
use RuntimeException;

/** Thrown when the export pipeline is set up incorrectly. */
final class ExportException extends RuntimeException implements TabulaException
{
    public static function noSource(): self
    {
        return new self('No data source was given: call ->from(...) first.');
    }

    /**
     * The PDF engine is not installed.
     *
     * Dompdf is not a REQUIRED dependency of the library (only a `suggest`), so that
     * applications exporting to Excel/CSV are not forced to carry tens of megabytes of font
     * tree around. The check runs at the moment the writer is CONSTRUCTED; without it the
     * `Dompdf\Dompdf` class would only be looked up inside `close()`, meaning a raw `Error`
     * would be thrown AFTER fifty thousand rows had already been processed — a failure that is
     * both late and, not being a `TabulaException`, one that slips past the caller's
     * `catch (TabulaException)` block.
     */
    public static function missingPdfEngine(): self
    {
        return new self('PDF output requires Dompdf, but it is not installed: composer require dompdf/dompdf');
    }

    /**
     * A page/column budget was given, but this format's writer has no concept of paper.
     *
     * Ignoring it SILENTLY is the very bug this phase exists to eliminate: in the system this
     * replaces, the `setPaper()` call was effectively decorative and nobody noticed that A5
     * was being printed. A setting is either applied or refused out loud.
     */
    public static function pageSettingsUnsupported(Format $format): self
    {
        return new self(sprintf(
            '->page()/->columns() was given, but the writer for the "%s" format does not understand page geometry (it is not PageAware). '
            .'Page size is only meaningful for PDF; in Xlsx and CSV there is no such thing as paper.',
            $format->value,
        ));
    }

    public static function noOutput(): self
    {
        return new self('The export produced no file at all.');
    }

    public static function noColumns(string $schema, Format $format): self
    {
        return new self(sprintf(
            'The "%s" schema has no columns left to write for the "%s" format — all of its fields may have been excluded from this format with Field::only().',
            $schema,
            $format->value,
        ));
    }

    public static function invalidPageSize(float $widthMm, float $heightMm): self
    {
        return new self(sprintf(
            'Page dimensions must be at least 1 mm; %s × %s mm was given.',
            $widthMm,
            $heightMm,
        ));
    }

    public static function negativeMargin(float $mm): self
    {
        return new self(sprintf('A margin cannot be negative; %s mm was given.', $mm));
    }

    public static function invalidMinColumnWidth(float $mm): self
    {
        return new self(sprintf('The minimum column width must be positive; %s mm was given.', $mm));
    }

    public static function invalidMaxColumns(int $columns): self
    {
        return new self(sprintf('The maximum column count must be at least 1; %d was given.', $columns));
    }

    /** The page is too narrow to take even one readable column. */
    public static function pageTooNarrow(float $usableMm, float $minWidthMm): self
    {
        return new self(sprintf(
            'The usable page width is %s mm while the minimum column width is %s mm — not even one column fits. '
            .'Use a larger page (e.g. A3 instead of A4), switch to landscape, reduce the margins, or lower the minWidth value.',
            $usableMm,
            $minWidthMm,
        ));
    }

    /**
     * The `Priority::Always` columns do not fit on the page on their own.
     *
     * Dropping some of them would break a promise: both `Overflow::Drop` and `Priority` say
     * "Always is never dropped". Rather than silently printing an incomplete document, we stop.
     */
    public static function mandatoryColumnsExceedBudget(int $mandatory, int $capacity): self
    {
        return new self(sprintf(
            'There are %d mandatory (Priority::Always) columns but the page budget is %d columns — and these cannot be dropped. '
            .'Use a larger page (e.g. A3 instead of A4), switch to landscape, lower the minWidth value, '
            .'or take the Always priority off some of the fields.',
            $mandatory,
            $capacity,
        ));
    }

    /** The anchor columns eat the whole budget; no data column is left over. */
    public static function anchorsFillTheBudget(int $anchors, int $capacity): self
    {
        return new self(sprintf(
            'The anchor column count (%d) fills the entire page budget (%d columns), leaving no room for data columns. '
            .'Choose fewer anchors or widen the page.',
            $anchors,
            $capacity,
        ));
    }

    public static function unwritableTarget(string $path, string $reason): self
    {
        return new self(sprintf('Cannot write to the target "%s": %s', $path, $reason));
    }
}
