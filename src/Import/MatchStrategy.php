<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

/**
 * How the columns in the file are matched against the fields in the schema.
 *
 * THE FATAL FLAW OF THE SYSTEM THIS REPLACES LIVED RIGHT HERE: matching was done with the
 * TRANSLATED HEADER TEXT, meaning the string "Customer Code" was the file's real identity.
 * The consequences:
 *  - A single word changed in a translation file silently made every old template users
 *    already had on disk unreadable.
 *  - A file with English headers never matched at all in a Turkish session.
 *
 * In Tabula the identity is the KEY. Row 1 of the template we generate carries the canonical
 * keys (hidden in Excel), row 2 is the translation the user reads, and data starts at row 3.
 */
enum MatchStrategy: string
{
    /**
     * The default: use the key row IF there is one, otherwise fall back to the label.
     *
     * The templates we generate make a flawless round trip; files a user prepared by hand,
     * or files coming from another system, keep working as well.
     */
    case Auto = 'auto';

    /** Match by the key row only; fail if there is none. For machine-to-machine flows. */
    case Key = 'key';

    /** Match by the translated label only. For backward compatibility with legacy files. */
    case Label = 'label';
}
