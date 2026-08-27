<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Sheet;

use Balin\Tabula\Value\FormatContext;
use InvalidArgumentException;

/**
 * Her N satırda yeni sayfa.
 *
 * Excel'in 1.048.576 satır sınırını ve devasa tek sayfanın açılış yavaşlığını aşar.
 * CSV'de her parça ayrı dosya olur ve tek bir arşivde toplanır.
 */
final readonly class ChunkedSheets implements SheetStrategy
{
    public function __construct(
        private int $rowsPerSheet,
        /** `%d` parça numarasıyla değiştirilir. */
        private string $namePattern = 'Sayfa %d',
    ) {
        if ($rowsPerSheet < 1) {
            throw new InvalidArgumentException('Sayfa başına satır sayısı en az 1 olmalı.');
        }
    }

    public function sheetFor(int $rowIndex, mixed $row, FormatContext $context): string
    {
        return sprintf($this->namePattern, intdiv($rowIndex, $this->rowsPerSheet) + 1);
    }
}
