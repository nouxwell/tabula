<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;

/**
 * Hücredeki ham değeri alanın tipine çevirir — `ValueFormatter`ın ters yönü.
 *
 * Biçimlendirici gibi HOŞGÖRÜLÜ DEĞİLDİR ve olmamalıdır: dışa aktarmada bozuk bir hücre
 * boş basılıp geçilebilir, ama içe aktarmada aynı hücre veritabanına yanlış veri yazmak
 * demektir. Ayrıştırılamayan değer `ParseException` fırlatır; içe aktarma döngüsü onu
 * yakalayıp satır/alan bilgisiyle bir `RowError`e dönüştürür ve akış devam eder.
 */
interface ValueParser
{
    public function supports(FieldType $type): bool;

    /**
     * @param mixed $raw okuyucudan geldiği hâliyle hücre (dize, sayı, DateTime, null…)
     *
     * @throws ParseException değer bu alana yazılamıyorsa
     */
    public function parse(mixed $raw, Field $field, ParseContext $context): mixed;
}
