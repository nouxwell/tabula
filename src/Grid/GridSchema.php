<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Grid;

use Nouxwell\Tabula\Exception\SchemaException;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\FieldType;
use Nouxwell\Tabula\Schema\Schema;

/**
 * A schema as the browser's grid (@nouxwell/tabula-grid) works with it: the same fields that drive
 * export, import and templates, with what only a sheet being typed into needs on top.
 *
 *     $grid = GridSchema::of($schema, version: 3)
 *         ->module('product.price_update')
 *         ->lookup('productId', 'product')
 *         ->rules('newPrice', Rule::min('0'))
 *         ->rules('productId', Rule::unique())
 *         ->readOnly('sku', 'currentPrice')
 *         ->notSubmitted('sku', 'currentPrice');
 *
 *     $tabula->gridSchema($grid, 'tr');        // what the grid is given to draw the sheet
 *     $tabula->checkRows($grid, $request);     // the rows it sends, checked again here
 *
 * The version is the grid's way to tell a draft saved under an older schema: raise it whenever a
 * field is added, removed or changes type. The object is never changed: every method returns a copy.
 */
final class GridSchema
{
    private ?string $module = null;

    /** @var array<string, list<Rule>> */
    private array $rules = [];

    /** @var array<string, string> field key => the lookup's name */
    private array $lookups = [];

    /** @var array<string, true> */
    private array $readOnly = [];

    /** @var array<string, true> */
    private array $notSubmitted = [];

    private function __construct(
        private readonly Schema $schema,
        private readonly int $version,
    ) {
    }

    public static function of(Schema $schema, int $version): self
    {
        if ($version < 1) {
            throw SchemaException::invalidVersion($version);
        }

        return new self($schema, $version);
    }

    /** The module's name in the grid: requests and drafts carry it. The schema's name by default. */
    public function module(string $module): self
    {
        $clone = clone $this;
        $clone->module = $module;

        return $clone;
    }

    /**
     * Rules the field's value keeps, added to any it already has. `min` and `max` apply to a number,
     * `minLength` and `maxLength` to text, `unique` to any field.
     */
    public function rules(string $key, Rule ...$rules): self
    {
        $field = $this->schema->field($key);

        foreach ($rules as $rule) {
            $this->assertApplies($rule, $field, isset($this->lookups[$key]));
        }

        $clone = clone $this;
        $clone->rules[$key] = [...($this->rules[$key] ?? []), ...array_values($rules)];

        return $clone;
    }

    /**
     * Makes the field a lookup: a record picked by its name, which the grid keeps as `{id, label}`
     * and sends as the id. `$lookup` names the records, such as "product", for the grid's search.
     */
    public function lookup(string $key, string $lookup): self
    {
        $field = $this->schema->field($key);

        foreach ($this->rules[$key] ?? [] as $rule) {
            $this->assertApplies($rule, $field, true);
        }

        $clone = clone $this;
        $clone->lookups[$key] = $lookup;

        return $clone;
    }

    /** Fields shown but never typed into, such as a current price for reference. */
    public function readOnly(string ...$keys): self
    {
        $clone = clone $this;

        foreach ($keys as $key) {
            $this->schema->field($key);
            $clone->readOnly[$key] = true;
        }

        return $clone;
    }

    /** Fields shown in the sheet but not sent back: the grid leaves them out of what it sends. */
    public function notSubmitted(string ...$keys): self
    {
        $clone = clone $this;

        foreach ($keys as $key) {
            $this->schema->field($key);
            $clone->notSubmitted[$key] = true;
        }

        return $clone;
    }

    public function getSchema(): Schema
    {
        return $this->schema;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getModule(): string
    {
        return $this->module ?? $this->schema->getName();
    }

    /** @return list<Rule> */
    public function rulesFor(string $key): array
    {
        return $this->rules[$key] ?? [];
    }

    /** The lookup's name when the field is one; null otherwise. */
    public function lookupOf(string $key): ?string
    {
        return $this->lookups[$key] ?? null;
    }

    public function isReadOnly(string $key): bool
    {
        return isset($this->readOnly[$key]);
    }

    public function isSubmitted(string $key): bool
    {
        return !isset($this->notSubmitted[$key]);
    }

    private function assertApplies(Rule $rule, Field $field, bool $lookup): void
    {
        $type = $field->getType();
        $applies = match (true) {
            Rule::UNIQUE === $rule->kind => true,
            $lookup => false,
            $rule->isNumeric() => $type->isNumeric(),
            default => FieldType::String === $type,
        };

        if (!$applies) {
            throw SchemaException::ruleDoesNotApply($rule->kind, $field->getKey(), $lookup ? 'lookup' : $type->value);
        }
    }
}
