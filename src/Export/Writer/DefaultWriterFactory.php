<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Format;
use Closure;
use Dompdf\Dompdf;

/**
 * Yerleşik yazıcıları verilen ayarlarla üretir.
 *
 * `with()` ile bir biçim için kendi üreticini geçirebilirsin — ör. şirket antetli kendi PDF
 * yazıcın, `ExportBuilder`e dokunmadan.
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
     * @param Closure(): Writer $factory her çağrıda TAZE yazıcı döndürmeli
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
            // Motor kontrolü BURADA, `PdfWriter`ın içinde değil: yazıcı Dompdf'i ancak
            // `close()`ta çağırır, yani eksik paket tüm satırlar işlendikten sonra ham bir
            // `Error` olarak patlardı (bkz. `ExportException::missingPdfEngine`).
            Format::Pdf => class_exists(Dompdf::class)
                ? new PdfWriter($this->pdf)
                : throw ExportException::missingPdfEngine(),
        };
    }
}
