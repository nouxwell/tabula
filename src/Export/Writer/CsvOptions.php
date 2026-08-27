<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\WriterException;

/**
 * The options of the CSV writer.
 *
 * There is no single CSV default; there are two distinct audiences and what they want is the exact
 * opposite of each other:
 *
 *  - A HUMAN opens the file in a Turkish Excel → a `;` delimiter + a UTF-8 BOM are mandatory.
 *  - A MACHINE reads the file from a script → RFC 4180 (`,` delimiter, no BOM, escaping off).
 *
 * That is why the options are picked from named constructors; the caller does not have to line up
 * five scalars in the right order by hand.
 */
final readonly class CsvOptions
{
    /**
     * @param string $delimiter  Defaults to ';' — in the Turkish/European locale Excel uses ',' as
     *                           the decimal separator, so in a comma-delimited file "1.234,56" is
     *                           split across two columns.
     * @param string $escape     PHP's non-standard escaping. Passing '' turns it off and the output
     *                           conforms to RFC 4180 exactly (this will be the default in PHP 9 too).
     * @param bool   $writeBom   Without the BOM, Excel takes the file for cp1254 and ş/ğ/ı/İ/ö/ç/ü get mangled
     * @param string $lineEnding CRLF by default: both what RFC 4180 mandates and what Windows Excel
     *                           opens without trouble
     */
    public function __construct(
        public string $delimiter = ';',
        public string $enclosure = '"',
        public string $escape = '\\',
        public bool $writeBom = true,
        public string $lineEnding = "\r\n",
    ) {
        // Validate at CONSTRUCTION time, not at write time. `fputcsv()` throws a raw `ValueError`
        // on a multi-byte delimiter; that exception is not a `TabulaException`, and because it
        // blows up AFTER the file has been created it leaves behind a leftover on disk containing
        // nothing but the BOM.
        // The measure is `strlen` (BYTES), not `mb_strlen`: `fputcsv()` wants a single byte, which
        // means 'ş' is invalid.
        if (1 !== \strlen($delimiter)) {
            throw WriterException::csvCharacterMustBeSingleByte('delimiter', $delimiter);
        }

        if (1 !== \strlen($enclosure)) {
            throw WriterException::csvCharacterMustBeSingleByte('enclosure', $enclosure);
        }

        // The escape may DELIBERATELY be empty: passing '' turns off PHP's non-standard escaping.
        if ('' !== $escape && 1 !== \strlen($escape)) {
            throw WriterException::csvCharacterMustBeSingleByte('escape', $escape, emptyAllowed: true);
        }
    }

    /** For double-clicking the file open in a Turkish/European Excel — the default. */
    public static function excel(): self
    {
        return new self();
    }

    /** A feed going to a machine: RFC 4180, no BOM, escaping off. */
    public static function rfc4180(): self
    {
        return new self(
            delimiter: ',',
            enclosure: '"',
            escape: '',
            writeBom: false,
            lineEnding: "\r\n",
        );
    }
}
