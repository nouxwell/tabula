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
 * Köprünün GERÇEKTEN kabloladığını kanıtlar.
 *
 * Bundle'ın varlık sebebi tek bir cümledir: ana uygulamadaki `App\: resource: '../src/'`
 * globu `vendor/` altını görmez, dolayısıyla kütüphane kendi servislerini kaydetmezse
 * tek bir tanesi bile otomatik oluşmaz. Bunu "dosya var mı" diye bakarak doğrulamak
 * anlamsız; burada GERÇEK bir `ContainerBuilder` kurulur, uzantı yüklenir, kapsayıcı
 * DERLENİR ve servisler dışarı alınır. Derlenmeyen bir kapsayıcı, yazım hatası taşıyan
 * bir referansı ya da eksik bir servisi zaten yakalayamazdı.
 *
 * Kapsayıcıya iki dış girdi verilir; ikisi de gerçek bir Symfony çekirdeğinde hazır gelir:
 *  - `%kernel.default_locale%` parametresi (yapılandırmanın varsayılanı buna işaret eder),
 *  - `TranslatorInterface` servisi (köprü `service(TranslatorInterface::class)` kurar).
 */
#[CoversClass(TabulaBundle::class)]
#[CoversClass(SettingsFactory::class)]
final class TabulaBundleTest extends TestCase
{
    private const string KERNEL_LOCALE = 'tr';

    // ---------------------------------------------------------------- kapsayıcı ayağa kalkıyor mu

    #[Test]
    public function theContainerCompilesAndTabulaIsRetrievable(): void
    {
        $tabula = $this->compile()->get(Tabula::class);

        self::assertInstanceOf(Tabula::class, $tabula);
    }

    /**
     * `Tabula` KASITLI olarak `public()` işaretlidir. Uygulama kodu onu tip ipucuyla
     * enjekte eder ama testler, konsol komutları ve `$container->get()` kullanan eski
     * kodlar için doğrudan erişim de gerekir.
     */
    #[Test]
    public function tabulaIsThePublicEntryPointOfTheBundle(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.default_locale', self::KERNEL_LOCALE);
        $this->registerSymfonyTranslator($container);
        $this->loadExtension($container, []);

        // Hiçbir şeyi public'e ÇEKMEDEN derliyoruz: bundle'ın kendi verdiği erişim bu.
        $container->compile();

        self::assertTrue($container->has(Tabula::class));
        self::assertInstanceOf(Tabula::class, $container->get(Tabula::class));
    }

    // ---------------------------------------------------------------- yapılandırma → ayarlar

    /**
     * Asıl risk burada: yapılandırma ağacı düzgün tanımlanmış olsa bile değerlerin
     * `TabulaSettings`'e ULAŞTIĞININ garantisi yok — `loadExtension()` yanlış anahtarı
     * okusa ya da fabrikaya yanlış sırada argüman geçse kapsayıcı yine derlenirdi.
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

        // Düz dizideki 'before' metni enum örneğine SettingsFactory'de çevrilir; kapsayıcı
        // hiçbir zaman enum taşımaz (bkz. SettingsFactory sınıf yorumu).
        self::assertSame(SymbolPosition::Before, $settings->numbers->symbolPosition);

        self::assertSame('Y-m-d', $settings->dates->datePattern);
        self::assertSame('Y-m-d H:i:s', $settings->dates->dateTimePattern);
        self::assertSame('yyyy-mm-dd', $settings->dates->excelDateFormat);
        self::assertSame('yyyy-mm-dd hh:mm:ss', $settings->dates->excelDateTimeFormat);
    }

    /** Ayarları okuyan `Tabula`, kapsayıcıdaki AYNI nesneyi almalı; ikinci bir kopya değil. */
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
        // Varsayılan, Excel'in gerçek satır tavanıdır (1.048.576 − başlık satırı).
        self::assertSame(1_048_575, $settings->maxRowsPerSheet);
        self::assertSame(',', $settings->numbers->decimalSeparator);
        self::assertSame('.', $settings->numbers->thousandSeparator);
        self::assertSame(SymbolPosition::After, $settings->numbers->symbolPosition);
        self::assertSame([], $settings->numbers->currencySymbols);
        self::assertSame('d.m.Y', $settings->dates->datePattern);
    }

    // ---------------------------------------------------------------- varsayılan dil

    /**
     * `default_locale` varsayılanı sabit bir metin değil, `%kernel.default_locale%`
     * parametre referansıdır: kütüphane uygulamanın dilini KENDİLİĞİNDEN devralsın diye.
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
     * Değerin gerçekten bir PARAMETRE REFERANSI olduğunun kanıtı: parametre yoksa derleme
     * çöker. Varsayılan sabit bir metin olsaydı bu test yeşil kalırdı.
     *
     * (Derleyici asıl `ParameterNotFoundException`'ı `DefinitionErrorExceptionPass` içinde
     * yakalayıp DI `RuntimeException`'ına sarar; beklenen tip o yüzden sarmalayıcıdır.)
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

    // ---------------------------------------------------------------- çeviri portu

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
     * Alanların yalnızca yapılandırma ağacında durması yetmez; `SymfonyTranslator`'a
     * ARGÜMAN olarak geçmeleri gerekir. Bu yüzden zincir kapsayıcı üzerinden, uçtan uca
     * denenir: anahtar yalnızca ikinci alanda (`enum`) var.
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
        // Karşı kanıt: yukarıdaki testi geçiren şey yapılandırmanın kendisi, tesadüf değil.
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

    // ---------------------------------------------------------------- yapılandırma ağacı reddediyor mu

    /**
     * Ağacın en değerli işi kabul ettikleri değil, REDDETTİKLERİ: `symbol_position: 'sağ'`
     * sessizce yok sayılsaydı hata ancak aylar sonra, yanlış biçimlenmiş bir Excel'de
     * fark edilirdi.
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
        yield 'bilinmeyen simge konumu' => [
            ['numbers' => ['symbol_position' => 'sideways']],
            'tabula.numbers.symbol_position',
        ];

        yield 'sayfa başına sıfır satır' => [
            ['max_rows_per_sheet' => 0],
            'tabula.max_rows_per_sheet',
        ];

        yield 'negatif ondalık basamak' => [
            ['numbers' => ['decimal_digits' => -1]],
            'tabula.numbers.decimal_digits',
        ];

        yield 'boş alan zinciri' => [
            ['translation' => ['domains' => []]],
            'tabula.translation.domains',
        ];

        // Yazım hatası sessizce yutulmamalı; aksi hâlde ayar "uygulanmıyor" diye saatler yenir.
        yield 'tanınmayan anahtar' => [
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

    // ---------------------------------------------------------------- yardımcılar

    /**
     * Uzantıyı yükler, derler ve kapsayıcıyı döndürür.
     *
     * Kütüphane servisleri (Tabula dışında) ÖZELDİR; gerçek uygulamada tip ipucuyla
     * enjekte edilirler, `get()` ile alınmazlar. Test onları gözleyebilmek için derlemeden
     * ÖNCE public'e çeker — public bir takma ad olmadan `RemovePrivateAliasesPass` port
     * takma adını derleme sırasında kaldırırdı.
     *
     * @param array<string, mixed>                 $config
     * @param array<string, array<string, string>> $catalogue sahte Symfony çevirmeninin kataloğu
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

        self::assertInstanceOf(ExtensionInterface::class, $extension, 'Bundle bir kapsayıcı uzantısı sunmalı.');
        self::assertSame('tabula', $extension->getAlias(), 'Yapılandırma kökü `tabula:` olmalı.');

        $extension->load([$config], $container);
    }

    /**
     * Gerçek çekirdekte `translator` servisi hazırdır; burada onun yerine somut bir sahte
     * kaydedilir, aksi hâlde `service(TranslatorInterface::class)` referansı çözülemez.
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
