<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Writer;

use Nouxwell\Tabula\Format;

/**
 * Produces a writer for a given format.
 *
 * `ExportBuilder` asks for the writer here instead of `new`ing it directly; that way writer
 * settings such as the delimiter or the BOM can come from the application's configuration and
 * nobody has to write `->writer(new CsvWriter(...))` by hand at every call site.
 *
 * IT MUST RETURN A FRESH writer ON EVERY CALL: writers carry state (an open file handle, the
 * active sheet) and a shared instance would write into the other's file during two concurrent
 * exports.
 */
interface WriterFactory
{
    public function for(Format $format): Writer;
}
