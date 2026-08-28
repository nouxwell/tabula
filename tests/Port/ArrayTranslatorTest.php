<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Port;

use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Port\Translator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The framework-free default translator implementation.
 *
 * Two clauses of the contract matter:
 *  - The catalogue can be given either with a FLAT dotted key (`export.customer.code`) or as
 *    the NESTED structure that comes out of YAML; both are read with the same key.
 *  - If the key cannot be found, the KEY ITSELF is returned. In the system this package
 *    replaces, a missing translation produced an empty column header and the user could not
 *    tell which column they were looking at; here the worst case is that the technical key
 *    shows up.
 */
#[CoversClass(ArrayTranslator::class)]
final class ArrayTranslatorTest extends TestCase
{
    #[Test]
    public function itIsATranslator(): void
    {
        self::assertInstanceOf(Translator::class, new ArrayTranslator());
    }

    // ---------------------------------------------------------------- catalogue shapes

    #[Test]
    public function readsAFlatDottedKey(): void
    {
        $translator = new ArrayTranslator([
            'tr' => ['export.customer.code' => 'Kod'],
        ]);

        self::assertSame('Kod', $translator->trans('export.customer.code', [], 'tr'));
    }

    #[Test]
    public function readsANestedCatalogue(): void
    {
        $translator = new ArrayTranslator([
            'tr' => ['export' => ['customer' => ['code' => 'Kod', 'name' => 'Ad']]],
        ]);

        self::assertSame('Kod', $translator->trans('export.customer.code', [], 'tr'));
        self::assertSame('Ad', $translator->trans('export.customer.name', [], 'tr'));
    }

    #[Test]
    public function theFlatKeyWinsWhenBothShapesExist(): void
    {
        $translator = new ArrayTranslator([
            'tr' => ['a.b' => 'DÜZ', 'a' => ['b' => 'İÇ İÇE']],
        ]);

        self::assertSame('DÜZ', $translator->trans('a.b', [], 'tr'));
    }

    #[Test]
    public function anIncompletePathReturnsTheKey(): void
    {
        // 'a' is an array node; it is not text, so it does not count as a translation.
        $translator = new ArrayTranslator(['tr' => ['a' => ['b' => 'AB']]]);

        self::assertSame('a', $translator->trans('a', [], 'tr'));
    }

    #[Test]
    public function aNonStringLeafIsNotUsedAsATranslation(): void
    {
        $translator = new ArrayTranslator(['tr' => ['count' => 5]]);

        self::assertSame('count', $translator->trans('count', [], 'tr'));
    }

    // ---------------------------------------------------------------- fallback locale

    #[Test]
    public function fallsBackToTheFallbackLocaleWhenTheKeyIsMissing(): void
    {
        $translator = new ArrayTranslator([
            'tr' => ['export.customer.code' => 'Kod'],
            'en' => ['export.customer.code' => 'Code', 'export.customer.name' => 'Name'],
        ], 'en');

        self::assertSame('Kod', $translator->trans('export.customer.code', [], 'tr'));
        self::assertSame('Name', $translator->trans('export.customer.name', [], 'tr'));
    }

    #[Test]
    public function fallsBackWhenTheWholeLocaleIsUnknown(): void
    {
        $translator = new ArrayTranslator(['en' => ['greeting' => 'Hello']], 'en');

        self::assertSame('Hello', $translator->trans('greeting', [], 'de'));
    }

    #[Test]
    public function aNullLocaleUsesTheFallbackLocale(): void
    {
        $translator = new ArrayTranslator([
            'tr' => ['greeting' => 'Merhaba'],
            'en' => ['greeting' => 'Hello'],
        ], 'tr');

        self::assertSame('Merhaba', $translator->trans('greeting'));
    }

    // ---------------------------------------------------------------- missing key

    #[Test]
    public function aMissingKeyReturnsTheKeyItself(): void
    {
        $translator = new ArrayTranslator(['tr' => ['known' => 'Bilinen']], 'tr');

        self::assertSame('export.customer.iban', $translator->trans('export.customer.iban', [], 'tr'));
    }

    #[Test]
    public function anEmptyCatalogueReturnsEveryKeyUnchanged(): void
    {
        self::assertSame('a.b.c', (new ArrayTranslator())->trans('a.b.c'));
    }

    // ---------------------------------------------------------------- parameters

    #[Test]
    public function replacesParametersWrittenWithoutPercentSigns(): void
    {
        $translator = new ArrayTranslator(['tr' => ['greeting' => 'Merhaba %name%, %count% kayıt']], 'tr');

        self::assertSame(
            'Merhaba Ali, 3 kayıt',
            $translator->trans('greeting', ['name' => 'Ali', 'count' => 3], 'tr'),
        );
    }

    #[Test]
    public function replacesParametersWrittenWithPercentSigns(): void
    {
        // So that '%name%' can be written out of Symfony habit, the name is accepted in both shapes.
        $translator = new ArrayTranslator(['tr' => ['greeting' => 'Merhaba %name%, %count% kayıt']], 'tr');

        self::assertSame(
            'Merhaba Ali, 3 kayıt',
            $translator->trans('greeting', ['%name%' => 'Ali', '%count%' => 3], 'tr'),
        );
    }

    #[Test]
    public function parametersAreCastToString(): void
    {
        $translator = new ArrayTranslator(['tr' => ['total' => 'Toplam: %amount%']], 'tr');

        self::assertSame('Toplam: 12.5', $translator->trans('total', ['amount' => 12.5], 'tr'));
    }

    #[Test]
    public function parametersAreAlsoAppliedToTheFallbackKeyText(): void
    {
        // Even when no translation is found, the placeholders inside the key are filled in.
        $translator = new ArrayTranslator([], 'tr');

        self::assertSame('Ali bulunamadı', $translator->trans('%name% bulunamadı', ['name' => 'Ali'], 'tr'));
    }

    #[Test]
    public function anUnusedParameterLeavesTheTextAlone(): void
    {
        $translator = new ArrayTranslator(['tr' => ['greeting' => 'Merhaba']], 'tr');

        self::assertSame('Merhaba', $translator->trans('greeting', ['name' => 'Ali'], 'tr'));
    }

    #[Test]
    public function aPlaceholderWithoutAMatchingParameterStaysAsIs(): void
    {
        $translator = new ArrayTranslator(['tr' => ['greeting' => 'Merhaba %name%']], 'tr');

        self::assertSame('Merhaba %name%', $translator->trans('greeting', ['other' => 'x'], 'tr'));
    }
}
