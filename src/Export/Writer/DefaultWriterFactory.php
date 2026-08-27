<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Format;
use Closure;

/**
 * Yerleşik yazıcıları verilen ayarlarla üretir.
 *
 * `with()` ile bir biçim için kendi üreticini geçirebilirsin — PDF yazıcısı (Faz 3) de
 * buraya bu şekilde takılacak, `ExportBuilder`e dokunmadan.
 */
final class DefaultWriterFactory implements WriterFactory
{
    /** @var array<string, Closure(): Writer> */
    private array $overrides = [];

    public function __construct(
        private readonly CsvOptions $csv = new CsvOptions(),
        private readonly XlsxOptions $xlsx = new XlsxOptions(),
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
            Format::Pdf => throw ExportException::unsupportedFormat($format),
        };
    }
}
