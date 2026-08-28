<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Schema;

use InvalidArgumentException;
use Nouxwell\Tabula\Exception\SchemaException;
use Nouxwell\Tabula\Exception\TabulaException;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The schema is the single source of truth for the table, which makes the two things tested
 * here critical:
 *
 *  1. Nothing is swallowed silently — an unknown or duplicate key is always an error. In the
 *     system this replaces, an unrecognised column name coming from the client was
 *     silently dropped, and the user only noticed the missing column once they opened the
 *     file.
 *  2. `only()` preserves the REQUESTED ORDER — the column picker on the client can drag and
 *     reorder columns, so the selection order has to override the definition order.
 */
#[CoversClass(Schema::class)]
#[CoversClass(SchemaException::class)]
final class SchemaTest extends TestCase
{
    // ---------------------------------------------------------------- construction

    #[Test]
    public function makeStoresTheName(): void
    {
        self::assertSame('customer', Schema::make('customer')->getName());
    }

    #[Test]
    public function makeRejectsABlankName(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('The schema name cannot be empty.');

        Schema::make('   ');
    }

    #[Test]
    public function freshSchemaIsEmpty(): void
    {
        $schema = Schema::make('customer');

        self::assertTrue($schema->isEmpty());
        self::assertSame([], $schema->getKeys());
        self::assertSame([], $schema->getFields());
        self::assertNull($schema->getTitle());
    }

    #[Test]
    public function fieldsKeepsDefinitionOrder(): void
    {
        self::assertSame(['code', 'name', 'balance'], self::customerSchema()->getKeys());
    }

    #[Test]
    public function fieldsIsKeyedByFieldKey(): void
    {
        $fields = self::customerSchema()->getFields();

        self::assertArrayHasKey('balance', $fields);
        self::assertSame('balance', $fields['balance']->getKey());
    }

    // ---------------------------------------------------------------- immutability

    #[Test]
    public function fieldsReturnsACloneAndDoesNotMutateTheOriginal(): void
    {
        $schema = self::customerSchema();
        $extended = $schema->fields(Field::string('taxNumber'));

        self::assertNotSame($schema, $extended);
        self::assertSame(['code', 'name', 'balance'], $schema->getKeys(), 'The original schema must not grow.');
        self::assertSame(['code', 'name', 'balance', 'taxNumber'], $extended->getKeys());
    }

    #[Test]
    public function titleReturnsAClone(): void
    {
        $schema = Schema::make('customer');
        $titled = $schema->title('export.customer.title');

        self::assertNotSame($schema, $titled);
        self::assertNull($schema->getTitle());
        self::assertSame('export.customer.title', $titled->getTitle());
    }

    #[Test]
    public function titleKeepsAClosureForLateResolution(): void
    {
        $title = static fn (string $locale): string => 'tr' === $locale ? 'Müşteriler' : 'Customers';

        self::assertSame($title, Schema::make('customer')->title($title)->getTitle());
    }

    #[Test]
    public function mutatingTheArrayReturnedByGetFieldsDoesNotTouchTheSchema(): void
    {
        $schema = self::customerSchema();

        $fields = $schema->getFields();
        unset($fields['code']);

        self::assertSame(['code', 'name', 'balance'], $schema->getKeys());
    }

    // ---------------------------------------------------------------- duplicate key

    #[Test]
    public function duplicateFieldKeyInASingleCallThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('The "customer" schema defines the field "code" more than once.');

        Schema::make('customer')->fields(
            Field::string('code'),
            Field::integer('code'),
        );
    }

    #[Test]
    public function duplicateFieldKeyAcrossTwoCallsAlsoThrows(): void
    {
        // No silent overwrite: the second call goes through the same guard.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('more than once');

        self::customerSchema()->fields(Field::string('code'));
    }

    #[Test]
    public function schemaExceptionIsPartOfThePackageExceptionFamily(): void
    {
        $exception = SchemaException::duplicateField('customer', 'code');

        self::assertInstanceOf(TabulaException::class, $exception);
        self::assertInstanceOf(InvalidArgumentException::class, $exception);
    }

    // ---------------------------------------------------------------- field access

    #[Test]
    public function fieldReturnsTheDefinition(): void
    {
        $schema = self::customerSchema();

        self::assertSame('balance', $schema->field('balance')->getKey());
        self::assertTrue($schema->has('balance'));
        self::assertFalse($schema->has('nope'));
    }

    /** The error message enumerates the defined keys — the fastest way to track down a typo. */
    #[Test]
    public function unknownFieldThrowsAndListsTheKnownKeys(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('The "customer" schema has no field called "cod". Defined fields: code, name, balance');

        self::customerSchema()->field('cod');
    }

    #[Test]
    public function unknownFieldOnAnEmptySchemaSaysThereAreNone(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Defined fields: (none)');

        Schema::make('customer')->field('code');
    }

    // ---------------------------------------------------------------- only()

    /**
     * The most critical clause of the contract: the result comes back in the REQUESTED order,
     * not in the DEFINITION order. The column picker on the client leaves the ordering up to
     * the user, so it depends on this.
     */
    #[Test]
    public function onlyPreservesTheRequestedOrderNotTheDefinitionOrder(): void
    {
        $schema = self::customerSchema();

        self::assertSame(['balance', 'code'], $schema->only(['balance', 'code'])->getKeys());
        self::assertSame(['name', 'balance', 'code'], $schema->only(['name', 'balance', 'code'])->getKeys());

        // Two different orders can be requested from the same schema; the schema itself never changes.
        self::assertSame(['code', 'name', 'balance'], $schema->getKeys());
    }

    #[Test]
    public function onlyReturnsACloneWithTheSameNameAndTitle(): void
    {
        $schema = self::customerSchema()->title('export.customer.title');
        $picked = $schema->only(['code']);

        self::assertNotSame($schema, $picked);
        self::assertSame('customer', $picked->getName());
        self::assertSame('export.customer.title', $picked->getTitle());
        self::assertSame(['code'], $picked->getKeys());
    }

    #[Test]
    public function onlyKeepsTheOriginalFieldInstances(): void
    {
        $schema = self::customerSchema();

        self::assertSame($schema->field('code'), $schema->only(['code'])->field('code'));
    }

    #[Test]
    public function onlyRejectsAnUnknownKeyInsteadOfSwallowingIt(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('The "customer" schema has no field called "iban". Defined fields: code, name, balance');

        self::customerSchema()->only(['code', 'iban']);
    }

    #[Test]
    public function onlyRejectsAnEmptySelection(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('At least one field must be selected from the "customer" schema.');

        self::customerSchema()->only([]);
    }

    #[Test]
    public function onlyCollapsesARepeatedKey(): void
    {
        // Fields are held by key, so asking for the same key twice yields a single column.
        self::assertSame(['code'], self::customerSchema()->only(['code', 'code'])->getKeys());
    }

    // ---------------------------------------------------------------- forFormat()

    #[Test]
    public function forFormatDropsFieldsThatDoNotApply(): void
    {
        $schema = Schema::make('customer')->fields(
            Field::string('code'),
            Field::string('internalId')->only(Format::Xlsx),
            Field::string('note')->only(Format::Csv, Format::Pdf),
        );

        self::assertSame(['code', 'internalId'], $schema->forFormat(Format::Xlsx)->getKeys());
        self::assertSame(['code', 'note'], $schema->forFormat(Format::Csv)->getKeys());
        self::assertSame(['code', 'note'], $schema->forFormat(Format::Pdf)->getKeys());
    }

    #[Test]
    public function forFormatLeavesTheOriginalSchemaIntact(): void
    {
        $schema = Schema::make('customer')->fields(
            Field::string('code'),
            Field::string('internalId')->only(Format::Xlsx),
        );

        $schema->forFormat(Format::Csv);

        self::assertSame(['code', 'internalId'], $schema->getKeys());
    }

    #[Test]
    public function forFormatCanEmptyTheSchemaCompletely(): void
    {
        $schema = Schema::make('customer')->fields(Field::string('internalId')->only(Format::Xlsx));

        self::assertTrue($schema->forFormat(Format::Pdf)->isEmpty());
    }

    // ---------------------------------------------------------------- required()

    #[Test]
    public function requiredReturnsOnlyRequiredFieldsAsAList(): void
    {
        $schema = Schema::make('customer')->fields(
            Field::string('code')->required(),
            Field::string('name'),
            Field::string('taxNumber')->required(),
        );

        $required = $schema->required();

        // It must be a list: keys 0..n, so it can be handed straight to a writer.
        self::assertSame([0, 1], array_keys($required));
        self::assertSame(['code', 'taxNumber'], array_map(static fn (Field $f): string => $f->getKey(), $required));
    }

    #[Test]
    public function requiredIsEmptyWhenNothingIsMarked(): void
    {
        self::assertSame([], self::customerSchema()->required());
    }

    // ---------------------------------------------------------------- helpers

    private static function customerSchema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code'),
            Field::string('name'),
            Field::money('balance'),
        );
    }
}
