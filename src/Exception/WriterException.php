<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use LogicException;

/**
 * Thrown when the writer lifecycle is called out of order.
 *
 * It descends from `\LogicException` because these are not data or environment failures but
 * ordering mistakes in the calling code: open → startSheet → writeRow* → finishSheet → close.
 * The system this replaces gave its writers no such contract; a writer called in the wrong
 * order either produced an empty file silently or blew up with an inscrutable "null" error.
 */
final class WriterException extends LogicException implements TabulaException
{
    public static function notOpened(): self
    {
        return new self('The writer is not open: call open(...) first.');
    }

    public static function noActiveSheet(): self
    {
        return new self('There is no active sheet: call startSheet(...) first.');
    }

    public static function alreadyOpen(): self
    {
        return new self('The writer is already open: call close() before calling open(...) again.');
    }

    /**
     * The CSV delimiter/enclosure/escape character must be a single BYTE.
     *
     * Validation happens at configuration time, not at write time: `fputcsv()` throws a raw
     * `ValueError` in this situation — and because that is NOT a `TabulaException`, the
     * `catch (TabulaException)` block wrapping the export misses it — on top of which it
     * blows up on the header row, after the file has already been created, leaving a file on
     * disk that contains nothing but the BOM.
     *
     * The most common trigger: `escape: '\\'` in `tabula.yaml`. YAML does not process
     * backslash escapes inside a single-quoted scalar, so that value is TWO characters.
     */
    public static function csvCharacterMustBeSingleByte(string $setting, string $value, bool $emptyAllowed = false): self
    {
        return new self(sprintf(
            'The CSV "%s" setting must be a single byte%s; "%s" was given (%d bytes). '
            .'Mind the backslash in YAML: \'\\\\\' is two characters.',
            $setting,
            $emptyAllowed ? ' or empty' : '',
            $value,
            \strlen($value),
        ));
    }

    /**
     * Excel colours must be ARGB (`FFRRGGBB`) or RGB (`RRGGBB`).
     *
     * PhpSpreadsheet's `setARGB()` method SILENTLY swallows a value it cannot parse; the
     * result only becomes visible to the user once the file is opened, and it has two typical
     * shapes:
     *  - `#F2F2F2`, written out of CSS habit → the header turns pure white and it looks as if
     *    "the setting did nothing".
     *  - An empty string → the header row is printed as a PITCH-BLACK band.
     */
    public static function invalidArgbColor(string $setting, string $value): self
    {
        return new self(sprintf(
            'The Excel "%s" setting expects ARGB (FFRRGGBB or RRGGBB); "%s" was given. '
            .'Remove the leading "#" character.',
            $setting,
            $value,
        ));
    }

    /**
     * The PDF font size must be positive.
     *
     * Dompdf does NOT reject a zero or negative point size: it computes a line height of zero
     * and the table collapses into an invisible strip on the page. The user gets this back as
     * an "empty PDF" and cannot work out the cause by looking at the output — which is why we
     * stop at configuration time.
     */
    public static function invalidFontSize(float $pt): self
    {
        return new self(sprintf('The PDF font size must be positive; %s pt was given.', $pt));
    }
}
