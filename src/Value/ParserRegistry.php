<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Value\Parser\BoolParser;
use Balin\Tabula\Value\Parser\DateParser;
use Balin\Tabula\Value\Parser\EnumParser;
use Balin\Tabula\Value\Parser\MoneyParser;
use Balin\Tabula\Value\Parser\NumberParser;
use Balin\Tabula\Value\Parser\StringParser;

/**
 * Tipten ayrıştırıcıya eşleme — `FormatterRegistry`nin ters yöndeki ikizi.
 *
 * Aramalar tip başına önbelleklenir; bir içe aktarma dosyasında on binlerce hücre vardır
 * ve her hücrede doğrusal tarama yapmak ölçülebilir bir maliyettir.
 *
 * Öndeki ayrıştırıcı kazanır: `with()` ile eklenen özel bir ayrıştırıcı, yerleşik olanı
 * ezer. Böylece bir projenin kendi tarih lehçesini tanıtması, kütüphaneyi çatallamak
 * değil, tek bir sınıf yazmaktır.
 */
final class ParserRegistry
{
    /** @var list<ValueParser> */
    private array $parsers;

    /** @var array<string, ValueParser> */
    private array $cache = [];

    public function __construct(ValueParser ...$parsers)
    {
        $this->parsers = array_values($parsers);
    }

    /** Yerleşik ayrıştırıcılarla kurulu kayıt defteri. */
    public static function default(): self
    {
        return new self(
            new StringParser(),
            new NumberParser(),
            new MoneyParser(),
            new BoolParser(),
            new DateParser(),
            new EnumParser(),
        );
    }

    /** Özel ayrıştırıcıları BAŞA ekleyerek yerleşikleri ezer. */
    public function with(ValueParser ...$parsers): self
    {
        return new self(...array_values($parsers), ...$this->parsers);
    }

    /**
     * Alanın tipine karşılık gelen ayrıştırıcı.
     *
     * Biçimlendirici ikizi `FieldType` alır; bu taraf ALANIN kendisini alır, çünkü
     * ayrıştırıcı bulunamadığında fırlatılan `ParseException::noParser()` alanı ister
     * (hata mesajının hangi kolondan bahsettiği kullanıcı için tek anlamlı bilgidir).
     * Önbellek yine TİP başınadır; alan başına önbelleklemek aynı tipteki yüzlerce alan
     * için bellekte gereksiz kopya tutardı.
     */
    public function for(Field $field): ValueParser
    {
        return $this->cache[$field->getType()->value] ??= $this->find($field);
    }

    private function find(Field $field): ValueParser
    {
        $type = $field->getType();

        foreach ($this->parsers as $parser) {
            if ($parser->supports($type)) {
                return $parser;
            }
        }

        throw ParseException::noParser($field);
    }
}
