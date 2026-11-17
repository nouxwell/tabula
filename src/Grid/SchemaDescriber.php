<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Grid;

use BackedEnum;
use Closure;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Port\Translator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Value\FormatContext;
use Nouxwell\Tabula\Value\FormatterRegistry;
use UnitEnum;

/**
 * Writes a `GridSchema` as the module schema the browser's grid reads (`ModuleSchema.from`).
 *
 * Labels, the title and the labels of options are translated as a template translates them, in the
 * language asked for, so the sheet and the template say the same. The grid refuses a property it
 * does not know rather than skipping it, so only what it takes for a field's type is written: a
 * money field's places and currency, a date's pattern, an options field's options.
 *
 * A currency or an options list that depends on the row (a closure) has no single answer for a
 * whole column: a currency closure is left out, and an options closure is called with a null row,
 * as a template calls it.
 */
final class SchemaDescriber
{
    public function __construct(
        private readonly Translator $translator,
        private readonly TabulaSettings $settings,
        private readonly FormatterRegistry $formatters,
    ) {
    }

    /** @return array<string, mixed> ready for `json_encode` */
    public function describe(GridSchema $grid, string $locale): array
    {
        $context = new FormatContext($locale, $this->translator, $this->settings, Format::Xlsx);
        $schema = $grid->getSchema();
        $json = ['module' => $grid->getModule(), 'schemaVersion' => $grid->getVersion()];
        $title = $schema->getTitle();

        if (null !== $title) {
            $json['title'] = $title instanceof Closure ? (string) $title($locale) : $context->trans($title);
        }

        $json['fields'] = array_values(array_map(
            fn (Field $field): array => $this->field($grid, $field, $context),
            $schema->getFields(),
        ));

        return $json;
    }

    /** @return array<string, mixed> */
    private function field(GridSchema $grid, Field $field, FormatContext $context): array
    {
        $key = $field->getKey();
        $lookup = $grid->lookupOf($key);
        $type = null !== $lookup ? 'lookup' : match ($field->getType()) {
            FieldType::Enum, FieldType::Options => 'options',
            default => $field->getType()->value,
        };

        $json = [
            'key' => $key,
            'type' => $type,
            'label' => $this->label($field, $context),
            'required' => $field->isRequired(),
            'editable' => !$grid->isReadOnly($key),
            'submit' => $grid->isSubmitted($key),
        ];

        $rules = $grid->rulesFor($key);

        if ([] !== $rules) {
            $json['rules'] = array_map(static fn (Rule $rule): array => $rule->toArray(), $rules);
        }

        $example = $field->getExample();

        if (\is_string($example)) {
            $json['example'] = $context->trans($example);
        } elseif (\is_int($example) || \is_float($example)) {
            $json['example'] = Decimal::normalize($example) ?? (string) $example;
        }

        switch ($type) {
            case 'lookup':
                $json['lookup'] = $lookup;
                break;
            case 'decimal':
            case 'quantity':
                if (null !== $field->getDecimals()) {
                    $json['decimals'] = $field->getDecimals();
                }
                break;
            case 'money':
                if (null !== $field->getDecimals()) {
                    $json['decimals'] = $field->getDecimals();
                }

                if (\is_string($field->getCurrency())) {
                    $json['currency'] = $field->getCurrency();
                }
                break;
            case 'date':
            case 'datetime':
                if (null !== $field->getPattern()) {
                    $json['pattern'] = $field->getPattern();
                }
                break;
            case 'options':
                $json['options'] = $this->options($field, $context);
                break;
        }

        return $json;
    }

    private function label(Field $field, FormatContext $context): string
    {
        $label = $field->getLabel();

        if ($label instanceof Closure) {
            return (string) $label($context->locale);
        }

        // No label: the key, as a template's header falls back to it.
        return $context->trans($label ?? $field->getKey());
    }

    /**
     * Each option's value and its label, the label as the template's drop-down list writes it: the
     * same formatter the export uses, so the three can never say different things.
     *
     * @return list<array{value: int|string, label: string}>
     */
    private function options(Field $field, FormatContext $context): array
    {
        $formatter = $this->formatters->for($field->getType());
        $options = [];

        foreach ($this->choices($field) as [$value, $raw]) {
            $options[] = ['value' => $value, 'label' => $formatter->format($raw, $field, null, $context)->text];
        }

        return $options;
    }

    /**
     * The value the grid keeps, with what the formatter is given for it.
     *
     * @return list<array{int|string, mixed}>
     */
    private function choices(Field $field): array
    {
        if (FieldType::Enum === $field->getType()) {
            $class = $field->getEnumClass();

            if (null === $class || !enum_exists($class)) {
                return [];
            }

            return array_map(
                static fn (UnitEnum $case): array => [$case instanceof BackedEnum ? $case->value : $case->name, $case],
                $class::cases(),
            );
        }

        $options = $field->getOptions();

        if ($options instanceof Closure) {
            $options = $options(null);
        }

        return \is_array($options) ? array_map(static fn (int|string $key): array => [$key, $key], array_keys($options)) : [];
    }
}
