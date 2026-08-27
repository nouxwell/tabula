<?php

declare(strict_types=1);

namespace Balin\Tabula\Bridge\Symfony;

use Balin\Tabula\Export\Writer\CsvOptions;
use Balin\Tabula\Export\Writer\DefaultWriterFactory;
use Balin\Tabula\Export\Writer\WriterFactory;
use Balin\Tabula\Export\Writer\XlsxOptions;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Settings\DateSettings;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Tabula;
use Balin\Tabula\Value\FormatterRegistry;
use Balin\Tabula\Value\ValueResolver;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Tabula'nın Symfony kablolaması.
 *
 * NEDEN GEREKLİ: ana uygulamada servisleri kaydeden tek glob `App\: resource: '../src/'`
 * biçimindedir ve `vendor/` altındaki hiçbir sınıfı görmez. Kütüphane kendi kablolamasını
 * getirmezse tek bir servisi bile otomatik tanımlanmaz. Bu bundle o boşluğu doldurur.
 *
 * Kurulum — `config/bundles.php`:
 *
 *     Balin\Tabula\Bridge\Symfony\TabulaBundle::class => ['all' => true],
 *
 * Yapılandırma — `config/packages/tabula.yaml`:
 *
 *     tabula:
 *         default_locale: '%kernel.default_locale%'
 *         translation:
 *             domains: ['messages', 'enum']
 *         numbers:
 *             currency_symbols:
 *                 TRY: '₺'
 *
 * Ardından `Balin\Tabula\Tabula` her yere otomatik enjekte edilebilir.
 */
final class TabulaBundle extends AbstractBundle
{
    protected string $extensionAlias = 'tabula';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Uygulamada Symfony çevirmeni yoksa portu Passthrough'a bağla — aksi hâlde
        // var olmayan `TranslatorInterface` takma adına bağımlılık tüm konteyneri düşürür.
        $container->addCompilerPass(new TranslatorFallbackPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default_locale')
                    ->defaultValue('%kernel.default_locale%')
                    ->info('Çıktı dili verilmediğinde kullanılacak varsayılan dil.')
                ->end()
                ->scalarNode('empty_text')
                    ->defaultValue('')
                    ->info('Boş hücrede yazılacak metin (ör. "-"). Boş bırakılırsa hücre hiç yaratılmaz.')
                ->end()
                ->scalarNode('bool_true_key')->defaultValue('tabula.bool.yes')->end()
                ->scalarNode('bool_false_key')->defaultValue('tabula.bool.no')->end()
                ->integerNode('max_rows_per_sheet')
                    ->defaultValue(1_048_575)
                    ->min(1)
                    ->max(1_048_575)
                    ->info('Bir sayfaya yazılacak azami satır. Excel tavanı 1.048.576 satırdır (başlık dahil).')
                ->end()

                ->arrayNode('translation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('domains')
                            ->info('Sırayla denenecek çeviri alanları. ERP için genellikle ["messages", "enum"].')
                            ->scalarPrototype()->end()
                            ->defaultValue(['messages'])
                            ->requiresAtLeastOneElement()
                            // Ortam dosyaları listeyi DARALTMAK için yazılır; derin birleştirme
                            // açık kalırsa `prod/tabula.yaml` listeyi daraltmak yerine genişletir
                            // ve aynı alan iki kez sorgulanır.
                            ->performNoDeepMerging()
                        ->end()
                        ->arrayNode('addressable_domains')
                            ->info('`alan:anahtar` önekiyle erişilebilen EK alanlar (ör. ["validators"]). `domains` zaten dahildir.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->performNoDeepMerging()
                        ->end()
                        ->scalarNode('domain_separator')
                            ->defaultValue(':')
                            ->info('Açık alan öneki ayracı, ör. "enum:purchase_status.open".')
                        ->end()
                    ->end()
                ->end()

                ->arrayNode('numbers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('decimal_separator')->defaultValue(',')->end()
                        ->scalarNode('thousand_separator')->defaultValue('.')->end()
                        ->integerNode('decimal_digits')->defaultValue(2)->min(0)->end()
                        ->integerNode('quantity_digits')->defaultValue(3)->min(0)->end()
                        ->integerNode('money_digits')->defaultValue(2)->min(0)->end()
                        ->enumNode('symbol_position')
                            ->values(['before', 'after', 'none'])
                            ->defaultValue('after')
                        ->end()
                        ->arrayNode('currency_symbols')
                            ->info('Para birimi kodu => simge, ör. TRY: "₺".')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()

                ->arrayNode('dates')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('date_pattern')->defaultValue('d.m.Y')->end()
                        ->scalarNode('datetime_pattern')->defaultValue('d.m.Y H:i')->end()
                        ->scalarNode('excel_date_format')->defaultValue('dd.mm.yyyy')->end()
                        ->scalarNode('excel_datetime_format')->defaultValue('dd.mm.yyyy hh:mm')->end()
                    ->end()
                ->end()

                ->arrayNode('csv')
                    ->addDefaultsIfNotSet()
                    ->info('Varsayılanlar Türkçe Excel içindir. Makineye giden besleme için: delimiter "," · escape "" · write_bom false.')
                    ->children()
                        ->scalarNode('delimiter')->defaultValue(';')->cannotBeEmpty()->end()
                        ->scalarNode('enclosure')->defaultValue('"')->cannotBeEmpty()->end()
                        // Kaçış BİLEREK boş bırakılabilir: '' verilince PHP'nin standart dışı
                        // kaçışı kapanır ve çıktı RFC 4180'e birebir uyar. Bu yüzden burada
                        // `cannotBeEmpty()` YOK — ama `escape: ~` yazan kişi de "kapat" demek
                        // istiyordur; null'ı boş dizeye çeviriyoruz, yoksa TypeError'a düşerdi.
                        ->scalarNode('escape')
                            ->defaultValue('\\')
                            ->beforeNormalization()->ifNull()->then(static fn (): string => '')->end()
                        ->end()
                        ->booleanNode('write_bom')->defaultTrue()->end()
                        ->enumNode('line_ending')
                            ->values(['crlf', 'lf'])
                            ->defaultValue('crlf')
                        ->end()
                    ->end()
                ->end()

                ->arrayNode('xlsx')
                    ->addDefaultsIfNotSet()
                    ->info('Renkler ARGB biçimindedir (FFRRGGBB); baştaki iki hane saydamlıktır.')
                    ->children()
                        ->scalarNode('creator')->defaultValue('Tabula')->cannotBeEmpty()->end()
                        ->scalarNode('header_fill')->defaultValue('FFF2F2F2')->cannotBeEmpty()->end()
                        ->scalarNode('required_header_fill')->defaultValue('FFFCE4E4')->cannotBeEmpty()->end()
                        ->scalarNode('header_border_color')->defaultValue('FFBFBFBF')->cannotBeEmpty()->end()
                        ->booleanNode('bold_header')->defaultTrue()->end()
                        ->booleanNode('freeze_header')->defaultTrue()->end()
                        ->booleanNode('auto_filter')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // Ayarlar düz diziden kurulur; kapsayıcı enum örneği taşımak zorunda kalmaz.
        $services->set(NumberSettings::class)
            ->factory([SettingsFactory::class, 'numbers'])
            ->args([$config['numbers']]);

        $services->set(DateSettings::class)
            ->factory([SettingsFactory::class, 'dates'])
            ->args([$config['dates']]);

        $services->set(TabulaSettings::class)
            ->factory([SettingsFactory::class, 'settings'])
            ->args([$config, service(NumberSettings::class), service(DateSettings::class)]);

        // Port → Symfony translator. Alan zinciri yapılandırmadan gelir.
        $services->set(SymfonyTranslator::class)
            ->args([
                service(TranslatorInterface::class),
                $config['translation']['domains'],
                $config['translation']['domain_separator'],
                $config['translation']['addressable_domains'],
            ]);

        $services->alias(Translator::class, SymfonyTranslator::class);

        $services->set(ValueResolver::class);

        $services->set(FormatterRegistry::class)
            ->factory([FormatterRegistry::class, 'default']);

        $services->set(CsvOptions::class)
            ->factory([SettingsFactory::class, 'csv'])
            ->args([$config['csv']]);

        $services->set(XlsxOptions::class)
            ->factory([SettingsFactory::class, 'xlsx'])
            ->args([$config['xlsx']]);

        $services->set(DefaultWriterFactory::class)
            ->args([service(CsvOptions::class), service(XlsxOptions::class)]);

        $services->alias(WriterFactory::class, DefaultWriterFactory::class);

        $services->set(Tabula::class)
            ->args([
                service(Translator::class),
                service(TabulaSettings::class),
                service(FormatterRegistry::class),
                service(ValueResolver::class),
                service(WriterFactory::class),
            ])
            ->public();
    }
}
