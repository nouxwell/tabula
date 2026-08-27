<?php

declare(strict_types=1);

namespace Balin\Tabula\Export;

use Balin\Tabula\Schema\Align;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Schema\Priority;
use Balin\Tabula\Value\FormatContext;
use Closure;

/**
 * Yazıcıya verilen ÇÖZÜLMÜŞ kolon.
 *
 * Etiket bu noktada artık bir çeviri anahtarı değil, istenen dildeki metindir;
 * hizalama `Auto` değil, tipten türetilmiş nihai değerdir. Yazıcılar `Field` görmez —
 * yalnız bu düz veriyi görür.
 */
final readonly class Column
{
    public function __construct(
        public string $key,
        public string $label,
        public FieldType $type,
        public Align $align,
        public ?int $width,
        public bool $required,
        public Priority $priority,
    ) {
    }

    /** Alanın etiketini bağlamdaki dile çözerek kolonu üretir. */
    public static function fromField(Field $field, FormatContext $context): self
    {
        return new self(
            key: $field->getKey(),
            label: self::resolveLabel($field, $context),
            type: $field->getType(),
            align: $field->getAlign(),
            width: $field->getWidth(),
            required: $field->isRequired(),
            priority: $field->getPriority(),
        );
    }

    private static function resolveLabel(Field $field, FormatContext $context): string
    {
        $label = $field->getLabel();

        if ($label instanceof Closure) {
            return (string) $label($context->locale);
        }

        // Etiket verilmemişse anahtarın kendisi başlık olur — çıktı asla başlıksız kalmaz.
        return $context->trans($label ?? $field->getKey());
    }
}
