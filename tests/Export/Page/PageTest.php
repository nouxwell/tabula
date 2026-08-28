<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Export\Page;

use Nouxwell\Tabula\Exception\ExportException;
use Nouxwell\Tabula\Export\Page\Orientation;
use Nouxwell\Tabula\Export\Page\Page;
use Nouxwell\Tabula\Export\Page\PageSize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Page geometry — pure arithmetic.
 *
 * The numbers here cannot be "roughly right". The width of the paper is the input to the entire
 * column budget: a one-millimetre deviation turns into a whole COLUMN of difference because of
 * the `floor()` inside `ColumnBudget::capacity()`, and the user only ever sees it as a missing
 * column in the PDF.
 *
 * `cssPageRule()` is moreover the very reason the class exists: in the system this replaces, the
 * paper size was defined both in PHP (`Dompdf::setPaper()`) and in the template's `@page` rule,
 * Dompdf silently obeyed the CSS, and `setPaper()` was effectively decorative. The tests below
 * nail down that the rule REALLY reflects the chosen measurement and that an orientation change
 * works its way into the rule.
 */
#[CoversClass(Page::class)]
#[CoversClass(PageSize::class)]
final class PageTest extends TestCase
{
    // ---------------------------------------------------------------- measurements

    #[Test]
    public function standardPapersCarryTheirRealMillimetres(): void
    {
        $a4 = Page::a4();
        self::assertSame(210.0, $a4->widthMm());
        self::assertSame(297.0, $a4->heightMm());

        $a5 = Page::a5();
        self::assertSame(148.0, $a5->widthMm());
        self::assertSame(210.0, $a5->heightMm());

        $a3 = Page::a3();
        self::assertSame(297.0, $a3->widthMm());
        self::assertSame(420.0, $a3->heightMm());

        // Letter/Legal are not metric: their dimensions come from inches and are NOT whole numbers.
        // A rounded 216 mm here could silently create a one-column difference in the budget.
        $letter = Page::letter();
        self::assertSame(215.9, $letter->widthMm());
        self::assertSame(279.4, $letter->heightMm());

        $legal = Page::of(PageSize::Legal);
        self::assertSame(215.9, $legal->widthMm());
        self::assertSame(355.6, $legal->heightMm());
    }

    #[Test]
    public function landscapeSwapsTheEdgesInsteadOfInventingNewOnes(): void
    {
        $a4 = Page::a4()->landscape();
        self::assertSame(297.0, $a4->widthMm());
        self::assertSame(210.0, $a4->heightMm());

        $a3 = Page::a3()->landscape();
        self::assertSame(420.0, $a3->widthMm());
        self::assertSame(297.0, $a3->heightMm());

        self::assertSame(Orientation::Landscape, $a3->orientation);
    }

    #[Test]
    public function customPaperIsGivenInPortraitAndTurnsLikeAnyOther(): void
    {
        $ticket = Page::custom(80.0, 200.0);

        self::assertSame(80.0, $ticket->widthMm());
        self::assertSame(200.0, $ticket->heightMm());
        self::assertSame(200.0, $ticket->landscape()->widthMm());
        self::assertSame(80.0, $ticket->landscape()->heightMm());
    }

    // ---------------------------------------------------------------- usable width

    #[Test]
    public function usableWidthIsThePaperMinusTheTwoSideMargins(): void
    {
        // A4 landscape, 10 mm on all four edges: 297 − 10 − 10 = 277. The whole column-budget
        // arithmetic starts from this number (see ColumnBudgetTest).
        self::assertSame(277.0, Page::a4()->landscape()->margins(10.0)->usableWidthMm());

        // Only the LEFT/RIGHT margins count: the top/bottom margins do not affect the column count.
        self::assertSame(257.0, Page::a4()->landscape()->marginsOf(5.0, 20.0, 5.0, 20.0)->usableWidthMm());

        // On the same paper in portrait the usable width drops to 190 mm — an orientation change
        // affects the budget directly, no separate setting is needed.
        self::assertSame(190.0, Page::a4()->margins(10.0)->usableWidthMm());
    }

    #[Test]
    public function theDefaultMarginIsTenMillimetresOnEveryEdge(): void
    {
        $page = Page::a4();

        self::assertSame(10.0, $page->marginTopMm);
        self::assertSame(10.0, $page->marginRightMm);
        self::assertSame(10.0, $page->marginBottomMm);
        self::assertSame(10.0, $page->marginLeftMm);
    }

    #[Test]
    public function marginsSetsAllFourEdgesAtOnce(): void
    {
        $page = Page::a4()->margins(7.5);

        self::assertSame(7.5, $page->marginTopMm);
        self::assertSame(7.5, $page->marginRightMm);
        self::assertSame(7.5, $page->marginBottomMm);
        self::assertSame(7.5, $page->marginLeftMm);
    }

    #[Test]
    public function marginsOfKeepsTheEdgesInCssOrder(): void
    {
        // The order is the SAME as CSS's `margin` shorthand: top, right, bottom, left. Were it
        // different, `cssPageRule()` would write the right numbers onto the wrong edges and
        // nobody would notice.
        $page = Page::a4()->marginsOf(1.0, 2.0, 3.0, 4.0);

        self::assertSame(1.0, $page->marginTopMm);
        self::assertSame(2.0, $page->marginRightMm);
        self::assertSame(3.0, $page->marginBottomMm);
        self::assertSame(4.0, $page->marginLeftMm);
    }

    // ---------------------------------------------------------------- immutability

    #[Test]
    public function turningThePageLeavesTheOriginalUntouched(): void
    {
        $portrait = Page::a4();
        $landscape = $portrait->landscape();

        self::assertNotSame($portrait, $landscape);
        self::assertSame(Orientation::Portrait, $portrait->orientation);
        self::assertSame(210.0, $portrait->widthMm(), 'The portrait page was MUTATED while being turned to landscape.');
    }

    #[Test]
    public function turningTwiceInTheSameDirectionChangesNothing(): void
    {
        $twice = Page::a4()->landscape()->landscape();

        self::assertSame(297.0, $twice->widthMm());
        self::assertSame(210.0, $twice->heightMm());
    }

    #[Test]
    public function goingBackToPortraitRestoresExactlyThePortraitPage(): void
    {
        // `landscape()` does not swap the dimensions, it only holds the ORIENTATION; the swap
        // happens at read time. Had the dimensions really been swapped, a there-and-back turn
        // would produce an absurd page such as 297×210 portrait, and the bug would only show up
        // in print.
        $portrait = Page::a4()->margins(12.0);
        $roundTrip = $portrait->landscape()->portrait();

        self::assertEquals($portrait, $roundTrip);
        self::assertSame($portrait->cssPageRule(), $roundTrip->cssPageRule());
    }

    #[Test]
    public function marginsSurviveAnOrientationChangeAndViceVersa(): void
    {
        $page = Page::a3()->marginsOf(1.0, 2.0, 3.0, 4.0)->landscape();

        self::assertSame(2.0, $page->marginRightMm);
        self::assertSame(420.0, $page->widthMm());

        $again = $page->margins(6.0);
        self::assertSame(Orientation::Landscape, $again->orientation);
        self::assertSame(6.0, $again->marginLeftMm);
    }

    // ---------------------------------------------------------------- refused setups

    #[Test]
    public function aNegativeMarginIsRefused(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/cannot be negative/');

        Page::a4()->margins(-1.0);
    }

    #[Test]
    public function aSingleNegativeEdgeIsEnoughToBeRefused(): void
    {
        // Checking all four at once is not enough: a single negative edge is already enough to
        // make `usableWidthMm()` BIGGER than the paper, and the budget hands out a made-up extra column.
        $this->expectException(ExportException::class);

        Page::a4()->marginsOf(10.0, 10.0, -0.5, 10.0);
    }

    #[Test]
    public function aZeroMarginIsPerfectlyLegal(): void
    {
        // A zero margin is a real need in label/receipt printing; it must not be lumped in with a negative one.
        $page = Page::a4()->margins(0.0);

        self::assertSame(0.0, $page->marginTopMm);
        self::assertSame(210.0, $page->usableWidthMm());
    }

    #[Test]
    public function aPaperWithoutWidthIsRefused(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/at least 1 mm/');

        Page::custom(0.0, 100.0);
    }

    #[Test]
    public function aSubMillimetrePaperIsRefusedBecauseTheCssRuleWouldRoundItToZero(): void
    {
        // `cssPageRule()` writes the measurement with two decimals: 0.004 mm passes a "positive"
        // check but lands in the rule as `0mm`, and Dompdf silently falls back to ITS OWN default
        // paper. The value that is validated and the value that is printed must not drift apart.
        $this->expectException(ExportException::class);

        Page::custom(0.004, 100.0);
    }

    #[Test]
    public function aPaperWithANegativeEdgeIsRefused(): void
    {
        $this->expectException(ExportException::class);

        Page::custom(100.0, -1.0);
    }

    // ---------------------------------------------------------------- ★ the single source of truth

    #[Test]
    public function theCssRuleSpellsOutTheChosenPaperExactly(): void
    {
        // The EXACT text of the rule is nailed down: this string is the ONLY place Dompdf learns
        // the paper size from. A stray space or a shifted unit silently drops Dompdf back to its
        // default Letter.
        self::assertSame(
            '@page { size: 420mm 297mm; margin: 10mm 10mm 10mm 10mm; }',
            Page::a3()->landscape()->margins(10.0)->cssPageRule(),
        );
    }

    #[Test]
    public function changingTheOrientationChangesTheRule(): void
    {
        $a3 = Page::a3()->margins(10.0);

        self::assertSame('@page { size: 297mm 420mm; margin: 10mm 10mm 10mm 10mm; }', $a3->cssPageRule());
        self::assertSame('@page { size: 420mm 297mm; margin: 10mm 10mm 10mm 10mm; }', $a3->landscape()->cssPageRule());
    }

    #[Test]
    public function theRuleCarriesEachMarginToItsOwnEdge(): void
    {
        self::assertSame(
            '@page { size: 297mm 210mm; margin: 12.5mm 8mm 12.5mm 8mm; }',
            Page::a4()->landscape()->marginsOf(12.5, 8.0, 12.5, 8.0)->cssPageRule(),
        );
    }

    #[Test]
    public function theRuleDropsNoiseZerosButKeepsARealFraction(): void
    {
        // `210.00mm` is perfectly valid CSS too; but a rule nobody reads is a rule nobody
        // reviews. Tidying up is not the same as SHORTENING, though: if Letter's 215.9 gets
        // rounded, the paper actually changes.
        self::assertSame(
            '@page { size: 210mm 297mm; margin: 10mm 10mm 10mm 10mm; }',
            Page::a4()->cssPageRule(),
        );

        self::assertSame(
            '@page { size: 215.9mm 279.4mm; margin: 10mm 10mm 10mm 10mm; }',
            Page::letter()->cssPageRule(),
        );

        self::assertSame(
            '@page { size: 210mm 297mm; margin: 0mm 0mm 0mm 0mm; }',
            Page::a4()->margins(0.0)->cssPageRule(),
        );
    }
}
