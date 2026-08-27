<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Bridge\Symfony;

use Balin\Tabula\Bridge\Symfony\SymfonyTranslator;
use Balin\Tabula\Port\Translator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Symfony çeviri köprüsünün sözleşmesi.
 *
 * Adaptörün tüm zorluğu tek bir yerden çıkar: Symfony bir anahtarı BULAMADIĞINDA hata
 * vermez, anahtarın kendisini döndürür. "Bulundu mu?" sorusunun cevabı bu yüzden yalnızca
 * dönen METNE bakılarak verilebilir — ve alan zinciri (`messages` → `enum`) tamamen bu
 * karara dayanır. Aşağıdaki sahte translator gerçek Symfony'nin ıska davranışını birebir
 * taklit eder (`strtr($id, $params)`); testlerin değeri buna bağlıdır.
 */
#[CoversClass(SymfonyTranslator::class)]
final class SymfonyTranslatorTest extends TestCase
{
    #[Test]
    public function itIsATranslator(): void
    {
        self::assertInstanceOf(Translator::class, new SymfonyTranslator(new RecordingSymfonyTranslator()));
    }

    /**
     * Sahtenin sadakati: bundan sonraki her ıska testi bu davranışın doğru taklit
     * edildiğine güvenir. Anahtar katalogda yoksa Symfony anahtarı mesaj olarak kullanır
     * ve parametreleri YİNE DE yerleştirir — döndürdüğü metin ham anahtara eşit değildir.
     */
    #[Test]
    public function theFakeReproducesSymfonysMissBehaviour(): void
    {
        $symfony = new RecordingSymfonyTranslator();

        self::assertSame('greeting.hello', $symfony->trans('greeting.hello', [], 'messages'));
        self::assertSame('Ada bulunamadı', $symfony->trans('%name% bulunamadı', ['%name%' => 'Ada'], 'messages'));
    }

    // ---------------------------------------------------------------- alan zinciri

    #[Test]
    public function findsAKeyThatOnlyTheSecondDomainDefines(): void
    {
        // ERP'deki asıl senaryo: etiketler `messages`, enum karşılıkları `enum` alanında.
        $symfony = new RecordingSymfonyTranslator([
            'messages' => ['export.customer.code' => 'Kod'],
            'enum' => ['purchase_status.open' => 'Açık'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Açık', $translator->trans('purchase_status.open'));
        self::assertSame(['messages', 'enum'], $symfony->askedDomains(), 'İlk alan önce denenmeli.');
    }

    #[Test]
    public function theFirstDomainWinsAndTheRestAreNeverAsked(): void
    {
        $symfony = new RecordingSymfonyTranslator([
            'messages' => ['status.open' => 'Mesajlardan'],
            'enum' => ['status.open' => 'Enumdan'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Mesajlardan', $translator->trans('status.open'));
        self::assertSame(['messages'], $symfony->askedDomains(), 'İsabetten sonra zincir durmalı.');
    }

    #[Test]
    public function theChainFollowsTheConfiguredOrder(): void
    {
        $symfony = new RecordingSymfonyTranslator([
            'messages' => ['status.open' => 'Mesajlardan'],
            'enum' => ['status.open' => 'Enumdan'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['enum', 'messages']);

        self::assertSame('Enumdan', $translator->trans('status.open'));
        self::assertSame(['enum'], $symfony->askedDomains());
    }

    #[Test]
    public function aKeyMissingFromEveryDomainReturnsTheKeyItself(): void
    {
        // Eksik çeviri boş hücre değil, görünür bir teknik anahtar üretmeli.
        $symfony = new RecordingSymfonyTranslator(['messages' => ['known' => 'Bilinen']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('export.customer.iban', $translator->trans('export.customer.iban'));
        self::assertSame(['messages', 'enum'], $symfony->askedDomains(), 'Her alan denenmeli.');
    }

    #[Test]
    public function anEmptyDomainListNeverAsksSymfonyAndReturnsTheKey(): void
    {
        // Bundle `requiresAtLeastOneElement()` diyor; yine de dejenere hâlde çökmemeli.
        $symfony = new RecordingSymfonyTranslator(['messages' => ['greeting' => 'Merhaba']]);
        $translator = new SymfonyTranslator($symfony, []);

        self::assertSame('greeting', $translator->trans('greeting'));
        self::assertSame([], $symfony->askedDomains());
    }

    // ---------------------------------------------------------------- açık alan öneki

    #[Test]
    public function anExplicitPrefixGoesStraightToThatDomain(): void
    {
        $symfony = new RecordingSymfonyTranslator([
            'messages' => ['purchase_status.open' => 'Mesajlardan'],
            'enum' => ['purchase_status.open' => 'Açık'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Açık', $translator->trans('enum:purchase_status.open'));
        self::assertSame(['enum'], $symfony->askedDomains(), 'Açık önek varken zincir hiç işlememeli.');
        self::assertSame(['purchase_status.open'], $symfony->askedIds(), 'Önek ayrıştırılıp atılmalı.');
    }

    #[Test]
    public function anExplicitPrefixDoesNotFallBackToTheOtherDomains(): void
    {
        // Açık önek bir NİYET beyanıdır: "bu anahtar oradadır". Bulunamazsa sessizce
        // başka alana kaymak, hatanın kaynağını gizlerdi.
        $symfony = new RecordingSymfonyTranslator([
            'messages' => ['purchase_status.ghost' => 'Yanlış alandan gelen değer'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('purchase_status.ghost', $translator->trans('enum:purchase_status.ghost'));
        self::assertSame(['enum'], $symfony->askedDomains());
    }

    #[Test]
    public function anUnknownPrefixIsTreatedAsPartOfTheKey(): void
    {
        // 'foo' yapılandırılmış bir alan değil; içinde iki nokta geçen anahtarlar
        // yanlışlıkla bölünmemeli.
        $symfony = new RecordingSymfonyTranslator(['messages' => ['foo:bar' => 'Değer']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Değer', $translator->trans('foo:bar'));
        self::assertSame(['foo:bar'], $symfony->askedIds(), 'Anahtar bölünmeden geçmeli.');
        self::assertSame(['messages'], $symfony->askedDomains());
    }

    #[Test]
    public function anUnknownPrefixStillWalksTheWholeChainWithTheFullKey(): void
    {
        $symfony = new RecordingSymfonyTranslator(['enum' => ['foo:bar' => 'Enumdan']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Enumdan', $translator->trans('foo:bar'));
        self::assertSame(['foo:bar', 'foo:bar'], $symfony->askedIds());
        self::assertSame(['messages', 'enum'], $symfony->askedDomains());
    }

    #[Test]
    public function aKeyStartingWithTheSeparatorIsNotSplit(): void
    {
        // Boş önek bir alan adı olamaz; ':x' bütün olarak anahtardır.
        $symfony = new RecordingSymfonyTranslator(['messages' => [':x' => 'İki nokta ile başlayan']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('İki nokta ile başlayan', $translator->trans(':x'));
        self::assertSame([':x'], $symfony->askedIds());
    }

    #[Test]
    public function aLeadingSeparatorIsNotSplitEvenWhenAnEmptyDomainIsConfigured(): void
    {
        // `0 === $position` korumasının tek iş gördüğü yer: alan listesinde boş ad varsa
        // `in_array` kontrolü ':x' anahtarını "boş alan + x" diye bölmeye razı olurdu.
        // Çerçevesiz kurulumda böyle bir liste yazılabilir; anahtar yine bölünmemeli.
        $symfony = new RecordingSymfonyTranslator([
            '' => ['x' => 'Boş alandan'],
            'messages' => [':x' => 'Bütün anahtar'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['', 'messages']);

        self::assertSame('Bütün anahtar', $translator->trans(':x'));
        self::assertSame([':x', ':x'], $symfony->askedIds(), 'Anahtar hiçbir alanda bölünmemeli.');
    }

    #[Test]
    public function aPrefixIsOnlyADomainWhenTheDefaultDomainListAllowsIt(): void
    {
        // Varsayılan yapılandırma yalnız 'messages' taşır; 'enum:' önek SAYILMAZ.
        $symfony = new RecordingSymfonyTranslator(['messages' => ['enum:status.open' => 'Bütün anahtar']]);
        $translator = new SymfonyTranslator($symfony);

        self::assertSame('Bütün anahtar', $translator->trans('enum:status.open'));
        self::assertSame(['enum:status.open'], $symfony->askedIds());
        self::assertSame(['messages'], $symfony->askedDomains());
    }

    #[Test]
    public function aMultiCharacterSeparatorIsStrippedByItsFullLength(): void
    {
        // Ayracın uzunluğu hesaba katılmazsa kimlik '::' yerine ':' ile başlar.
        $symfony = new RecordingSymfonyTranslator(['enum' => ['purchase_status.open' => 'Açık']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum'], '::');

        self::assertSame('Açık', $translator->trans('enum::purchase_status.open'));
        self::assertSame(['purchase_status.open'], $symfony->askedIds());
        self::assertSame(['enum'], $symfony->askedDomains());
    }

    // ---------------------------------------------------------------- isabet tespiti

    #[Test]
    public function resolvesAKeyWhoseTranslationCarriesParameters(): void
    {
        $symfony = new RecordingSymfonyTranslator(['messages' => ['greeting.hello' => 'Merhaba %name%']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Merhaba Ada', $translator->trans('greeting.hello', ['name' => 'Ada']));
        self::assertSame(['messages'], $symfony->askedDomains());
    }

    /**
     * KRİTİK DURUM. Anahtarın KENDİSİ yer tutucu taşıdığında Symfony ıskaladığı hâlde ham
     * anahtardan FARKLI bir metin döndürür ('%name% bulunamadı' → 'Ada bulunamadı').
     * Naif `$translated !== $id` karşılaştırması bunu isabet sanar, zincir daha ilk alanda
     * takılır ve ikinci alandaki gerçek çeviri hiç görülmez. Doğru karşılaştırma
     * `strtr($id, $params)` iledir.
     */
    #[Test]
    public function aMissWithParametersFallsThroughToTheNextDomain(): void
    {
        $symfony = new RecordingSymfonyTranslator([
            'enum' => ['%name% bulunamadı' => '%name% kaydı yok'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Ada kaydı yok', $translator->trans('%name% bulunamadı', ['name' => 'Ada']));
        self::assertSame(['messages', 'enum'], $symfony->askedDomains(), 'İlk alan ıska sayılmalı.');
    }

    #[Test]
    public function theNaiveComparisonWouldHaveReportedAHitOnThatKey(): void
    {
        // Yukarıdaki testin neden gerçek bir tuzağı koruduğunu belgeler: 'messages' alanı
        // anahtarı TANIMLAMADIĞI hâlde ham anahtardan farklı bir metin döndürüyor.
        $symfony = new RecordingSymfonyTranslator();

        $missed = $symfony->trans('%name% bulunamadı', ['%name%' => 'Ada'], 'messages');

        self::assertNotSame('%name% bulunamadı', $missed, 'Naif karşılaştırma burada isabet derdi.');
        self::assertSame('Ada bulunamadı', $missed);
    }

    #[Test]
    public function aKeyWithParametersMissingEverywhereReturnsTheSubstitutedKey(): void
    {
        $symfony = new RecordingSymfonyTranslator();
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Ada bulunamadı', $translator->trans('%name% bulunamadı', ['name' => 'Ada']));
        self::assertSame(['messages', 'enum'], $symfony->askedDomains());
    }

    #[Test]
    public function aRealTranslationOfAParameterisedKeyStopsTheChain(): void
    {
        // Aynı tuzaklı anahtarın diğer yüzü: 'messages' bu kez anahtarı GERÇEKTEN tanımlıyor,
        // dolayısıyla ikinci alan hiç sorulmamalı — ıska tespiti fazla hassas olmamalı.
        $symfony = new RecordingSymfonyTranslator([
            'messages' => ['%name% bulunamadı' => 'Kayıt yok'],
            'enum' => ['%name% bulunamadı' => 'Enumdan'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Kayıt yok', $translator->trans('%name% bulunamadı', ['name' => 'Ada']));
        self::assertSame(['messages'], $symfony->askedDomains());
    }

    // ---------------------------------------------------------------- parametre normalleştirme

    /**
     * @param array<string, string|int|float> $params
     */
    #[Test]
    #[DataProvider('equivalentParameterNames')]
    public function normalisesParameterNamesToSymfonyPlaceholders(array $params): void
    {
        $symfony = new RecordingSymfonyTranslator(['messages' => ['total' => 'Toplam: %count%']]);
        $translator = new SymfonyTranslator($symfony, ['messages']);

        self::assertSame('Toplam: 5', $translator->trans('total', $params));
        self::assertSame([['%count%' => '5']], $symfony->askedParams(), 'Symfony yalnız yer tutucu biçimini anlar.');
    }

    /**
     * @return iterable<string, array{array<string, string|int|float>}>
     */
    public static function equivalentParameterNames(): iterable
    {
        yield 'sade ad' => [['count' => 5]];
        yield 'yüzdeyle sarılmış ad' => [['%count%' => 5]];
    }

    #[Test]
    public function parametersReachSymfonyAsStrings(): void
    {
        $symfony = new RecordingSymfonyTranslator(['messages' => ['total' => 'Toplam: %amount%']]);
        $translator = new SymfonyTranslator($symfony, ['messages']);

        self::assertSame('Toplam: 12.5', $translator->trans('total', ['amount' => 12.5]));
        self::assertSame([['%amount%' => '12.5']], $symfony->askedParams());
    }

    #[Test]
    public function normalisedParametersAreAlsoUsedOnTheExplicitDomainPath(): void
    {
        $symfony = new RecordingSymfonyTranslator(['enum' => ['status.count' => '%count% adet']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('3 adet', $translator->trans('enum:status.count', ['count' => 3]));
        self::assertSame([['%count%' => '3']], $symfony->askedParams());
    }

    #[Test]
    public function anEmptyParameterListIsPassedThroughUntouched(): void
    {
        $symfony = new RecordingSymfonyTranslator(['messages' => ['greeting' => 'Merhaba']]);
        $translator = new SymfonyTranslator($symfony, ['messages']);

        self::assertSame('Merhaba', $translator->trans('greeting'));
        self::assertSame([[]], $symfony->askedParams());
    }

    // ---------------------------------------------------------------- dil

    #[Test]
    public function passesTheLocaleToEveryDomainInTheChain(): void
    {
        // Kuyruk işçisinde "istekten gelen locale" yoktur; dil her çağrıda taşınmalı.
        $symfony = new RecordingSymfonyTranslator(['enum' => ['status.open' => 'Açık']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Açık', $translator->trans('status.open', [], 'tr'));
        self::assertSame(['tr', 'tr'], $symfony->askedLocales());
    }

    #[Test]
    public function passesTheLocaleOnTheExplicitDomainPath(): void
    {
        $symfony = new RecordingSymfonyTranslator(['enum' => ['status.open' => 'Açık']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Açık', $translator->trans('enum:status.open', [], 'en'));
        self::assertSame(['en'], $symfony->askedLocales());
        self::assertSame(['enum'], $symfony->askedDomains());
    }

    #[Test]
    public function aNullLocaleIsForwardedAsNullSoSymfonyPicksItsDefault(): void
    {
        $symfony = new RecordingSymfonyTranslator(['enum' => ['status.open' => 'Açık']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Açık', $translator->trans('status.open'));
        self::assertSame([null, null], $symfony->askedLocales());
    }
}

/**
 * Symfony `TranslatorInterface`'inin sahtesi — alan başına düz bir katalog taşır.
 *
 * Tek kritik davranış: anahtar katalogda YOKSA anahtarın kendisi mesaj kabul edilir ve
 * parametreler ona da yerleştirilir (`strtr`). Gerçek Symfony tam olarak böyle davranır;
 * `SymfonyTranslator`'ın isabet tespiti bu sözleşmenin üstüne kurulmuştur.
 */
final class RecordingSymfonyTranslator implements TranslatorInterface
{
    /** @var list<array{id: string, params: array<array-key, mixed>, domain: string|null, locale: string|null}> */
    private array $calls = [];

    /**
     * @param array<string, array<string, string>> $catalogues alan adı => (anahtar => çeviri)
     */
    public function __construct(private readonly array $catalogues = [])
    {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        // Ham parametreler kaydedilir: normalleştirmenin gerçekten yapıldığını ancak
        // dönüştürülmemiş hâli görerek doğrulayabiliriz.
        $this->calls[] = ['id' => $id, 'params' => $parameters, 'domain' => $domain, 'locale' => $locale];

        $message = $this->catalogues[$domain ?? 'messages'][$id] ?? $id;

        return strtr($message, self::stringify($parameters));
    }

    public function getLocale(): string
    {
        return 'tr';
    }

    /** @return list<string> */
    public function askedIds(): array
    {
        return array_map(static fn (array $call): string => $call['id'], $this->calls);
    }

    /** @return list<string|null> */
    public function askedDomains(): array
    {
        return array_map(static fn (array $call): ?string => $call['domain'], $this->calls);
    }

    /** @return list<string|null> */
    public function askedLocales(): array
    {
        return array_map(static fn (array $call): ?string => $call['locale'], $this->calls);
    }

    /** @return list<array<array-key, mixed>> */
    public function askedParams(): array
    {
        return array_map(static fn (array $call): array => $call['params'], $this->calls);
    }

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return array<string, string>
     */
    private static function stringify(array $parameters): array
    {
        $replacements = [];

        foreach ($parameters as $name => $value) {
            $replacements[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return $replacements;
    }
}
