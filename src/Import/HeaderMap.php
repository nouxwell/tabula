<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Import;

use Closure;
use Nouxwell\Tabula\Exception\ImportException;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Value\ParseContext;
use Nouxwell\Tabula\Value\Parser\StringParser;

/**
 * Binds the file's headers to the schema's fields — THIS IS WHERE THE FLAW OF THE SYSTEM THIS
 * REPLACES IS REPAIRED.
 *
 * ★ TEMPLATE LAYOUT (see `TemplateBuilder`):
 *
 *     row 1 → CANONICAL FIELD KEYS (hidden in xlsx, visible but harmless in csv)
 *     row 2 → translated labels (what the user reads)
 *     row 3 → data
 *
 * NO MARKER WHATSOEVER IS LOOKED FOR in the file to reach the decision: if EVERY non-blank
 * cell in row 1 lands on a key in the schema, that row is the key row and the data starts at
 * row 3; if it does not, row 1 is the label header and the data starts at row 2. No hidden
 * version number, signature cell or file-name convention is needed — none of them would have
 * survived a user hitting "save as".
 *
 * In the system this replaces the file's identity was the TRANSLATED HEADER STRING ("Customer
 * Code"). The consequences: a single word changed in a translation file silently made every
 * template users had on disk unreadable, and a file with English headers never matched at all
 * in a Turkish session. Here the identity is the KEY; the label is only a fallback route for
 * legacy files.
 *
 * The class is PURE and independently testable: it opens no file and reads no rows, it only
 * compares two header rows against the schema.
 */
final readonly class HeaderMap
{
    /** In the key-row layout the data starts at row 3 (1: keys, 2: labels). */
    private const int KEY_ROW_DATA_START = 3;

    /** With no key row, row 1 is the header and the data starts at row 2. */
    private const int LABEL_ROW_DATA_START = 2;

    /**
     * @param array<int, Field> $fields       column index => field, in FILE order
     * @param int               $firstDataRow the user-visible number of the first data row
     * @param bool              $usedKeyRow   whether the match was made with canonical keys
     * @param list<string>      $ignored      headers with no counterpart in the schema (or repeated ones)
     */
    private function __construct(
        public array $fields,
        public int $firstDataRow,
        public bool $usedKeyRow,
        public array $ignored,
    ) {
    }

    /**
     * Derives the mapping from the first two rows of the file.
     *
     * TWO rows are asked for because in the key-row layout row 2 is the HUMAN NAME of the
     * columns that did not match: when a user adds their own "Notes" column to the template,
     * row 1 of that column is empty, and a report saying "ignored header: ''" tells nobody
     * which column it is talking about. Row 2 TAKES NO PART in the matching decision, it only
     * feeds the report.
     *
     * @param list<mixed> $firstRow  row 1 of the file, cells exactly as they came
     * @param list<mixed> $secondRow row 2 of the file; an empty array if there is none
     *
     * @throws ImportException when the key row is mandatory but missing, or when no column matches at all
     */
    public static function resolve(
        array $firstRow,
        array $secondRow,
        Schema $schema,
        MatchStrategy $strategy,
        ParseContext $context,
    ): self {
        // `Label` NEVER LOOKS for a key row: it exists for backward compatibility with old,
        // hand-made files, and there row 1 is always the label.
        $keys = MatchStrategy::Label === $strategy ? null : self::detectKeyRow($firstRow, $schema);

        // In machine-to-machine flows, falling back to the label is a SILENT loss: when the
        // translation changes, the feed looks as if it were still working while it reads the
        // wrong column. If `Key` was asked for, either there is a key row or the file is rejected.
        if (MatchStrategy::Key === $strategy && null === $keys) {
            throw ImportException::keyRowMissing();
        }

        return null === $keys
            ? self::byLabel($firstRow, $schema, $context)
            : self::byKey($keys, $firstRow, $secondRow, $schema, $context);
    }

    /**
     * The field keys recognised in the file, in FILE order.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return array_values(array_map(
            static fn (Field $field): string => $field->getKey(),
            $this->fields,
        ));
    }

    // ---------------------------------------------------------------- key row

    /**
     * Is row 1 a key row? If so, the column index => key mapping.
     *
     * @param list<mixed> $row
     *
     * @return array<int, string>|null null = not a key row
     */
    private static function detectKeyRow(array $row, Schema $schema): ?array
    {
        $keys = [];

        foreach ($row as $index => $cell) {
            // A blank cell DOES NOT BREAK the decision: the extra column a user added to the
            // template has no counterpart in the hidden key row, and that must not stop the
            // file from being a template. That column later ends up in `ignored`.
            if (StringParser::isBlank($cell)) {
                continue;
            }

            $text = StringParser::describe($cell);

            // ★ Key matching is CASE SENSITIVE. A key is a canonical identifier, not a text a
            // human reads; a schema may well carry a `code` and a `Code` field separately, and
            // folding case would conflate the two. The tolerance on the label side (below)
            // exists for exactly the opposite reason.
            if (!$schema->has($text)) {
                return null;
            }

            $keys[$index] = $text;
        }

        // A completely empty row 1 satisfies "every non-blank cell matched" vacuously. Taking
        // it for a key row would mean mistaking the first two rows of the file for headers and
        // swallowing REAL DATA.
        return [] === $keys ? null : $keys;
    }

    /**
     * @param array<int, string> $keys     column index => canonical key
     * @param list<mixed>        $keyRow   the whole of row 1 — the file's width comes from here
     * @param list<mixed>        $labelRow row 2 — the human name of the ignored columns
     */
    private static function byKey(array $keys, array $keyRow, array $labelRow, Schema $schema, ParseContext $context): self
    {
        $fields = [];
        $ignored = [];
        $taken = [];

        $width = max(\count($keyRow), \count($labelRow));

        for ($index = 0; $index < $width; ++$index) {
            $key = $keys[$index] ?? null;

            if (null === $key) {
                // A column with no counterpart in the key row: a column the user added by
                // hand. Its identity is read from row 2, that is, from the label they SEE.
                $label = self::textAt($labelRow, $index);

                if ('' !== $label) {
                    $ignored[] = $label;
                }

                continue;
            }

            // A second header pointing at the same field: the FIRST one wins. Binding to the
            // second would mean silently throwing away the data the user typed into the first
            // column.
            if (isset($taken[$key])) {
                $ignored[] = $key;

                continue;
            }

            $taken[$key] = true;
            $fields[$index] = $schema->field($key);
        }

        // `$fields` cannot be empty: `detectKeyRow()` does not declare a key row without
        // finding at least one matching key, and the first key is always taken.
        return new self($fields, self::dataStartAfterKeyRow($fields, $keyRow, $labelRow, $context), true, $ignored);
    }

    /**
     * AFTER a key row, which row does the data start at?
     *
     * ★ THIS CHECK CLOSES A SILENT DATA LOSS. Had the rule been applied unconditionally
     * ("if there is a key row, the data starts at row 3"), the following file would have
     * eaten its first record:
     *
     *     code;name        ← the SINGLE header row written by `export()`
     *     A-1;One          ← DATA — taken for the "label row" and skipped
     *     A-2;Two
     *
     * Because a field's label may be IDENTICAL to its key: if `->label()` was never given,
     * `Column::fromField()` writes the key into the header (and with no translator it stays
     * as it is). In that case row 1 reads as both a key row and a label row — and reading an
     * exported file back would swallow the first row every single time. Eliminating exactly
     * this class of bug is the reason this library exists.
     *
     * The decision is put to ROW 2: if the label is identical to the key, then in a genuine
     * template rows 1 and 2 are CHARACTER FOR CHARACTER the same (`TemplateBuilder` writes
     * both). If they are not the same, row 2 is data.
     *
     * Where there is no ambiguity (the labels differ from the keys — that is, in practically
     * every real schema) this method returns at the first `if` and nothing changes.
     *
     * @param array<int, Field> $fields
     * @param list<mixed>       $keyRow
     * @param list<mixed>       $labelRow
     */
    private static function dataStartAfterKeyRow(array $fields, array $keyRow, array $labelRow, ParseContext $context): int
    {
        foreach ($fields as $index => $field) {
            // If even a single column's label differs from its key, row 1 CANNOT BE READ as a
            // label row; there is no ambiguity, the layout is the template itself.
            if (self::fold(self::labelOf($field, $context)) !== self::foldedAt($keyRow, $index)) {
                return self::KEY_ROW_DATA_START;
            }
        }

        foreach (array_keys($fields) as $index) {
            if (self::foldedAt($labelRow, $index) !== self::foldedAt($keyRow, $index)) {
                return self::LABEL_ROW_DATA_START;
            }
        }

        return self::KEY_ROW_DATA_START;
    }

    // ---------------------------------------------------------------- label row

    /**
     * Matches row 1 by TRANSLATED LABELS — the fallback route for legacy files.
     *
     * The labels are produced from the SCHEMA, not from the file, and are resolved by exactly
     * the same rule as `Column::fromField()` (closure → call it, string → translate it, none
     * at all → the key itself). Producing two different labels in two places would have meant
     * the template's header and the header the import expects drifting apart — in the system
     * this replaces, the export read from one translation family and the import from another.
     *
     * @param list<mixed> $headerRow
     */
    private static function byLabel(array $headerRow, Schema $schema, ParseContext $context): self
    {
        /** @var array<string, Field> $byLabel */
        $byLabel = [];
        /** @var list<string> $expected */
        $expected = [];

        foreach ($schema->getFields() as $field) {
            $label = self::labelOf($field, $context);
            $expected[] = $label;

            // The first field wins: if two fields translate to the same text (e.g. both to
            // "Amount"), the second can never match. Silently binding to the second would mean
            // writing what the user typed into the first column into a different field.
            $byLabel[self::fold($label)] ??= $field;
        }

        $fields = [];
        $ignored = [];
        $found = [];
        $taken = [];

        foreach ($headerRow as $index => $cell) {
            if (StringParser::isBlank($cell)) {
                continue;
            }

            $text = StringParser::describe($cell);
            $found[] = $text;

            $field = $byLabel[self::fold($text)] ?? null;

            if (null === $field || isset($taken[$field->getKey()])) {
                $ignored[] = $text;

                continue;
            }

            $taken[$field->getKey()] = true;
            $fields[$index] = $field;
        }

        // If not a single column stuck, the file is unusable for this schema. Returning an
        // empty result ("0 rows imported") would have made the user think the file had been
        // accepted; the message lists both what was found and what was expected.
        if ([] === $fields) {
            throw ImportException::noMatchingColumns($found, $expected);
        }

        return new self($fields, self::LABEL_ROW_DATA_START, false, $ignored);
    }

    /** The SAME label resolution as `Column::fromField()` — the two cannot drift apart. */
    private static function labelOf(Field $field, ParseContext $context): string
    {
        $label = $field->getLabel();

        if ($label instanceof Closure) {
            return (string) $label($context->locale);
        }

        // If no label was given, the key itself becomes the header — a column is never left headerless.
        return $context->trans($label ?? $field->getKey());
    }

    /**
     * The comparison form of a label: stripped of invisible whitespace, trimmed, case folded.
     *
     * `mb_strtolower()` IS LOCALE-INDEPENDENT, and here that is not a defect but exactly the
     * property we are after: since both sides go through the same function, "AMOUNT" meets
     * "Amount". A locale-sensitive fold would turn the letter "I" into "ı" one day and "i" the
     * next depending on the server's language, and the same file would match differently in
     * two environments.
     */
    private static function fold(string $text): string
    {
        return mb_strtolower(StringParser::clean($text));
    }

    /**
     * The cleaned text of the cell in the row; an empty string if the cell is missing or blank.
     *
     * @param list<mixed> $row
     */
    private static function textAt(array $row, int $index): string
    {
        $cell = $row[$index] ?? null;

        return StringParser::isBlank($cell) ? '' : StringParser::describe($cell);
    }

    /**
     * `textAt()` in its comparison-ready form.
     *
     * @param list<mixed> $row
     */
    private static function foldedAt(array $row, int $index): string
    {
        return self::fold(self::textAt($row, $index));
    }
}
