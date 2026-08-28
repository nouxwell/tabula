<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Value;

use ArrayObject;
use Nouxwell\Tabula\Schema\Field;
use Nouxwell\Tabula\Value\ValueResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The single place where the raw value is read out of a row.
 *
 * A row can be anything: a Doctrine array projection, an entity, a DTO, an ArrayObject. In
 * the engine this replaces, every report wrote its own `$row['x'] ?? $row->getX()`
 * ladder for this, and a missing relation blew up with "Undefined index" in most places. The
 * contract here is clear: MISSING DATA IS NOT AN ERROR, IT IS AN EMPTY CELL — if `null` is
 * met anywhere along the path, `null` is returned and no exception is thrown.
 */
#[CoversClass(ValueResolver::class)]
final class ValueResolverTest extends TestCase
{
    private ValueResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ValueResolver();
    }

    // ---------------------------------------------------------------- arrays

    #[Test]
    public function readsAPlainArrayKey(): void
    {
        self::assertSame('C-001', $this->read('code', ['code' => 'C-001']));
    }

    #[Test]
    public function readsANestedDotPathOverArrays(): void
    {
        $row = ['address' => ['city' => 'Ankara']];

        self::assertSame('Ankara', $this->read('city', $row, 'address.city'));
    }

    #[Test]
    public function readsADeeplyNestedDotPath(): void
    {
        $row = ['a' => ['b' => ['c' => 'derin']]];

        self::assertSame('derin', $this->read('c', $row, 'a.b.c'));
    }

    #[Test]
    public function falsyValuesSurviveAndAreNotConfusedWithMissing(): void
    {
        self::assertFalse($this->read('flag', ['flag' => false]));
        self::assertSame(0, $this->read('qty', ['qty' => 0]));
        self::assertSame('', $this->read('note', ['note' => '']));
        self::assertSame('0', $this->read('code', ['code' => '0']));
    }

    // ---------------------------------------------------------------- objects

    #[Test]
    public function readsAGetter(): void
    {
        self::assertSame('C-001', $this->read('code', new ResolverCustomer()));
    }

    #[Test]
    public function readsAnIsGetterForBooleans(): void
    {
        self::assertTrue($this->read('active', new ResolverCustomer()));
    }

    #[Test]
    public function readsABareMethodNamedLikeTheKey(): void
    {
        // If no getX/isX is found, the key itself is tried as a method name.
        self::assertSame('1234567890', $this->read('taxNumber', new ResolverCustomer()));
    }

    #[Test]
    public function readsAPublicProperty(): void
    {
        self::assertSame('Ada Lovelace', $this->read('displayName', new ResolverCustomer()));
    }

    #[Test]
    public function readsADynamicPropertyOnStdClass(): void
    {
        $row = new stdClass();
        $row->code = 'STD-1';

        self::assertSame('STD-1', $this->read('code', $row));
    }

    #[Test]
    public function getterWinsOverAPublicPropertyWithTheSameName(): void
    {
        // Accessor order: getX, isX, x(), then the property.
        self::assertSame('getter', $this->read('label', new ResolverAccessorOrder()));
    }

    #[Test]
    public function inaccessibleMembersAreNotReachable(): void
    {
        // Neither a private method nor a private property can be read from outside; not an error, null.
        self::assertNull($this->read('secret', new ResolverCustomer()));
        self::assertNull($this->read('hiddenCode', new ResolverCustomer()));
    }

    #[Test]
    public function walksADotPathThroughObjects(): void
    {
        $row = new ResolverCustomer(new ResolverAddress('Bursa'));

        self::assertSame('Bursa', $this->read('city', $row, 'address.city'));
    }

    #[Test]
    public function walksADotPathThatMixesArraysAndObjects(): void
    {
        self::assertSame('Konya', $this->read('city', ['address' => new ResolverAddress('Konya')], 'address.city'));
        self::assertSame('Sivas', $this->read('city', new ResolverArrayHolder(), 'address.city'));
    }

    // ---------------------------------------------------------------- ArrayAccess

    #[Test]
    public function readsAnArrayAccessOffset(): void
    {
        self::assertSame('AO-1', $this->read('code', new ArrayObject(['code' => 'AO-1'])));
    }

    #[Test]
    public function walksADotPathThroughArrayAccess(): void
    {
        $row = new ArrayObject(['address' => ['city' => 'İzmir']]);

        self::assertSame('İzmir', $this->read('city', $row, 'address.city'));
    }

    // ---------------------------------------------------------------- closure source

    #[Test]
    public function aClosureSourceReceivesTheWholeRow(): void
    {
        $row = ['code' => 'c-001', 'name' => 'Ada'];

        $value = $this->resolver->resolve(
            Field::string('upper')->from(static fn (array $r): string => strtoupper($r['code'])),
            $row,
        );

        self::assertSame('C-001', $value);
    }

    #[Test]
    public function aClosureSourceGetsTheExactRowInstance(): void
    {
        $row = new ResolverCustomer();
        $captured = null;

        $this->resolver->resolve(
            Field::string('x')->from(static function (mixed $given) use (&$captured): string {
                $captured = $given;

                return 'ok';
            }),
            $row,
        );

        self::assertSame($row, $captured);
    }

    // ---------------------------------------------------------------- DQL aliases

    /**
     * In Doctrine projections the result of `SELECT c.code` most often comes back on the row
     * as a flat `code`, but with some driver/hydration combinations as `c.code`. So that the
     * field definition can stay the same in both cases, the flat key is tried first.
     */
    #[Test]
    public function aDqlAliasResolvesWhenTheRowHasTheFlatKey(): void
    {
        self::assertSame('FLAT', $this->read('code', ['c.code' => 'FLAT'], 'c.code'));
    }

    #[Test]
    public function aDqlAliasResolvesWhenTheRowIsActuallyNested(): void
    {
        self::assertSame('NESTED', $this->read('code', ['c' => ['code' => 'NESTED']], 'c.code'));
    }

    #[Test]
    public function aDqlAliasResolvesOverNestedObjects(): void
    {
        $row = new stdClass();
        $row->c = new ResolverAddress('DQL');

        self::assertSame('DQL', $this->read('city', $row, 'c.city'));
    }

    #[Test]
    public function theFlatKeyWinsWhenBothShapesExist(): void
    {
        $row = ['c.code' => 'FLAT', 'c' => ['code' => 'NESTED']];

        self::assertSame('FLAT', $this->read('code', $row, 'c.code'));
    }

    // ---------------------------------------------------------------- missing data

    #[Test]
    public function aMissingKeyReturnsNullInsteadOfThrowing(): void
    {
        self::assertNull($this->read('iban', ['code' => 'C-001']));
    }

    #[Test]
    public function aMissingDotPathReturnsNullInsteadOfThrowing(): void
    {
        self::assertNull($this->read('city', ['code' => 'C-001'], 'address.city'));
        self::assertNull($this->read('c', ['a' => ['b' => []]], 'a.b.c'));
    }

    #[Test]
    public function nullMidwayStopsTheWalkAndReturnsNull(): void
    {
        // A missing relation is not an error: with no address, the city is an empty cell.
        self::assertNull($this->read('city', ['address' => null], 'address.city'));
        self::assertNull($this->read('city', new ResolverCustomer(), 'address.city'));
    }

    #[Test]
    public function aGetterReturningNullIsNotAnError(): void
    {
        self::assertNull($this->read('nickname', new ResolverCustomer()));
    }

    #[Test]
    public function aRowThatIsNeitherArrayNorObjectResolvesToNull(): void
    {
        self::assertNull($this->read('code', 'düz metin'));
        self::assertNull($this->read('code', null));
        self::assertNull($this->read('code', 42));
    }

    // ---------------------------------------------------------------- helpers

    /** For reading in a single line without writing out a field definition. */
    private function read(string $key, mixed $row, ?string $from = null): mixed
    {
        $field = Field::string($key);

        if (null !== $from) {
            $field = $field->from($from);
        }

        return $this->resolver->resolve($field, $row);
    }
}

/** A simple address object for the test run. */
final class ResolverAddress
{
    public function __construct(public string $city)
    {
    }
}

/** A row carrying every accessor shape (getter, isser, bare method, public property, private member). */
final class ResolverCustomer
{
    public string $displayName = 'Ada Lovelace';

    /** An unpopulated relation/field: the accessor exists but there is no value. */
    public ?string $nickname = null;

    private string $secret = 'gizli';

    public function __construct(public ?ResolverAddress $address = null)
    {
    }

    public function getCode(): string
    {
        return 'C-001';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function taxNumber(): string
    {
        return '1234567890';
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    /** Private members are not visible from outside; the test proves that, this method only proves the access exists. */
    public function revealSecret(): string
    {
        return $this->secret;
    }

    public function revealHiddenCode(): string
    {
        return $this->hiddenCode();
    }

    private function hiddenCode(): string
    {
        return 'H-1';
    }
}

/** A row where getX, isX and a public property are present at the same time — for the ordering test. */
final class ResolverAccessorOrder
{
    public string $label = 'property';

    public function getLabel(): string
    {
        return 'getter';
    }
}

/** A row carrying a plain array inside an object. */
final class ResolverArrayHolder
{
    /** @var array<string, string> */
    public array $address = ['city' => 'Sivas'];
}
