<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Sheet;

use Balin\Tabula\Value\FormatContext;
use InvalidArgumentException;

/**
 * Her şey tek sayfada — varsayılan davranış.
 *
 * Tek istisna taşma korumasıdır: `$maxRows` verildiğinde (ki `ExportBuilder` bunu
 * `TabulaSettings::$maxRowsPerSheet` değerinden geçirir) sayfa dolunca `Ad (2)`, `Ad (3)`
 * diye devam eder. Excel bir sayfaya en fazla 1.048.576 satır alır ve bu sınır aşıldığında
 * yazıcı hata vermeden BOZUK bir dosya üretebilir — sessiz taşma yerine yeni sayfa.
 */
final readonly class SingleSheet implements SheetStrategy
{
    public function __construct(
        private string $name = 'Sheet1',
        private ?int $maxRows = null,
    ) {
        if (null !== $maxRows && $maxRows < 1) {
            throw new InvalidArgumentException('Sayfa başına satır sayısı en az 1 olmalı.');
        }
    }

    public function sheetFor(int $rowIndex, mixed $row, FormatContext $context): string
    {
        if (null === $this->maxRows || $rowIndex < $this->maxRows) {
            return $this->name;
        }

        return sprintf('%s (%d)', $this->name, intdiv($rowIndex, $this->maxRows) + 1);
    }
}
