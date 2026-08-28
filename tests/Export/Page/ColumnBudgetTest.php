<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Export\Page;

use Nouxwell\Tabula\Exception\ExportException;
use Nouxwell\Tabula\Export\Column;
use Nouxwell\Tabula\Export\Page\ColumnBudget;
use Nouxwell\Tabula\Export\Page\Overflow;
use Nouxwell\Tabula\Export\Page\Page;
use Nouxwell\Tabula\Schema\Align;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Schema\Priority;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The column budget — the real work on the PDF side.
 *
 * In the system this replaces there was NO limit on the column count: under
 * `table-layout: fixed` twenty columns were squeezed past the point of readability, and the only
 * remedy was a patch that broke the header by hand with `<br>`. The claim made here is a
 * different one: since the width of the paper is a measurable thing, the column count is not a
 * preference but a CALCULATION — and what happens to the columns that do not fit is a deliberate
 * choice.
 *
 * `split()` is deliberately pure (it does not need Dompdf); that is why this file can verify
 * every grouping rule without writing a single file.
 */
#[CoversClass(ColumnBudget::class)]
#[CoversClass(Overflow::class)]
final class ColumnBudgetTest extends TestCase
{
    // ---------------------------------------------------------------- ★ the budget arithmetic

    #[Test]
    public function theBudgetGrowsWithThePaperWithoutTouchingTheConfiguration(): void
    {
        $budget = ColumnBudget::fit();

        // A4 landscape: (297 − 2×10) ÷ 22 = 12.59 → 12 columns.
        self::assertSame(12, $budget->capacity(Page::a4()->landscape()));

        // The SAME budget object, only the paper grew: (420 − 2×10) ÷ 22 = 18.18 → 18 columns.
        // This is exactly what the architecture claims: making the page bigger grows the column
        // count by itself, and there is no need to go looking for a manual "maximum columns" setting.
        self::assertSame(18, $budget->capacity(Page::a3()->landscape()));

        // And it narrows when the paper turns portrait: (210 − 2×10) ÷ 22 = 8.63 → 8 columns.
        // These two numbers are what `PdfOptions` means when it says landscape paper gives
        // 12 columns instead of 8.
        self::assertSame(8, $budget->capacity(Page::a4()));
    }

    #[Test]
    public function aTighterMinimumWidthBuysMoreColumnsOnTheSamePaper(): void
    {
        $page = Page::a4()->landscape();

        // 277 ÷ 40 = 6.9 → 6. A report that wants wide columns (an address, say) gets fewer of them.
        self::assertSame(6, ColumnBudget::fit()->minWidth(40.0)->capacity($page));

        // 277 ÷ 15 = 18.4 → 18. Lowering the readability floor grows the budget; that decision
        // belongs to the caller, not to the library.
        self::assertSame(18, ColumnBudget::fit()->minWidth(15.0)->capacity($page));
    }

    #[Test]
    public function theHardCeilingWinsOnlyWhenItIsLowerThanWhatFits(): void
    {
        $page = Page::a4()->landscape();

        // The width allows 12 but the ceiling is 5: min(12, 5) = 5.
        self::assertSame(5, ColumnBudget::fit()->max(5)->capacity($page));

        // When the ceiling is higher than the width, the paper wins — `max()` is not a
        // GUARANTEE, it is a LIMIT.
        self::assertSame(12, ColumnBudget::fit()->max(50)->capacity($page));

        // null removes the ceiling.
        self::assertSame(12, ColumnBudget::fit()->max(5)->max(null)->capacity($page));
    }

    #[Test]
    public function aCeilingBelowOneColumnIsRefused(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/must be at least 1/');

        ColumnBudget::fit()->max(0);
    }

    #[Test]
    public function aNonPositiveMinimumWidthIsRefused(): void
    {
        // A zero minimum width would mean a division by zero inside `capacity()`; it is the
        // SETUP that has to be stopped, not the arithmetic itself — the error message is still
        // meaningful there.
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/must be positive/');

        ColumnBudget::fit()->minWidth(0.0);
    }

    #[Test]
    public function aPageTooNarrowForASingleReadableColumnIsRefused(): void
    {
        // On A5 portrait (148 mm), 70 mm margins leave 8 mm behind: not even one column fits.
        // Silently returning zero columns would produce an empty PDF and nobody would work out why.
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/not even one column fits/');

        ColumnBudget::fit()->capacity(Page::a5()->margins(70.0));
    }

    #[Test]
    public function aMinimumWidthWiderThanThePaperIsRefusedToo(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/not even one column fits/');

        ColumnBudget::fit()->minWidth(300.0)->capacity(Page::a4()->landscape());
    }

    // ---------------------------------------------------------------- immutability

    #[Test]
    public function everySettingReturnsACopyAndLeavesTheOriginalAlone(): void
    {
        // A budget is typically built once somewhere and shared across many exports; if it
        // mutated in place, one call site's `max(3)` would spread to the whole application.
        $base = ColumnBudget::fit();
        $page = Page::a4()->landscape();

        $narrow = $base->minWidth(40.0);
        $capped = $base->max(3);
        $anchored = $base->anchor('code');
        $dropping = $base->overflow(Overflow::Drop);

        self::assertNotSame($base, $narrow);
        self::assertNotSame($base, $capped);
        self::assertNotSame($base, $anchored);
        self::assertNotSame($base, $dropping);

        self::assertSame(12, $base->capacity($page), 'The base budget has been polluted.');
        self::assertCount(2, $base->split(self::cols(18), $page), 'The overflow behaviour of the base budget has changed.');
    }

    // ---------------------------------------------------------------- a table that fits

    #[Test]
    public function columnsThatAlreadyFitComeBackAsOneUntouchedGroup(): void
    {
        $columns = self::cols(5);

        $groups = ColumnBudget::fit()->split($columns, Page::a4()->landscape());

        self::assertCount(1, $groups);
        // `assertSame` on purpose: the objects must not be copied, nor their order changed.
        self::assertSame($columns, $groups[0]);
    }

    #[Test]
    public function splittingNothingGivesNothing(): void
    {
        // A page with no columns must NOT return a made-up empty group: `PdfWriter` produces one
        // `<table>` per group, and an empty group would print a table with no header and no cells.
        self::assertSame([], ColumnBudget::fit()->split([], Page::a4()->landscape()));
    }

    // ---------------------------------------------------------------- Shrink

    #[Test]
    public function shrinkNeverSplitsNoMatterHowManyColumnsThereAre(): void
    {
        $columns = self::cols(40);

        $groups = ColumnBudget::unlimited()->split($columns, Page::a4()->landscape());

        self::assertCount(1, $groups);
        self::assertSame($columns, $groups[0]);
    }

    #[Test]
    public function shrinkDoesNotEvenComplainAboutAPageThatIsTooNarrow(): void
    {
        // Shrink means "do not ask me for the arithmetic, leave the fitting to the renderer";
        // since the budget is never computed, `pageTooNarrow` must not be thrown either.
        // Otherwise a user who deliberately chose unlimited would trip over a rule they do not
        // care about at all.
        $groups = ColumnBudget::unlimited()->split(self::cols(30), Page::a5()->margins(70.0));

        self::assertCount(1, $groups);
        self::assertCount(30, $groups[0]);
    }

    // ---------------------------------------------------------------- Drop

    #[Test]
    public function dropKeepsExactlyTheBudgetAndSheddsTheOptionalColumnsFirst(): void
    {
        // Original order: a(Optional) b(Always) c(Normal) d(Optional) e(Normal) f(Always)
        // The budget is 4. The Optional ones (a, d) drop first; b, c, e, f are left.
        $columns = [
            self::col('a', Priority::Optional),
            self::col('b', Priority::Always),
            self::col('c', Priority::Normal),
            self::col('d', Priority::Optional),
            self::col('e', Priority::Normal),
            self::col('f', Priority::Always),
        ];

        $groups = ColumnBudget::fit()->overflow(Overflow::Drop)->max(4)
            ->split($columns, Page::a4()->landscape());

        self::assertCount(1, $groups, 'Drop never splits; a single group must come back.');
        self::assertCount(4, $groups[0], 'As many columns as the budget should have been left.');

        // ★ The order is the ORIGINAL order, not the priority order. The elimination goes by
        // priority, but the survivors are put back into readable order; otherwise the output
        // would be b, f, c, e and the column layout the user chose would be silently reshuffled.
        self::assertSame(['b', 'c', 'e', 'f'], self::keys($groups[0]));
    }

    #[Test]
    public function dropSheddsNormalColumnsOnlyAfterTheOptionalOnesAreGone(): void
    {
        // The budget is 3: even once both Optionals have dropped there is still one too many, so
        // the next victim must be the leading Normal — without Always being touched.
        $columns = [
            self::col('a', Priority::Normal),
            self::col('b', Priority::Optional),
            self::col('c', Priority::Always),
            self::col('d', Priority::Normal),
            self::col('e', Priority::Optional),
        ];

        $groups = ColumnBudget::fit()->overflow(Overflow::Drop)->max(3)
            ->split($columns, Page::a4()->landscape());

        self::assertSame(['a', 'c', 'd'], self::keys($groups[0]));
    }

    #[Test]
    public function dropKeepsEveryAlwaysColumnWhileThereIsRoom(): void
    {
        // Wherever there is room, `Always` is untouchable: a list that loses the account code
        // becomes unreadable, and there is no way to tell that anything is missing either.
        $columns = [
            self::col('k1', Priority::Optional),
            self::col('k2', Priority::Optional),
            self::col('k3', Priority::Always),
            self::col('k4', Priority::Optional),
            self::col('k5', Priority::Always),
        ];

        $groups = ColumnBudget::fit()->overflow(Overflow::Drop)->max(2)
            ->split($columns, Page::a4()->landscape());

        self::assertSame(['k3', 'k5'], self::keys($groups[0]));
    }

    #[Test]
    public function dropRefusesToShedAMandatoryColumnEvenWhenTheyAloneOverflow(): void
    {
        // `Overflow::Drop` and `Priority` both promise that "Always never drops". When four
        // untouchable columns do not fit a three-column budget there is no way to keep that
        // promise — so the right move is to stop, not to cut them off and silently print an
        // INCOMPLETE document. A column declared MANDATORY in the output disappearing without
        // anyone being told is the very class of bug this library exists to eliminate.
        $columns = [
            self::col('k1', Priority::Always),
            self::col('k2', Priority::Always),
            self::col('k3', Priority::Always),
            self::col('k4', Priority::Always),
        ];

        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/There are 4 mandatory .*page budget is 3 columns/s');

        ColumnBudget::fit()->overflow(Overflow::Drop)->max(3)
            ->split($columns, Page::a4()->landscape());
    }

    #[Test]
    public function dropKeepsEveryMandatoryColumnWhenTheyFit(): void
    {
        // The boundary: when the mandatory count EQUALS the budget the elimination has to run
        // rather than blow up — the optional ones drop and all three Always columns stay.
        $columns = [
            self::col('k1', Priority::Always),
            self::col('k2', Priority::Optional),
            self::col('k3', Priority::Always),
            self::col('k4', Priority::Optional),
            self::col('k5', Priority::Always),
        ];

        $groups = ColumnBudget::fit()->overflow(Overflow::Drop)->max(3)
            ->split($columns, Page::a4()->landscape());

        self::assertSame(['k1', 'k3', 'k5'], self::keys($groups[0]));
    }

    // ---------------------------------------------------------------- ★ NextPageSet

    #[Test]
    public function nextPageSetCutsTheTableIntoGroupsAndRepeatsTheAnchors(): void
    {
        // 18 columns, a budget of 12 on A4 landscape, two anchors. Because the anchors are
        // repeated in every group, the slots left for data columns are 12 − 2 = 10.
        $groups = ColumnBudget::fit()->anchor('k1', 'k2')
            ->split(self::cols(18), Page::a4()->landscape());

        self::assertCount(2, $groups);

        self::assertSame(
            ['k1', 'k2', 'k3', 'k4', 'k5', 'k6', 'k7', 'k8', 'k9', 'k10', 'k11', 'k12'],
            self::keys($groups[0]),
        );

        // The remaining 6 data columns + the two anchors = 8. The last group is not obliged to
        // fill the budget.
        self::assertSame(
            ['k1', 'k2', 'k13', 'k14', 'k15', 'k16', 'k17', 'k18'],
            self::keys($groups[1]),
        );
    }

    #[Test]
    public function theAnchorsComeFirstInTheirOriginalOrderNotInTheOrderTheyWereNamed(): void
    {
        // The anchors were picked from the middle of the list and handed to `anchor()` in
        // REVERSE order. They still have to move to the front of the group in their original
        // order (k2, k5): the anchors are where the reader recognises the row, and a header
        // layout that shifts around from group to group does exactly the opposite.
        $groups = ColumnBudget::fit()->anchor('k5', 'k2')
            ->split(self::cols(18), Page::a4()->landscape());

        self::assertCount(2, $groups);

        self::assertSame(
            ['k2', 'k5', 'k1', 'k3', 'k4', 'k6', 'k7', 'k8', 'k9', 'k10', 'k11', 'k12'],
            self::keys($groups[0]),
        );

        self::assertSame(
            ['k2', 'k5', 'k13', 'k14', 'k15', 'k16', 'k17', 'k18'],
            self::keys($groups[1]),
        );
    }

    #[Test]
    public function noDataColumnIsLostOrPrintedTwiceWhenTheTableIsCut(): void
    {
        // This is the whole promise of NextPageSet: unlike Drop, NO data is lost. With the
        // anchors taken out, the union of the groups must be exactly the original data columns —
        // in the same order, with no repetition and nothing missing.
        $columns = self::cols(18);
        $anchors = ['k1', 'k2'];

        $groups = ColumnBudget::fit()->anchor(...$anchors)
            ->split($columns, Page::a4()->landscape());

        $printed = [];
        foreach ($groups as $group) {
            foreach ($group as $column) {
                if (!in_array($column->key, $anchors, true)) {
                    $printed[] = $column->key;
                }
            }
        }

        $expected = [];
        foreach ($columns as $column) {
            if (!in_array($column->key, $anchors, true)) {
                $expected[] = $column->key;
            }
        }

        self::assertSame($expected, $printed);
        self::assertSame($printed, array_values(array_unique($printed)), 'A data column ended up in two groups at once.');
    }

    #[Test]
    public function withoutAnchorsTheGroupsAreSimplyConsecutiveSlices(): void
    {
        $groups = ColumnBudget::fit()->split(self::cols(25), Page::a4()->landscape());

        self::assertCount(3, $groups);
        self::assertCount(12, $groups[0]);
        self::assertCount(12, $groups[1]);
        self::assertCount(1, $groups[2]);
        self::assertSame(['k25'], self::keys($groups[2]));
    }

    #[Test]
    public function anAnchorTheUserDidNotExportIsQuietlyIgnored(): void
    {
        // The anchor list is typically written out once alongside the schema, while the user
        // picks their own columns. An anchor that was not selected is NOT AN ERROR — stopping
        // the export over it would mean the library complaining about the user's column choice.
        $groups = ColumnBudget::fit()->anchor('musteri.kod', 'k1')
            ->split(self::cols(18), Page::a4()->landscape());

        self::assertCount(2, $groups);
        // Only the anchor that really exists is repeated: ONE slot (not two) comes off the
        // budget, so 11 slots are left for data columns and the 17 data columns split as 11 + 6.
        self::assertSame('k1', $groups[0][0]->key);
        self::assertSame('k1', $groups[1][0]->key);
        self::assertCount(12, $groups[0]);
        self::assertCount(7, $groups[1]);
    }

    #[Test]
    public function anchorsThatEatTheWholeBudgetAreRefused(): void
    {
        // When the anchor count equals the budget, no DATA column is left over: every group
        // would consist of the same two anchors and an endless number of meaningless page sets
        // would be produced. A setup error instead of a silent infinite loop.
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/leaving no room for data columns/');

        ColumnBudget::fit()->max(2)->anchor('k1', 'k2')
            ->split(self::cols(9), Page::a4()->landscape());
    }

    #[Test]
    public function moreAnchorsThanTheBudgetAreRefusedToo(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/The anchor column count/');

        ColumnBudget::fit()->max(2)->anchor('k1', 'k2', 'k3')
            ->split(self::cols(9), Page::a4()->landscape());
    }

    #[Test]
    public function anchorsAreIrrelevantWhenEverythingAlreadyFits(): void
    {
        // Even anchors that fill the budget must not try to split a table that already fits:
        // with no overflow `split()` does no grouping at all, so `anchorsFillTheBudget` is
        // never thrown.
        $columns = self::cols(2);

        $groups = ColumnBudget::fit()->max(2)->anchor('k1', 'k2')
            ->split($columns, Page::a4()->landscape());

        self::assertCount(1, $groups);
        self::assertSame($columns, $groups[0]);
    }

    #[Test]
    public function aBiggerPageCanRemoveTheNeedToSplitAtAll(): void
    {
        $columns = self::cols(16);
        $budget = ColumnBudget::fit()->anchor('k1');

        // 16 columns do not fit on A4 landscape (the budget is 12) → two groups.
        self::assertCount(2, $budget->split($columns, Page::a4()->landscape()));

        // On A3 landscape the budget rises to 18 → no split at all, and not one line of the
        // configuration changed.
        self::assertSame([$columns], $budget->split($columns, Page::a3()->landscape()));
    }

    // ---------------------------------------------------------------- helpers

    private static function col(string $key, Priority $priority = Priority::Normal): Column
    {
        return new Column(
            key: $key,
            label: strtoupper($key),
            type: FieldType::String,
            align: Align::Left,
            width: null,
            required: false,
            priority: $priority,
        );
    }

    /** @return list<Column> */
    private static function cols(int $count): array
    {
        $columns = [];

        for ($i = 1; $i <= $count; ++$i) {
            $columns[] = self::col('k'.$i);
        }

        return $columns;
    }

    /**
     * @param list<Column> $columns
     *
     * @return list<string>
     */
    private static function keys(array $columns): array
    {
        return array_map(static fn (Column $column): string => $column->key, $columns);
    }
}
