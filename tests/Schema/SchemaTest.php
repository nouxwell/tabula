<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Schema;

use Balin\Tabula\Exception\SchemaException;
use Balin\Tabula\Exception\TabulaException;
use Balin\Tabula\Format;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Şema, tablonun tek doğruluk kaynağıdır; bu yüzden burada test edilen iki şey kritiktir:
 *
 *  1. Sessiz yutma yok — bilinmeyen ya da çift anahtar her zaman hata olur. Eski ERP'de
 *     istemciden gelen tanınmayan kolon adı sessizce atılıyordu ve kullanıcı eksik kolonu
 *     ancak dosyayı açtığında fark ediyordu.
 *  2. `only()` İSTENEN SIRAYI korur — istemcideki kolon seçici kolonları sürükleyip
 *     sıralayabildiği için, seçim sırası tanım sırasını ezmek zorundadır.
 */
#[CoversClass(Schema::class)]
#[CoversClass(SchemaException::class)]
final class SchemaTest extends TestCase
{
    // ---------------------------------------------------------------- kuruluş

    #[Test]
    public function makeStoresTheName(): void
    {
        self::assertSame('customer', Schema::make('customer')->getName());
    }

    #[Test]
    public function makeRejectsABlankName(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Şema adı boş olamaz.');

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

    // ---------------------------------------------------------------- değişmezlik

    #[Test]
    public function fieldsReturnsACloneAndDoesNotMutateTheOriginal(): void
    {
        $schema = self::customerSchema();
        $extended = $schema->fields(Field::string('taxNumber'));

        self::assertNotSame($schema, $extended);
        self::assertSame(['code', 'name', 'balance'], $schema->getKeys(), 'Özgün şema büyümemeli.');
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

    // ---------------------------------------------------------------- çift anahtar

    #[Test]
    public function duplicateFieldKeyInASingleCallThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('"customer" şemasında "code" alanı birden fazla kez tanımlandı.');

        Schema::make('customer')->fields(
            Field::string('code'),
            Field::integer('code'),
        );
    }

    #[Test]
    public function duplicateFieldKeyAcrossTwoCallsAlsoThrows(): void
    {
        // Sessiz üzerine yazma yok: ikinci çağrı da aynı korumadan geçer.
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('birden fazla kez tanımlandı');

        self::customerSchema()->fields(Field::string('code'));
    }

    #[Test]
    public function schemaExceptionIsPartOfThePackageExceptionFamily(): void
    {
        $exception = SchemaException::duplicateField('customer', 'code');

        self::assertInstanceOf(TabulaException::class, $exception);
        self::assertInstanceOf(InvalidArgumentException::class, $exception);
    }

    // ---------------------------------------------------------------- alan erişimi

    #[Test]
    public function fieldReturnsTheDefinition(): void
    {
        $schema = self::customerSchema();

        self::assertSame('balance', $schema->field('balance')->getKey());
        self::assertTrue($schema->has('balance'));
        self::assertFalse($schema->has('nope'));
    }

    /** Hata mesajı tanımlı anahtarları sayar — yazım hatasını ayıklamanın en hızlı yolu. */
    #[Test]
    public function unknownFieldThrowsAndListsTheKnownKeys(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('"customer" şemasında "cod" diye bir alan yok. Tanımlı alanlar: code, name, balance');

        self::customerSchema()->field('cod');
    }

    #[Test]
    public function unknownFieldOnAnEmptySchemaSaysThereAreNone(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('Tanımlı alanlar: (hiç yok)');

        Schema::make('customer')->field('code');
    }

    // ---------------------------------------------------------------- only()

    /**
     * Sözleşmenin en kritik maddesi: sonuç TANIM sırasında değil, İSTENEN sırada gelir.
     * İstemcideki kolon seçici sıralamayı kullanıcıya bıraktığı için buna bağımlıdır.
     */
    #[Test]
    public function onlyPreservesTheRequestedOrderNotTheDefinitionOrder(): void
    {
        $schema = self::customerSchema();

        self::assertSame(['balance', 'code'], $schema->only(['balance', 'code'])->getKeys());
        self::assertSame(['name', 'balance', 'code'], $schema->only(['name', 'balance', 'code'])->getKeys());

        // Aynı şemadan iki farklı sıra istenebilir; şema kendisi hiç değişmez.
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
        $this->expectExceptionMessage('"customer" şemasında "iban" diye bir alan yok. Tanımlı alanlar: code, name, balance');

        self::customerSchema()->only(['code', 'iban']);
    }

    #[Test]
    public function onlyRejectsAnEmptySelection(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('"customer" şemasından en az bir alan seçilmeli.');

        self::customerSchema()->only([]);
    }

    #[Test]
    public function onlyCollapsesARepeatedKey(): void
    {
        // Alanlar anahtara göre tutulduğu için aynı anahtar iki kez istenirse tek kolon çıkar.
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

        // Liste olmalı: anahtarlar 0..n, yazıcıya doğrudan verilebilsin.
        self::assertSame([0, 1], array_keys($required));
        self::assertSame(['code', 'taxNumber'], array_map(static fn (Field $f): string => $f->getKey(), $required));
    }

    #[Test]
    public function requiredIsEmptyWhenNothingIsMarked(): void
    {
        self::assertSame([], self::customerSchema()->required());
    }

    // ---------------------------------------------------------------- yardımcılar

    private static function customerSchema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code'),
            Field::string('name'),
            Field::money('balance'),
        );
    }
}
