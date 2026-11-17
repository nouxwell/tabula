<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Grid;

use Nouxwell\Tabula\Exception\SchemaException;
use Nouxwell\Tabula\Grid\Decimal;
use Nouxwell\Tabula\Grid\GridSchema;
use Nouxwell\Tabula\Grid\Rule;
use Nouxwell\Tabula\Grid\SchemaDescriber;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Tests\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The grid draws its sheet from what `gridSchema()` writes, and refuses anything it does not know:
 * a property, a rule, a type. So what is written here is checked property by property, in the
 * shape that `ModuleSchema.from()` in the grid's core package reads.
 */
#[CoversClass(GridSchema::class)]
#[CoversClass(Rule::class)]
#[CoversClass(SchemaDescriber::class)]
#[CoversClass(Decimal::class)]
final class GridSchemaTest extends TestCase
{
    private static function schema(): Schema
    {
        return Schema::make('product.price_update')
            ->title('grid.title')
            ->fields(
                Field::integer('productId')->label('grid.product')->required(),
                Field::string('sku')->label('SKU'),
                Field::money('currentPrice')->label('grid.current')->decimals(2)->currency('TRY'),
                Field::money('newPrice')->label('grid.new')->decimals(2)->required()->example(1250.5),
                Field::options('currency', ['TRY' => 'currency.try', 'EUR' => 'currency.eur'])->label('grid.currency'),
                Field::enum('status', Status::class)->label('grid.status'),
                Field::date('validFrom')->label('grid.valid')->pattern('d.m.Y'),
                Field::bool('active'),
                Field::string('note')->example('grid.note.example'),
            );
    }

    private static function grid(): GridSchema
    {
        return GridSchema::of(self::schema(), 3)
            ->lookup('productId', 'product')
            ->rules('productId', Rule::unique())
            ->rules('newPrice', Rule::min('0'), Rule::max(100000))
            ->rules('note', Rule::maxLength(60))
            ->readOnly('sku', 'currentPrice')
            ->notSubmitted('sku', 'currentPrice');
    }

    private static function tabula(): Tabula
    {
        return new Tabula(new ArrayTranslator([
            'tr' => [
                'grid.title' => 'Toplu fiyat güncelleme',
                'grid.product' => 'Ürün',
                'grid.current' => 'Mevcut fiyat',
                'grid.new' => 'Yeni fiyat',
                'grid.currency' => 'Para birimi',
                'grid.status' => 'Durum',
                'grid.valid' => 'Geçerlilik',
                'grid.note.example' => 'Kampanya sonrası',
                'currency.try' => 'Türk lirası',
                'currency.eur' => 'Avro',
                'status.open' => 'Açık',
                'status.closed' => 'Kapalı',
            ],
        ]));
    }

    #[Test]
    public function itWritesTheSchemaAsTheGridReadsItTranslated(): void
    {
        $json = self::tabula()->gridSchema(self::grid(), 'tr');

        self::assertSame('product.price_update', $json['module']);
        self::assertSame(3, $json['schemaVersion']);
        self::assertSame('Toplu fiyat güncelleme', $json['title']);
        self::assertSame([
            [
                'key' => 'productId',
                'type' => 'lookup',
                'label' => 'Ürün',
                'required' => true,
                'editable' => true,
                'submit' => true,
                'rules' => [['rule' => 'unique']],
                'lookup' => 'product',
            ],
            ['key' => 'sku', 'type' => 'string', 'label' => 'SKU', 'required' => false, 'editable' => false, 'submit' => false],
            [
                'key' => 'currentPrice',
                'type' => 'money',
                'label' => 'Mevcut fiyat',
                'required' => false,
                'editable' => false,
                'submit' => false,
                'decimals' => 2,
                'currency' => 'TRY',
            ],
            [
                'key' => 'newPrice',
                'type' => 'money',
                'label' => 'Yeni fiyat',
                'required' => true,
                'editable' => true,
                'submit' => true,
                'rules' => [['rule' => 'min', 'value' => '0'], ['rule' => 'max', 'value' => '100000']],
                'example' => '1250.5',
                'decimals' => 2,
            ],
            [
                'key' => 'currency',
                'type' => 'options',
                'label' => 'Para birimi',
                'required' => false,
                'editable' => true,
                'submit' => true,
                'options' => [['value' => 'TRY', 'label' => 'Türk lirası'], ['value' => 'EUR', 'label' => 'Avro']],
            ],
            [
                'key' => 'status',
                'type' => 'options',
                'label' => 'Durum',
                'required' => false,
                'editable' => true,
                'submit' => true,
                'options' => [['value' => 'open', 'label' => 'Açık'], ['value' => 'closed', 'label' => 'Kapalı']],
            ],
            [
                'key' => 'validFrom',
                'type' => 'date',
                'label' => 'Geçerlilik',
                'required' => false,
                'editable' => true,
                'submit' => true,
                'pattern' => 'd.m.Y',
            ],
            ['key' => 'active', 'type' => 'bool', 'label' => 'active', 'required' => false, 'editable' => true, 'submit' => true],
            [
                'key' => 'note',
                'type' => 'string',
                'label' => 'note',
                'required' => false,
                'editable' => true,
                'submit' => true,
                'rules' => [['rule' => 'maxLength', 'value' => 60]],
                'example' => 'Kampanya sonrası',
            ],
        ], $json['fields']);
    }

    #[Test]
    public function itNamesTheModuleAfterTheSchemaUnlessToldOtherwise(): void
    {
        $tabula = self::tabula();

        self::assertSame('product.price_update', $tabula->gridSchema(GridSchema::of(self::schema(), 1))['module']);
        self::assertSame('prices', $tabula->gridSchema(GridSchema::of(self::schema(), 1)->module('prices'))['module']);
    }

    #[Test]
    public function itNeverChangesTheObjectItWasCalledOn(): void
    {
        $grid = GridSchema::of(self::schema(), 1);
        $ruled = $grid->rules('note', Rule::maxLength(5));

        self::assertSame([], $grid->rulesFor('note'));
        self::assertCount(1, $ruled->rulesFor('note'));
    }

    #[Test]
    public function itRefusesARuleThatDoesNotFitItsField(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('The "min" rule does not apply to the field "note", a string field.');

        GridSchema::of(self::schema(), 1)->rules('note', Rule::min('0'));
    }

    #[Test]
    public function itRefusesALengthOnALookup(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('The "maxLength" rule does not apply to the field "sku", a lookup field.');

        GridSchema::of(self::schema(), 1)->rules('sku', Rule::maxLength(5))->lookup('sku', 'sku');
    }

    #[Test]
    public function itRefusesAFieldTheSchemaDoesNotHave(): void
    {
        $this->expectException(SchemaException::class);

        GridSchema::of(self::schema(), 1)->readOnly('price');
    }

    #[Test]
    public function itRefusesAVersionBelowOne(): void
    {
        $this->expectException(SchemaException::class);

        GridSchema::of(self::schema(), 0);
    }

    #[Test]
    public function itKeepsLimitsAsDecimalTextAndRefusesOtherText(): void
    {
        self::assertSame('0.3', Rule::min('0.30')->value);
        self::assertSame('-12', Rule::max(-12)->value);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('The "min" rule needs a number written as decimal text, such as "0.30"; got "1,5".');

        Rule::min('1,5');
    }

    #[Test]
    public function itComparesDecimalsWithoutFloats(): void
    {
        self::assertSame(-1, Decimal::compare('0.3', '0.30000000000000001'));
        self::assertSame(0, Decimal::compare((string) Decimal::normalize('012.500'), '12.5'));
        self::assertSame(1, Decimal::compare('-1', '-2'));
        self::assertSame('0.1', Decimal::normalize(0.1));
        self::assertNull(Decimal::normalize('1e5'));
    }
}
