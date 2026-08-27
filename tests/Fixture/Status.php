<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Fixture;

/**
 * Mevcut ERP'deki yaygın gelenegi taklit eder: enum kendi ÇEVİRİ ANAHTARINI döndüren
 * bir `label()` metodu taşır. `EnumFormatter` bu geleneği `TranslatableEnum` arayüzü
 * olmadan da tanımak zorundadır — 200'den fazla mevcut enum bu şekilde yazılmış.
 */
enum Status: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return 'status.'.$this->value;
    }
}
