<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Page;

/**
 * What to do when the columns do not fit on the page.
 *
 * In the system this replaces this choice DID NOT EXIST: there was no column limit either, under
 * `table-layout: fixed` the columns were squeezed until they could not be read, and the only
 * remedy was a patch that broke the header by hand with `<br>`. All three behaviours must be a
 * deliberate choice.
 */
enum Overflow: string
{
    /**
     * Split the columns into GROUPS; each group is printed as its own page set.
     *
     * The anchor columns (`ColumnBudget::anchor()`) are repeated in every group, so a reader
     * looking at the second group knows which row they are on. This is how wide tables get
     * printed onto paper; no data is lost.
     */
    case NextPageSet = 'next_page_set';

    /**
     * DROP the low-priority columns, stay in a single group.
     *
     * `Priority::Optional` goes first, then `Normal`; `Always` never drops.
     * The right choice for single-page summary output — but it LOSES DATA, so it has to be chosen
     * deliberately.
     */
    case Drop = 'drop';

    /**
     * Never split, try to fit them all.
     *
     * The minimum-width rule is ignored; with many columns the result becomes unreadable. For
     * output where the column count really is limited and splitting is not wanted.
     */
    case Shrink = 'shrink';
}
