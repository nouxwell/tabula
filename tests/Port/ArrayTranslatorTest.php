<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Port;

use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Port\Translator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Çerçevesiz varsayılan çeviri uygulaması.
 *
 * İki sözleşme maddesi önemlidir:
 *  - Katalog hem DÜZ noktalı anahtarla (`export.customer.code`) hem de YAML'dan gelen
 *    İÇ İÇE yapıyla verilebilir; ikisi de aynı anahtarla okunur.
 *  - Anahtar bulunamazsa anahtarın KENDİSİ döner. Eski ERP'de eksik çeviri boş kolon
 *    başlığı üretiyordu ve kullanıcı hangi kolona baktığını anlayamıyordu; burada en
 *    kötü ihtimalle teknik anahtar görünür.
 */
#[CoversClass(ArrayTranslator::class)]
final class ArrayTranslatorTest extends TestCase
{
    #[Test]
    public function itIsATranslator(): void
    {
        self::assertInstanceOf(Translator::class, new ArrayTranslator());
    }

    // ---------------------------------------------------------------- katalog biçimleri

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
        // 'a' bir dizi düğümü; metin değil, o yüzden çeviri sayılmaz.
        $translator = new ArrayTranslator(['tr' => ['a' => ['b' => 'AB']]]);

        self::assertSame('a', $translator->trans('a', [], 'tr'));
    }

    #[Test]
    public function aNonStringLeafIsNotUsedAsATranslation(): void
    {
        $translator = new ArrayTranslator(['tr' => ['count' => 5]]);

        self::assertSame('count', $translator->trans('count', [], 'tr'));
    }

    // ---------------------------------------------------------------- yedek dil

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

    // ---------------------------------------------------------------- eksik anahtar

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

    // ---------------------------------------------------------------- parametreler

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
        // Symfony alışkanlığıyla '%name%' yazılabilsin diye ad her iki biçimde de kabul edilir.
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
        // Çeviri bulunamasa bile anahtarın içindeki yer tutucular doldurulur.
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
