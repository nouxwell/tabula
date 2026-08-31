<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Import;

use Nouxwell\Tabula\Import\KeyRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule an application with its own reader has to share with this library.
 *
 * It is public for one reason: an application that already has a reader — most do, long before
 * they meet this package — must answer "is row 1 the key row?" before it knows where the data
 * starts. Answering it by hand means a second copy of this rule, and the day the two drift the
 * reader stops being able to read what the writer produces.
 */
#[CoversClass(KeyRow::class)]
final class KeyRowTest extends TestCase
{
    /** @var list<string> */
    private const array KEYS = ['customerCode', 'customerName', 'total'];

    #[Test]
    public function aRowOfKnownKeysIsAKeyRow(): void
    {
        self::assertSame(
            [0 => 'customerCode', 1 => 'customerName', 2 => 'total'],
            KeyRow::detect(['customerCode', 'customerName', 'total'], self::KEYS),
        );
    }

    #[Test]
    public function aRowOfTranslatedLabelsIsNot(): void
    {
        self::assertNull(KeyRow::detect(['Cari Kodu', 'Ünvan', 'Toplam'], self::KEYS));
    }

    /**
     * One stranger is enough to settle it.
     *
     * Guessing "mostly keys, so probably a key row" would take a row of data for a header and
     * drop it.
     */
    #[Test]
    public function oneUnknownCellDisqualifiesTheWholeRow(): void
    {
        self::assertNull(KeyRow::detect(['customerCode', 'customerName', 'sipariş'], self::KEYS));
    }

    /**
     * A blank cell does NOT disqualify it.
     *
     * A column the user added to the template has no counterpart in the hidden key row.
     * Refusing the file over that would punish them for filling it in the way people do.
     */
    #[Test]
    public function aBlankCellIsSkippedRatherThanRefused(): void
    {
        self::assertSame(
            [0 => 'customerCode', 2 => 'total'],
            KeyRow::detect(['customerCode', '', 'total'], self::KEYS),
        );
    }

    /**
     * ★ The clause that is easy to leave out and expensive to leave out.
     *
     * "Every non-blank cell matched" is vacuously true of an empty row. Without this, a file
     * that merely begins with a blank line would have its first two rows eaten as headers.
     */
    #[Test]
    public function aWhollyBlankRowIsNotAKeyRow(): void
    {
        self::assertNull(KeyRow::detect(['', '', ''], self::KEYS));
        self::assertNull(KeyRow::detect([], self::KEYS));
    }

    /**
     * Case sensitive, deliberately.
     *
     * A key is a canonical identifier, not prose. A schema may carry `code` and `Code` as
     * separate fields, and folding case would conflate them. The tolerance on the label side
     * exists for the opposite reason.
     */
    #[Test]
    public function matchingIsCaseSensitive(): void
    {
        self::assertNull(KeyRow::detect(['CustomerCode', 'customerName', 'total'], self::KEYS));
    }

    #[Test]
    public function withNoKnownKeysNothingCanBeAKeyRow(): void
    {
        self::assertNull(KeyRow::detect(['customerCode'], []));
    }

    #[Test]
    public function matchesAnswersTheSameQuestion(): void
    {
        self::assertTrue(KeyRow::matches(['customerCode', 'total'], self::KEYS));
        self::assertFalse(KeyRow::matches(['Cari Kodu'], self::KEYS));
    }
}
