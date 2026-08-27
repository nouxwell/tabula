<?php

declare(strict_types=1);

namespace Balin\Tabula\Export;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Format;

/** Tamamlanmış bir dışa aktarmanın sonucu. */
final readonly class ExportResult
{
    /**
     * @param list<string> $paths   yazılan dosya yolları (CSV'de çok sayfa = çok dosya)
     * @param list<string> $columns yazılan kolon anahtarları, yazıldıkları sırayla
     */
    public function __construct(
        public array $paths,
        public int $rows,
        public int $sheets,
        public array $columns,
        public Format $format,
    ) {
    }

    /** Tek dosyalık çıktının yolu. */
    public function path(): string
    {
        return $this->paths[0] ?? throw ExportException::noOutput();
    }

    public function isMultiFile(): bool
    {
        return count($this->paths) > 1;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->rows;
    }
}
