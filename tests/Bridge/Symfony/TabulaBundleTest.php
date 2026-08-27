<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Bridge\Symfony;

use Balin\Tabula\Bridge\Symfony\SettingsFactory;
use Balin\Tabula\Bridge\Symfony\SymfonyTranslator;
use Balin\Tabula\Bridge\Symfony\TabulaBundle;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Settings\SymbolPosition;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Tabula;
use Balin\Tabula\Tests\Fixture\StubSymfonyTranslator;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Proves that the bridge REALLY DOES wire things up.
 *
 * The bundle's reason for existing is a single sentence: the host application's
 * `App\: resource: '../src/'` glob does not see under `vendor/`, so unless the library
 * registers its own services, not a single one of them comes into being automatically.
 * Verifying that by checking "does the file exist" would be meaningless; here a REAL
 * `ContainerBuilder` is set up, the extension is loaded, the container is COMPILED and the
 * services are pulled out. A container that is not compiled could not have caught a reference
 * with a typo in it or a missing service in the first place.
 *
 * Two external inputs are given to the container; both come ready-made in a real Symfony kernel:
 *  - the `%kernel.default_locale%` parameter (the configuration's default points at it),
 *  - the `TranslatorInterface` service (the bridge sets up `service(TranslatorInterface::class)`).
 */
#[CoversClass(TabulaBundle::class)]
#[CoversClass(SettingsFactory::class)]
final class TabulaBundleTest extends TestCase
{
    private const string KERNEL_LOCALE = 'tr';

    // ---------------------------------------------------------------- does the container come up

    #[Test]
    public function theContainerCompilesAndTabulaIsRetrievable(): void
    {
        $tabula = $this->compile()->get(Tabula::class);

        self::assertInstanceOf(Tabula::class, $tabula);
    }

    /**
     * `Tabula` is DELIBERATELY marked `public()`. Application code injects it by type hint, but
     * direct access is needed as well for tests, console commands and older code that uses
     * `$container->get()`.
     */
    #[Test]
    public function tabulaIsThePublicEntryPointOfTheBundle(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.default_locale', self::KERNEL_LOCALE);
        $this->registerSymfonyTranslator($container);
        $this->loadExtension($container, []);

        // We compile WITHOUT pulling anything into public: this is the access the bundle
        // itself grants.
        $container->compile();

        self::assertTrue($container->has(Tabula::class));
        self::assertInstanceOf(Tabula::class, $container->get(Tabula::class));
    }

    // ---------------------------------------------------------------- configuration → settings

    /**
     * The real risk is here: even with a properly defined configuration tree there is no
     * guarantee that the values REACH `TabulaSettings` — had `loadExtension()` read the wrong
     * key or passed the arguments to the factory in the wrong order, the container would still
     * have compiled.
     */
    #[Test]
    public function theConfigurationReachesTheResolvedSettings(): void
    {
        $container = $this->compile([
            'empty_text' => '-',
            'bool_true_key' => 'ortak.evet',
            'bool_false_key' => 'ortak.hayir',
            'max_rows_per_sheet' => 500,
            'numbers' => [
                'decimal_separator' => '.',
                'thousand_separator' => ',',
                'decimal_digits' => 4,
                'quantity_digits' => 5,
                'money_digits' => 6,
                'symbol_position' => 'before',
                'currency_symbols' => ['TRY' => '₺', 'USD' => '$'],
            ],
            'dates' => [
                'date_pattern' => 'Y-m-d',
                'datetime_pattern' => 'Y-m-d H:i:s',
                'excel_date_format' => 'yyyy-mm-dd',
                'excel_datetime_format' => 'yyyy-mm-dd hh:mm:ss',
            ],
        ]);

        $settings = $container->get(TabulaSettings::class);
        self::assertInstanceOf(TabulaSettings::class, $settings);

        self::assertSame('-', $settings->emptyText);
        self::assertSame('ortak.evet', $settings->boolTrueKey);
        self::assertSame('ortak.hayir', $settings->boolFalseKey);
        self::assertSame(500, $settings->maxRowsPerSheet);

        self::assertSame('.', $settings->numbers->decimalSeparator);
        self::assertSame(',', $settings->numbers->thousandSeparator);
        self::assertSame(4, $settings->numbers->decimalDigits);
        self::assertSame(5, $settings->numbers->quantityDigits);
        self::assertSame(6, $settings->numbers->moneyDigits);
        self::assertSame(['TRY' => '₺', 'USD' => '$'], $settings->numbers->currencySymbols);

        // The text 'before' from the plain array is turned into an enum instance in
        // SettingsFactory; the container never carries an enum (see the SettingsFactory class
        // comment).
        self::assertSame(SymbolPosition::Before, $settings->numbers->symbolPosition);

        self::assertSame('Y-m-d', $settings->dates->datePattern);
        self::assertSame('Y-m-d H:i:s', $settings->dates->dateTimePattern);
        self::assertSame('yyyy-mm-dd', $settings->dates->excelDateFormat);
        self::assertSame('yyyy-mm-dd hh:mm:ss', $settings->dates->excelDateTimeFormat);
    }

    /** `Tabula`, which reads the settings, must get THE SAME object as the container has; not a second copy. */
    #[Test]
    public function tabulaReceivesTheSettingsServiceItself(): void
    {
        $container = $this->compile(['empty_text' => '—']);

        $tabula = $container->get(Tabula::class);
        self::assertInstanceOf(Tabula::class, $tabula);

        self::assertSame($container->get(TabulaSettings::class), $tabula->settings());
        self::assertSame('—', $tabula->settings()->emptyText);
    }

    #[Test]
    public function anEmptyConfigurationYieldsTheDocumentedDefaults(): void
    {
        $settings = $this->compile()->get(TabulaSettings::class);
        self::assertInstanceOf(TabulaSettings::class, $settings);

        self::assertSame('', $settings->emptyText);
        self::assertSame('tabula.bool.yes', $settings->boolTrueKey);
        self::assertSame('tabula.bool.no', $settings->boolFalseKey);
        // The default is Excel's real row ceiling (1,048,576 − the header row).
        self::assertSame(1_048_575, $settings->maxRowsPerSheet);
        self::assertSame(',', $settings->numbers->decimalSeparator);
        self::assertSame('.', $settings->numbers->thousandSeparator);
        self::assertSame(SymbolPosition::After, $settings->numbers->symbolPosition);
        self::assertSame([], $settings->numbers->currencySymbols);
        self::assertSame('d.m.Y', $settings->dates->datePattern);
    }

    // ---------------------------------------------------------------- default locale

    /**
     * The default for `default_locale` is not a fixed text but a reference to the
     * `%kernel.default_locale%` parameter: so that the library inherits the application's
     * language BY ITSELF.
     */
    #[Test]
    public function theDefaultLocaleFallsBackToTheKernelParameter(): void
    {
        $settings = $this->compile()->get(TabulaSettings::class);
        self::assertInstanceOf(TabulaSettings::class, $settings);

        self::assertSame(self::KERNEL_LOCALE, $settings->defaultLocale);
    }

    #[Test]
    public function anExplicitDefaultLocaleWinsOverTheKernelParameter(): void
    {
        $settings = $this->compile(['default_locale' => 'de'])->get(TabulaSettings::class);
        self::assertInstanceOf(TabulaSettings::class, $settings);

        self::assertSame('de', $settings->defaultLocale);
    }

    /**
     * The proof that the value really is a PARAMETER REFERENCE: without the parameter, the
     * compilation collapses. Had the default been a fixed text, this test would have stayed
     * green.
     *
     * (The compiler catches the actual `ParameterNotFoundException` inside
     * `DefinitionErrorExceptionPass` and wraps it in the DI `RuntimeException`; that is why the
     * expected type is the wrapper.)
     */
    #[Test]
    public function theDefaultLocaleIsAParameterReferenceNotAHardcodedString(): void
    {
        $container = new ContainerBuilder();
        $this->registerSymfonyTranslator($container);
        $this->loadExtension($container, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kernel.default_locale');

        $container->compile();
    }

    // ---------------------------------------------------------------- translation port

    #[Test]
    public function theTranslatorPortResolvesToTheSymfonyAdapter(): void
    {
        $container = $this->compile();

        $translator = $container->get(Translator::class);

        self::assertInstanceOf(SymfonyTranslator::class, $translator);
        self::assertInstanceOf(Translator::class, $translator);
    }

    #[Test]
    public function tabulaReceivesTheAdapterThroughThePortAlias(): void
    {
        $container = $this->compile();

        $tabula = $container->get(Tabula::class);
        self::assertInstanceOf(Tabula::class, $tabula);

        self::assertSame($container->get(Translator::class), $tabula->translator());
    }

    /**
     * It is not enough for the domains merely to sit in the configuration tree; they have to
     * reach `SymfonyTranslator` as an ARGUMENT. That is why the chain is exercised end to end
     * through the container: the key exists only in the second domain (`enum`).
     */
    #[Test]
    public function theConfiguredDomainChainReachesTheAdapter(): void
    {
        $catalogue = ['enum' => ['purchase_status.open' => 'Açık']];

        $withChain = $this->compile(['translation' => ['domains' => ['messages', 'enum']]], $catalogue);
        $translator = $withChain->get(Translator::class);
        self::assertInstanceOf(Translator::class, $translator);

        self::assertSame('Açık', $translator->trans('purchase_status.open'));
    }

    #[Test]
    public function theDefaultDomainChainOnlyLooksAtMessages(): void
    {
        // The counter-proof: what makes the test above pass is the configuration itself, not coincidence.
        $catalogue = ['enum' => ['purchase_status.open' => 'Açık']];

        $translator = $this->compile([], $catalogue)->get(Translator::class);
        self::assertInstanceOf(Translator::class, $translator);

        self::assertSame('purchase_status.open', $translator->trans('purchase_status.open'));
    }

    #[Test]
    public function theConfiguredDomainSeparatorReachesTheAdapter(): void
    {
        $catalogue = ['enum' => ['purchase_status.open' => 'Açık']];

        $translator = $this
            ->compile(['translation' => ['domains' => ['messages', 'enum'], 'domain_separator' => '@']], $catalogue)
            ->get(Translator::class);
        self::assertInstanceOf(Translator::class, $translator);

        self::assertSame('Açık', $translator->trans('enum@purchase_status.open'));
    }

    // ---------------------------------------------------------------- does the configuration tree refuse

    /**
     * The tree's most valuable work is not what it accepts but what it REFUSES: had
     * `symbol_position: 'sağ'` been silently ignored, the mistake would only have been noticed
     * months later, in a badly formatted Excel file.
     *
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('invalidConfigurations')]
    public function theConfigurationTreeRejectsBadValues(array $config, string $expectedMessageFragment): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($expectedMessageFragment, '/').'/');

        $this->compile($config);
    }

    /** @return Generator<string, array{array<string, mixed>, string}> */
    public static function invalidConfigurations(): Generator
    {
        yield 'unknown symbol position' => [
            ['numbers' => ['symbol_position' => 'sideways']],
            'tabula.numbers.symbol_position',
        ];

        yield 'zero rows per sheet' => [
            ['max_rows_per_sheet' => 0],
            'tabula.max_rows_per_sheet',
        ];

        yield 'negative decimal digits' => [
            ['numbers' => ['decimal_digits' => -1]],
            'tabula.numbers.decimal_digits',
        ];

        yield 'empty domain chain' => [
            ['translation' => ['domains' => []]],
            'tabula.translation.domains',
        ];

        // A typo must not be swallowed silently; otherwise hours are eaten up over a setting
        // that "is not being applied".
        yield 'unrecognised key' => [
            ['empty_txt' => '-'],
            'empty_txt',
        ];
    }

    #[Test]
    public function theRejectedSymbolPositionErrorListsThePermissibleValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"before".+"after".+"none"/');

        $this->compile(['numbers' => ['symbol_position' => 'sideways']]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Loads the extension, compiles and returns the container.
     *
     * The library's services (apart from Tabula) are PRIVATE; in a real application they are
     * injected by type hint, not fetched with `get()`. So that it can observe them, the test
     * pulls them into public BEFORE compiling — without a public alias,
     * `RemovePrivateAliasesPass` would remove the port alias during compilation.
     *
     * @param array<string, mixed>                 $config
     * @param array<string, array<string, string>> $catalogue the catalogue of the fake Symfony translator
     */
    private function compile(array $config = [], array $catalogue = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.default_locale', self::KERNEL_LOCALE);

        $this->registerSymfonyTranslator($container, $catalogue);
        $this->loadExtension($container, $config);

        $container->getDefinition(TabulaSettings::class)->setPublic(true);
        $container->getDefinition(SymfonyTranslator::class)->setPublic(true);
        $container->getAlias(Translator::class)->setPublic(true);

        $container->compile();

        return $container;
    }

    /** @param array<string, mixed> $config */
    private function loadExtension(ContainerBuilder $container, array $config): void
    {
        $extension = (new TabulaBundle())->getContainerExtension();

        self::assertInstanceOf(ExtensionInterface::class, $extension, 'The bundle must offer a container extension.');
        self::assertSame('tabula', $extension->getAlias(), 'The configuration root must be `tabula:`.');

        $extension->load([$config], $container);
    }

    /**
     * In a real kernel the `translator` service is ready-made; here a concrete fake is
     * registered in its place, otherwise the `service(TranslatorInterface::class)` reference
     * could not be resolved.
     *
     * @param array<string, array<string, string>> $catalogue
     */
    private function registerSymfonyTranslator(ContainerBuilder $container, array $catalogue = []): void
    {
        $container->register(TranslatorInterface::class, StubSymfonyTranslator::class)
            ->setArguments([$catalogue])
            ->setPublic(true);
    }
}
