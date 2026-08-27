<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Value;

use ArrayObject;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Value\ValueResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Satırdan ham değeri okuyan tek nokta.
 *
 * Satır her şey olabilir: Doctrine dizi projeksiyonu, entity, DTO, ArrayObject.
 * Eski ERP motorunda bunun için her rapor kendi `$row['x'] ?? $row->getX()` merdivenini
 * yazıyordu; eksik ilişki de çoğu yerde "Undefined index" ile patlıyordu. Buradaki
 * sözleşme nettir: EKSİK VERİ HATA DEĞİL, BOŞ HÜCREDİR — yol boyunca `null` görülürse
 * `null` döner, istisna fırlatılmaz.
 */
#[CoversClass(ValueResolver::class)]
final class ValueResolverTest extends TestCase
{
    private ValueResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ValueResolver();
    }

    // ---------------------------------------------------------------- diziler

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

    // ---------------------------------------------------------------- nesneler

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
        // getX/isX bulunamazsa anahtarın kendisi metot adı olarak denenir.
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
        // Erişimci sırası: getX, isX, x(), sonra özellik.
        self::assertSame('getter', $this->read('label', new ResolverAccessorOrder()));
    }

    #[Test]
    public function inaccessibleMembersAreNotReachable(): void
    {
        // private metot da private özellik de dışarıdan okunamaz; hata değil, null.
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

    // ---------------------------------------------------------------- kapanış kaynağı

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

    // ---------------------------------------------------------------- DQL takma adları

    /**
     * Doctrine projeksiyonlarında `SELECT c.code` sonucu satırda çoğu zaman düz `code`
     * olarak döner, bazı sürücü/hydration kombinasyonlarında ise `c.code` olarak.
     * Alan tanımı her iki durumda da aynı kalabilsin diye önce düz anahtar denenir.
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

    // ---------------------------------------------------------------- eksik veri

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
        // Eksik ilişki hata değildir: adres yoksa şehir boş hücredir.
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

    // ---------------------------------------------------------------- yardımcılar

    /** Alan tanımı yazmadan tek satırda okuma yapmak için. */
    private function read(string $key, mixed $row, ?string $from = null): mixed
    {
        $field = Field::string($key);

        if (null !== $from) {
            $field = $field->from($from);
        }

        return $this->resolver->resolve($field, $row);
    }
}

/** Test koşumu için basit bir adres nesnesi. */
final class ResolverAddress
{
    public function __construct(public string $city)
    {
    }
}

/** Erişimcinin her biçimini (getter, isser, düz metot, public özellik, private üye) taşıyan satır. */
final class ResolverCustomer
{
    public string $displayName = 'Ada Lovelace';

    /** Doldurulmamış bir ilişki/alan: erişimci vardır ama değer yoktur. */
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

    /** private üyeler dışarıdan görünmez; test bunu doğrular, bu metot yalnız erişimi kanıtlar. */
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

/** getX, isX ve public özelliğin aynı anda bulunduğu satır — sıra testi için. */
final class ResolverAccessorOrder
{
    public string $label = 'property';

    public function getLabel(): string
    {
        return 'getter';
    }
}

/** Nesne içinde düz dizi taşıyan satır. */
final class ResolverArrayHolder
{
    /** @var array<string, string> */
    public array $address = ['city' => 'Sivas'];
}
