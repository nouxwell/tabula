<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Writer;

use Closure;
use Dompdf\Dompdf;
use Nouxwell\Tabula\Exception\ExportException;
use Nouxwell\Tabula\Format;

/**
 * Produces the built-in writers with the given options.
 *
 * With `with()` you can pass your own factory for a format — e.g. your own PDF writer with the
 * company letterhead, without touching `ExportBuilder`.
 */
final class DefaultWriterFactory implements WriterFactory
{
    /** @var array<string, Closure(): Writer> */
    private array $overrides = [];

    public function __construct(
        private readonly CsvOptions $csv = new CsvOptions(),
        private readonly XlsxOptions $xlsx = new XlsxOptions(),
        private readonly PdfOptions $pdf = new PdfOptions(),
    ) {
    }

    /**
     * @param Closure(): Writer $factory must return a FRESH writer on every call
     */
    public function with(Format $format, Closure $factory): self
    {
        $clone = clone $this;
        $clone->overrides[$format->value] = $factory;

        return $clone;
    }

    public function for(Format $format): Writer
    {
        $override = $this->overrides[$format->value] ?? null;

        if (null !== $override) {
            return $override();
        }

        return match ($format) {
            Format::Xlsx => new XlsxWriter($this->xlsx),
            Format::Csv => new CsvWriter($this->csv),
            // The engine check is HERE, not inside `PdfWriter`: the writer only calls Dompdf in
            // `close()`, so a missing package would blow up as a raw `Error` after every row had
            // already been processed (see `ExportException::missingPdfEngine`).
            Format::Pdf => class_exists(Dompdf::class)
                ? new PdfWriter($this->pdf)
                : throw ExportException::missingPdfEngine(),
        };
    }
}
