<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Exception\ValueException;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Value\Formatter\BoolFormatter;
use Balin\Tabula\Value\Formatter\DateFormatter;
use Balin\Tabula\Value\Formatter\EnumFormatter;
use Balin\Tabula\Value\Formatter\MoneyFormatter;
use Balin\Tabula\Value\Formatter\NumberFormatter;
use Balin\Tabula\Value\Formatter\StringFormatter;

/**
 * Tipten biçimlendiriciye eşleme.
 *
 * Aramalar tip başına önbelleklenir; satır sayısı yüz binlere çıktığında her hücrede
 * doğrusal tarama yapmak ölçülebilir bir maliyettir.
 *
 * Öndeki biçimlendirici kazanır: `with()` ile eklenen özel bir biçimlendirici,
 * yerleşik olanı ezer. (Mevcut ERP'de `normalize()` metodu 8 ayrı sınıfa kopyalanmıştı;
 * burada davranışı değiştirmek tek bir sınıf eklemekle olur.)
 */
final class FormatterRegistry
{
    /** @var list<ValueFormatter> */
    private array $formatters;

    /** @var array<string, ValueFormatter> */
    private array $cache = [];

    public function __construct(ValueFormatter ...$formatters)
    {
        $this->formatters = array_values($formatters);
    }

    /** Yerleşik biçimlendiricilerle kurulu kayıt defteri. */
    public static function default(): self
    {
        return new self(
            new StringFormatter(),
            new NumberFormatter(),
            new MoneyFormatter(),
            new BoolFormatter(),
            new DateFormatter(),
            new EnumFormatter(),
        );
    }

    /** Özel biçimlendiricileri BAŞA ekleyerek yerleşikleri ezer. */
    public function with(ValueFormatter ...$formatters): self
    {
        return new self(...array_values($formatters), ...$this->formatters);
    }

    public function for(FieldType $type): ValueFormatter
    {
        return $this->cache[$type->value] ??= $this->find($type);
    }

    private function find(FieldType $type): ValueFormatter
    {
        foreach ($this->formatters as $formatter) {
            if ($formatter->supports($type)) {
                return $formatter;
            }
        }

        throw ValueException::noFormatter($type);
    }
}
