<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Page;

use Nouxwell\Tabula\Exception\ExportException;
use Nouxwell\Tabula\Export\Column;
use Nouxwell\Tabula\Schema\Priority;

/**
 * CALCULATES how many columns fit on the page and decides what to do with the ones that do not.
 *
 * A PDF has a physical width, so the number of columns is not a matter of preference but a
 * computable budget:
 *
 *     budget = floor( (page width − left/right margins) ÷ minimum column width )
 *
 * A4 landscape is 297 − 2×10 = 277 mm; with a 22 mm minimum width that makes 12 columns. Moving
 * the same schema onto A3 landscape raises the budget to 18 — changing the page size grows the
 * column count BY ITSELF, with no manual tuning.
 *
 * Splitting was deliberately SEPARATED FROM RENDERING: `split()` is a pure function, it does not
 * need Dompdf and can be tested on its own. The writer merely prints the groups it returns.
 */
final readonly class ColumnBudget
{
    private function __construct(
        private float $minWidthMm,
        private ?int $maxColumns,
        /** @var list<string> */
        private array $anchorKeys,
        private Overflow $overflow,
    ) {
    }

    /** Default: 22 mm minimum column, no hard ceiling, split into page sets on overflow. */
    public static function fit(): self
    {
        return new self(22.0, null, [], Overflow::NextPageSet);
    }

    /** Never split, try to fit them all. */
    public static function unlimited(): self
    {
        return new self(22.0, null, [], Overflow::Shrink);
    }

    /** The readability floor: no column is printed narrower than this. */
    public function minWidth(float $mm): self
    {
        if ($mm <= 0) {
            throw ExportException::invalidMinColumnWidth($mm);
        }

        return new self($mm, $this->maxColumns, $this->anchorKeys, $this->overflow);
    }

    /** Never print more columns than this, even if the width would allow it. */
    public function max(?int $columns): self
    {
        if (null !== $columns && $columns < 1) {
            throw ExportException::invalidMaxColumns($columns);
        }

        return new self($this->minWidthMm, $columns, $this->anchorKeys, $this->overflow);
    }

    /**
     * The columns repeated in every group (e.g. code and title).
     *
     * They are the only way a reader looking at the second group can tell which row they are on.
     * A key that is NOT PART OF the export is silently ignored: if the user did not select that
     * column there is nothing to anchor, and that is not an error.
     */
    public function anchor(string ...$keys): self
    {
        return new self($this->minWidthMm, $this->maxColumns, array_values($keys), $this->overflow);
    }

    public function overflow(Overflow $overflow): self
    {
        return new self($this->minWidthMm, $this->maxColumns, $this->anchorKeys, $overflow);
    }

    // ---------------------------------------------------------------- arithmetic

    /** How many columns fit on the page. */
    public function capacity(Page $page): int
    {
        $usable = $page->usableWidthMm();
        $fits = (int) floor($usable / $this->minWidthMm);

        if ($fits < 1) {
            throw ExportException::pageTooNarrow($usable, $this->minWidthMm);
        }

        return null === $this->maxColumns ? $fits : min($fits, $this->maxColumns);
    }

    /**
     * Splits the columns into groups that fit on the page.
     *
     * Every returned group is one "page set": all rows are printed first with the columns of
     * group 1, then printed again from the start with the columns of group 2.
     *
     * @param list<Column> $columns
     *
     * @return list<list<Column>> at least one group
     */
    public function split(array $columns, Page $page): array
    {
        if ([] === $columns) {
            return [];
        }

        // Shrink does no arithmetic at all: fitting is left entirely to the renderer.
        if (Overflow::Shrink === $this->overflow) {
            return [$columns];
        }

        $capacity = $this->capacity($page);

        if (count($columns) <= $capacity) {
            return [$columns];
        }

        return Overflow::Drop === $this->overflow
            ? [$this->dropByPriority($columns, $capacity)]
            : $this->intoPageSets($columns, $capacity);
    }

    /**
     * Drops columns by priority; the ORIGINAL ORDER of the survivors is preserved.
     *
     * @param list<Column> $columns
     *
     * @return list<Column>
     */
    private function dropByPriority(array $columns, int $capacity): array
    {
        // `Always` NEVER drops — that is the written promise of both `Overflow::Drop` and
        // `Priority`. If the mandatory columns alone exceed the budget there is NO WAY to keep
        // that promise: rather than cutting them off and silently printing an incomplete
        // document, we stop. A column declared MANDATORY in the output disappearing without
        // anyone being told is the very class of bug this library exists to eliminate.
        $mandatory = 0;
        foreach ($columns as $column) {
            if (Priority::Always === $column->priority) {
                ++$mandatory;
            }
        }

        if ($mandatory > $capacity) {
            throw ExportException::mandatoryColumnsExceedBudget($mandatory, $capacity);
        }

        $indexed = [];
        foreach ($columns as $index => $column) {
            $indexed[] = ['index' => $index, 'column' => $column];
        }

        // Always first, then Normal, Optional last. At equal priority the original order is
        // preserved (usort may not be stable, but comparing `index` guarantees it).
        usort($indexed, static function (array $a, array $b): int {
            $byPriority = $a['column']->priority->weight() <=> $b['column']->priority->weight();

            return 0 !== $byPriority ? $byPriority : $a['index'] <=> $b['index'];
        });

        $kept = array_slice($indexed, 0, $capacity);

        // After the elimination, put the columns back into readable order.
        usort($kept, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_map(static fn (array $entry): Column => $entry['column'], $kept);
    }

    /**
     * Splits into groups such that the anchor columns are repeated in every group.
     *
     * @param list<Column> $columns
     *
     * @return list<list<Column>>
     */
    private function intoPageSets(array $columns, int $capacity): array
    {
        $anchors = [];
        $rest = [];

        foreach ($columns as $column) {
            if (in_array($column->key, $this->anchorKeys, true)) {
                $anchors[] = $column;
                continue;
            }

            $rest[] = $column;
        }

        $slots = $capacity - count($anchors);

        if ($slots < 1) {
            throw ExportException::anchorsFillTheBudget(count($anchors), $capacity);
        }

        // `$rest` can never be empty here: we only get here while `count($columns) > $capacity`,
        // so if every column were an anchor `$slots` would come out negative and the guard above
        // would already have thrown. That is why there is NO empty-group fallback — dead code
        // describing a situation that cannot happen is good for nothing but misleading the reader.
        $groups = [];

        foreach (array_chunk($rest, $slots) as $chunk) {
            $groups[] = [...$anchors, ...$chunk];
        }

        return $groups;
    }
}
