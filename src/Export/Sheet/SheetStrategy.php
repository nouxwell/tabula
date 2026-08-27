<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Sheet;

use Balin\Tabula\Value\FormatContext;

/**
 * Satırların sayfalara nasıl bölüneceği.
 *
 * Boru hattı her satır için `sheetFor()` çağırır ve dönen ad DEĞİŞTİĞİNDE yeni sayfa açar.
 * Bu yüzden strateji saf ve öngörülebilir olmalıdır.
 */
interface SheetStrategy
{
    /**
     * @param int   $rowIndex 0'dan başlayan genel satır sırası
     * @param mixed $row      ham satır
     */
    public function sheetFor(int $rowIndex, mixed $row, FormatContext $context): string;
}
