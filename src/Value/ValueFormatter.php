<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;

/**
 * Ham değeri hücreye çeviren birim.
 *
 * Her tip için bir uygulama vardır; kayıt defteri `supports()` ile eşleştirir.
 * Yeni bir tip eklemek = yeni bir biçimlendirici yazmak (mevcut kodda 8 ayrı yerde
 * kopyalanmış `normalize()` metotları vardı).
 */
interface ValueFormatter
{
    public function supports(FieldType $type): bool;

    /**
     * @param mixed $raw satırdan okunmuş ham değer
     * @param mixed $row alanın bağlamı (para birimi gibi satırdan türeyen ayarlar için)
     */
    public function format(mixed $raw, Field $field, mixed $row, FormatContext $context): Cell;
}
