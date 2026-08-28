<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Bridge\Symfony;

use Nouxwell\Tabula\Bridge\Symfony\SymfonyTranslator;
use Nouxwell\Tabula\Port\Translator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The contract of the Symfony translation bridge.
 *
 * The whole difficulty of the adapter comes out of one single place: when Symfony CANNOT FIND
 * a key it does not raise an error, it returns the key itself. The answer to "was it found?"
 * can therefore only be given by looking at the TEXT that came back — and the domain chain
 * (`messages` → `enum`) rests entirely on that decision. The fake translator below reproduces
 * real Symfony's miss behaviour exactly (`strtr($id, $params)`); the value of these tests
 * depends on that.
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
     * The fidelity of the fake: every miss test after this one trusts that this behaviour is
     * imitated correctly. If the key is not in the catalogue, Symfony uses the key as the
     * message and STILL substitutes the parameters — the text it returns is not equal to the
     * raw key.
     */
    #[Test]
    public function theFakeReproducesSymfonysMissBehaviour(): void
    {
        $symfony = new RecordingSymfonyTranslator();

        self::assertSame('greeting.hello', $symfony->trans('greeting.hello', [], 'messages'));
        self::assertSame('Ada bulunamadı', $symfony->trans('%name% bulunamadı', ['%name%' => 'Ada'], 'messages'));
    }

    // ---------------------------------------------------------------- domain chain

    #[Test]
    public function findsAKeyThatOnlyTheSecondDomainDefines(): void
    {
        // The real scenario in the system this replaces: labels in `messages`, enum
        // counterparts in the `enum` domain.
        $symfony = new RecordingSymfonyTranslator([
            'messages' => ['export.customer.code' => 'Kod'],
            'enum' => ['purchase_status.open' => 'Açık'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Açık', $translator->trans('purchase_status.open'));
        self::assertSame(['messages', 'enum'], $symfony->askedDomains(), 'The first domain must be tried first.');
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
        self::assertSame(['messages'], $symfony->askedDomains(), 'After a hit the chain must stop.');
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
        // A missing translation must produce a visible technical key, not an empty cell.
        $symfony = new RecordingSymfonyTranslator(['messages' => ['known' => 'Bilinen']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('export.customer.iban', $translator->trans('export.customer.iban'));
        self::assertSame(['messages', 'enum'], $symfony->askedDomains(), 'Every domain must be tried.');
    }

    #[Test]
    public function anEmptyDomainListNeverAsksSymfonyAndReturnsTheKey(): void
    {
        // The bundle says `requiresAtLeastOneElement()`; even so, it must not crash in the
        // degenerate case.
        $symfony = new RecordingSymfonyTranslator(['messages' => ['greeting' => 'Merhaba']]);
        $translator = new SymfonyTranslator($symfony, []);

        self::assertSame('greeting', $translator->trans('greeting'));
        self::assertSame([], $symfony->askedDomains());
    }

    // ---------------------------------------------------------------- explicit domain prefix

    #[Test]
    public function anExplicitPrefixGoesStraightToThatDomain(): void
    {
        $symfony = new RecordingSymfonyTranslator([
            'messages' => ['purchase_status.open' => 'Mesajlardan'],
            'enum' => ['purchase_status.open' => 'Açık'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Açık', $translator->trans('enum:purchase_status.open'));
        self::assertSame(['enum'], $symfony->askedDomains(), 'With an explicit prefix the chain must not run at all.');
        self::assertSame(['purchase_status.open'], $symfony->askedIds(), 'The prefix must be parsed off and dropped.');
    }

    #[Test]
    public function anExplicitPrefixDoesNotFallBackToTheOtherDomains(): void
    {
        // An explicit prefix is a declaration of INTENT: "this key is over there". Silently
        // drifting to another domain when it is not found would hide where the mistake is.
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
        // 'foo' is not a configured domain; keys that happen to contain a colon must not be
        // split by mistake.
        $symfony = new RecordingSymfonyTranslator(['messages' => ['foo:bar' => 'Değer']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Değer', $translator->trans('foo:bar'));
        self::assertSame(['foo:bar'], $symfony->askedIds(), 'The key must pass through unsplit.');
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
        // An empty prefix cannot be a domain name; ':x' is a key in its entirety.
        $symfony = new RecordingSymfonyTranslator(['messages' => [':x' => 'İki nokta ile başlayan']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('İki nokta ile başlayan', $translator->trans(':x'));
        self::assertSame([':x'], $symfony->askedIds());
    }

    #[Test]
    public function aLeadingSeparatorIsNotSplitEvenWhenAnEmptyDomainIsConfigured(): void
    {
        // The only place the `0 === $position` guard does any work: if the domain list contains
        // an empty name, the `in_array` check would happily split the key ':x' into "empty
        // domain + x". Outside a framework such a list can be written; the key must still not
        // be split.
        $symfony = new RecordingSymfonyTranslator([
            '' => ['x' => 'Boş alandan'],
            'messages' => [':x' => 'Bütün anahtar'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['', 'messages']);

        self::assertSame('Bütün anahtar', $translator->trans(':x'));
        self::assertSame([':x', ':x'], $symfony->askedIds(), 'The key must not be split in any domain.');
    }

    #[Test]
    public function aPrefixIsOnlyADomainWhenTheDefaultDomainListAllowsIt(): void
    {
        // The default configuration carries only 'messages'; 'enum:' DOES NOT COUNT as a prefix.
        $symfony = new RecordingSymfonyTranslator(['messages' => ['enum:status.open' => 'Bütün anahtar']]);
        $translator = new SymfonyTranslator($symfony);

        self::assertSame('Bütün anahtar', $translator->trans('enum:status.open'));
        self::assertSame(['enum:status.open'], $symfony->askedIds());
        self::assertSame(['messages'], $symfony->askedDomains());
    }

    #[Test]
    public function aMultiCharacterSeparatorIsStrippedByItsFullLength(): void
    {
        // If the separator's length is not taken into account, the id starts with ':' instead of '::'.
        $symfony = new RecordingSymfonyTranslator(['enum' => ['purchase_status.open' => 'Açık']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum'], '::');

        self::assertSame('Açık', $translator->trans('enum::purchase_status.open'));
        self::assertSame(['purchase_status.open'], $symfony->askedIds());
        self::assertSame(['enum'], $symfony->askedDomains());
    }

    // ---------------------------------------------------------------- hit detection

    #[Test]
    public function resolvesAKeyWhoseTranslationCarriesParameters(): void
    {
        $symfony = new RecordingSymfonyTranslator(['messages' => ['greeting.hello' => 'Merhaba %name%']]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Merhaba Ada', $translator->trans('greeting.hello', ['name' => 'Ada']));
        self::assertSame(['messages'], $symfony->askedDomains());
    }

    /**
     * THE CRITICAL CASE. When the key ITSELF carries a placeholder, Symfony returns a text
     * DIFFERENT from the raw key even though it missed ('%name% bulunamadı' → 'Ada
     * bulunamadı'). A naive `$translated !== $id` comparison takes that for a hit, the chain
     * gets stuck on the very first domain, and the real translation in the second domain is
     * never seen. The correct comparison is against `strtr($id, $params)`.
     */
    #[Test]
    public function aMissWithParametersFallsThroughToTheNextDomain(): void
    {
        $symfony = new RecordingSymfonyTranslator([
            'enum' => ['%name% bulunamadı' => '%name% kaydı yok'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Ada kaydı yok', $translator->trans('%name% bulunamadı', ['name' => 'Ada']));
        self::assertSame(['messages', 'enum'], $symfony->askedDomains(), 'The first domain must count as a miss.');
    }

    #[Test]
    public function theNaiveComparisonWouldHaveReportedAHitOnThatKey(): void
    {
        // Documents why the test above guards a real trap: the 'messages' domain DOES NOT
        // DEFINE the key and yet returns a text different from the raw key.
        $symfony = new RecordingSymfonyTranslator();

        $missed = $symfony->trans('%name% bulunamadı', ['%name%' => 'Ada'], 'messages');

        self::assertNotSame('%name% bulunamadı', $missed, 'The naive comparison would call this a hit.');
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
        // The other face of the same trapped key: this time 'messages' REALLY DOES define the
        // key, so the second domain must never be asked — miss detection must not be
        // over-sensitive.
        $symfony = new RecordingSymfonyTranslator([
            'messages' => ['%name% bulunamadı' => 'Kayıt yok'],
            'enum' => ['%name% bulunamadı' => 'Enumdan'],
        ]);
        $translator = new SymfonyTranslator($symfony, ['messages', 'enum']);

        self::assertSame('Kayıt yok', $translator->trans('%name% bulunamadı', ['name' => 'Ada']));
        self::assertSame(['messages'], $symfony->askedDomains());
    }

    // ---------------------------------------------------------------- parameter normalisation

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
        self::assertSame([['%count%' => '5']], $symfony->askedParams(), 'Symfony only understands the placeholder form.');
    }

    /**
     * @return iterable<string, array{array<string, string|int|float>}>
     */
    public static function equivalentParameterNames(): iterable
    {
        yield 'plain name' => [['count' => 5]];
        yield 'name wrapped in percent signs' => [['%count%' => 5]];
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

    // ---------------------------------------------------------------- locale

    #[Test]
    public function passesTheLocaleToEveryDomainInTheChain(): void
    {
        // In a queue worker there is no "locale from the request"; the language has to be
        // carried on every call.
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
 * A fake of Symfony's `TranslatorInterface` — it carries a flat catalogue per domain.
 *
 * The one critical behaviour: if the key IS NOT in the catalogue, the key itself is taken as
 * the message and the parameters are substituted into it too (`strtr`). Real Symfony behaves
 * exactly like that; `SymfonyTranslator`'s hit detection is built on top of that contract.
 */
final class RecordingSymfonyTranslator implements TranslatorInterface
{
    /** @var list<array{id: string, params: array<array-key, mixed>, domain: string|null, locale: string|null}> */
    private array $calls = [];

    /**
     * @param array<string, array<string, string>> $catalogues domain name => (key => translation)
     */
    public function __construct(private readonly array $catalogues = [])
    {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        // The raw parameters are recorded: we can only verify that the normalisation really
        // happened by seeing the untransformed form.
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
