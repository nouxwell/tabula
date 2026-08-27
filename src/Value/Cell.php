<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Schema\Align;

/**
 * Biçimlendirilmiş tek hücre.
 *
 * İki gösterimi birden taşır ve yazıcı hangisini kullanacağına kendi karar verir:
 *  - `value`  → Excel'e yazılan GERÇEK değer (float/int/bool/string), `numberFormat` ile birlikte.
 *               Böylece hücre Excel'de sayı olarak kalır; toplanabilir, sıralanabilir.
 *  - `text`   → CSV ve PDF'in yazdığı, yerelleştirilmiş görünen metin.
 *
 * Mevcut ERP'de bu ayrım yoktu: Excel sayı yazarken PDF ve Twig önceden biçimlenmiş
 * dize yazıyor, CSV ise ondalıkları hiç biçimlendirmiyordu.
 */
final readonly class Cell
{
    public function __construct(
        public mixed $value,
        public string $text,
        public ?string $numberFormat = null,
        public Align $align = Align::Left,
    ) {
    }

    public static function text(string $text, Align $align = Align::Left): self
    {
        return new self($text, $text, null, $align);
    }

    public static function number(int|float $value, string $text, ?string $numberFormat = null, Align $align = Align::Right): self
    {
        return new self($value, $text, $numberFormat, $align);
    }

    public static function empty(string $text = '', Align $align = Align::Left): self
    {
        return new self(null, $text, null, $align);
    }

    public function isEmpty(): bool
    {
        return null === $this->value && '' === $this->text;
    }
}
