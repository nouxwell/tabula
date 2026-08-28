<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export;

use Closure;
use Nouxwell\Tabula\Schema\Align;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Schema\Priority;
use Nouxwell\Tabula\Value\FormatContext;

/**
 * The RESOLVED column handed to the writer.
 *
 * At this point the label is no longer a translation key but the text in the requested
 * language; the alignment is no longer `Auto` but the final value derived from the type.
 * Writers never see a `Field` — they only ever see this flat data.
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

    /** Builds the column by resolving the field's label into the context's language. */
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

        // When no label was given, the key itself becomes the header — the output is never
        // left without one.
        return $context->trans($label ?? $field->getKey());
    }
}
