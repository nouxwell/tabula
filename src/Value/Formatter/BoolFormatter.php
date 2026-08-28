<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value\Formatter;

use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Value\Cell;
use Nouxwell\Tabula\Value\FormatContext;
use Nouxwell\Tabula\Value\ValueFormatter;

/**
 * Boolean fields.
 *
 * The value written into the cell is NOT a raw `true/false` but the translated text
 * (Yes/No). The reason is Excel: if a real boolean is written, the cell shows TRUE/DOĞRU
 * according to Excel's own language and no longer matches the drop-down list in the
 * template.
 *
 * That was exactly the bug in the system this replaces: it produced the cell text
 * from one translation family (`general.yes`) and the template's validation list from
 * ANOTHER one (`form.true`); the value it wrote was therefore not in its own allow-list, and
 * Excel opened the file saying it "needs to be repaired". Here there is a single source:
 * `TabulaSettings::$boolTrueKey` / `$boolFalseKey` — the template generator reads the very
 * same pair.
 *
 * The input side is deliberately wide; `1` can arrive from the database, `'true'` from a
 * CSV, and `'Evet'` from a template a Turkish user filled in.
 */
final class BoolFormatter implements ValueFormatter
{
    public function supports(FieldType $type): bool
    {
        return FieldType::Bool === $type;
    }

    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell
    {
        $align = $field->getAlign();

        $formatter = $field->getFormatter();
        if (null !== $formatter) {
            return Cell::text((string) $formatter($raw, $row), $align);
        }

        $value = $this->toBool($raw);

        // An unrecognised value is an empty cell too: turning "unknown" into a made-up No
        // would hand false information to whoever reads the export.
        if (null === $value) {
            return Cell::empty($context->settings->emptyText, $align);
        }

        $settings = $context->settings;
        $text = $context->trans($value ? $settings->boolTrueKey : $settings->boolFalseKey);

        // Cell::text → value === text: the same text in Excel and in CSV/PDF alike.
        return Cell::text($text, $align);
    }

    /** `null` (= an empty cell) for anything unrecognised or empty. */
    private function toBool(mixed $raw): ?bool
    {
        if (null === $raw) {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        // Doctrine tinyint(1), SQL COUNTs and JSON APIs all produce 0/1.
        if (is_int($raw)) {
            return 0 !== $raw;
        }

        if (is_float($raw)) {
            return 0.0 !== $raw;
        }

        if (!is_string($raw)) {
            return null;
        }

        // On import the user writes in their own language; the Turkish forms are recognised too.
        return match (mb_strtolower(trim($raw), 'UTF-8')) {
            '1', 'true', 'yes', 'on', 'y', 't', 'evet', 'e' => true,
            '0', 'false', 'no', 'off', 'n', 'f', 'hayır', 'hayir', 'h' => false,
            default => null,
        };
    }
}
