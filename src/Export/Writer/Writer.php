<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Export\Column;
use Balin\Tabula\Value\Cell;

/**
 * Dosyaya yazan birim.
 *
 * Yaşam döngüsü kesin olarak şudur:
 *
 *     open(path)
 *       startSheet(name, columns)
 *         writeRow(cells) ×N
 *       finishSheet()
 *       ... (birden çok sayfa)
 *     close() → yazılan dosya yolları
 *
 * Yazıcı satırları BİRİKTİRMEMELİDİR; her `writeRow()` çağrısı mümkün olduğunca
 * çıktıya doğru ilerlemelidir. `close()` bir liste döndürür çünkü CSV'de çok sayfalı
 * çıktı birden fazla dosya demektir.
 */
interface Writer
{
    public function open(string $path): void;

    /**
     * @param list<Column> $columns
     */
    public function startSheet(string $name, array $columns): void;

    /**
     * @param list<Cell> $cells kolonlarla aynı sırada
     */
    public function writeRow(array $cells): void;

    public function finishSheet(): void;

    /**
     * @return list<string> yazılan dosya yolları
     */
    public function close(): array;
}
