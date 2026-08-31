<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Bridge\Symfony;

use Nouxwell\Tabula\Export\Writer\CsvOptions;
use Nouxwell\Tabula\Export\Writer\DefaultWriterFactory;
use Nouxwell\Tabula\Export\Writer\PdfOptions;
use Nouxwell\Tabula\Export\Writer\WriterFactory;
use Nouxwell\Tabula\Export\Writer\XlsxOptions;
use Nouxwell\Tabula\Import\Reader\ReaderRegistry;
use Nouxwell\Tabula\Port\Translator;
use Nouxwell\Tabula\Settings\DateSettings;
use Nouxwell\Tabula\Settings\NumberSettings;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Template\TemplateBuilder;
use Nouxwell\Tabula\Template\TemplateOptions;
use Nouxwell\Tabula\Value\FormatterRegistry;
use Nouxwell\Tabula\Value\ParserRegistry;
use Nouxwell\Tabula\Value\ValueResolver;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Tabula's Symfony wiring.
 *
 * WHY THIS IS NEEDED: in the host application the only glob that registers services is of the
 * form `App\: resource: '../src/'`, and it sees no class under `vendor/`. If the library does
 * not bring its own wiring along, not a single one of its services gets autowired. This bundle
 * fills that gap.
 *
 * Installation — `config/bundles.php`:
 *
 *     Nouxwell\Tabula\Bridge\Symfony\TabulaBundle::class => ['all' => true],
 *
 * Configuration — `config/packages/tabula.yaml`:
 *
 *     tabula:
 *         default_locale: '%kernel.default_locale%'
 *         translation:
 *             domains: ['messages', 'enum']
 *         numbers:
 *             currency_symbols:
 *                 TRY: '₺'
 *         pdf:
 *             page_size: a4
 *             orientation: landscape
 *         template:
 *             sample_rows: 5
 *
 * After that, `Nouxwell\Tabula\Tabula` can be autowired anywhere.
 */
final class TabulaBundle extends AbstractBundle
{
    protected string $extensionAlias = 'tabula';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // If the application has no Symfony translator, bind the port to Passthrough —
        // otherwise a dependency on the non-existent `TranslatorInterface` alias brings the
        // whole container down.
        $container->addCompilerPass(new TranslatorFallbackPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default_locale')
                    ->defaultValue('%kernel.default_locale%')
                    ->info('The default language used when no output language is given.')
                ->end()
                ->scalarNode('empty_text')
                    ->defaultValue('')
                    ->info('The text written into an empty cell (e.g. "-"). If left empty, the cell is not created at all.')
                ->end()
                // Words, not translation keys — see TabulaSettings::$boolTrueKey. A key the
                // catalogue does not define is handed straight back and written into the cell
                // as it stands, so a key here silently prints itself.
                ->scalarNode('bool_true_key')
                    ->defaultValue('Yes')
                    ->info('What a true cell says. A plain word is written as-is; a translation key is resolved.')
                ->end()
                ->scalarNode('bool_false_key')
                    ->defaultValue('No')
                    ->info('What a false cell says. A plain word is written as-is; a translation key is resolved.')
                ->end()
                ->integerNode('max_rows_per_sheet')
                    ->defaultValue(1_048_575)
                    ->min(1)
                    ->max(1_048_575)
                    ->info('The maximum number of rows written to one sheet. Excel\'s ceiling is 1,048,576 rows (header included).')
                ->end()

                ->arrayNode('translation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('domains')
                            ->info('The translation domains, tried in order. In an ERP-style application typically ["messages", "enum"].')
                            ->scalarPrototype()->end()
                            ->defaultValue(['messages'])
                            ->requiresAtLeastOneElement()
                            // Environment files are written to NARROW the list; if deep merging
                            // is left on, `prod/tabula.yaml` widens the list instead of
                            // narrowing it and the same domain is queried twice.
                            ->performNoDeepMerging()
                        ->end()
                        ->arrayNode('addressable_domains')
                            ->info('EXTRA domains reachable with a `domain:key` prefix (e.g. ["validators"]). `domains` is already included.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->performNoDeepMerging()
                        ->end()
                        ->scalarNode('domain_separator')
                            ->defaultValue(':')
                            ->info('The separator of the explicit domain prefix, e.g. "enum:purchase_status.open".')
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
                            ->info('Currency code => symbol, e.g. TRY: "₺".')
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
                    ->info('The defaults target Turkish Excel. For a machine-bound feed: delimiter "," · escape "" · write_bom false.')
                    ->children()
                        ->scalarNode('delimiter')->defaultValue(';')->cannotBeEmpty()->end()
                        ->scalarNode('enclosure')->defaultValue('"')->cannotBeEmpty()->end()
                        // The escape may DELIBERATELY be left empty: passing '' turns off PHP's
                        // non-standard escaping and the output conforms to RFC 4180 exactly.
                        // That is why there is NO `cannotBeEmpty()` here — but someone writing
                        // `escape: ~` also means "turn it off"; we convert null into an empty
                        // string, otherwise it would end up in a TypeError.
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
                    ->info('Colours are in ARGB form (FFRRGGBB); the leading two digits are the alpha channel.')
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

                ->arrayNode('template')
                    ->addDefaultsIfNotSet()
                    ->info('The empty import template. The header APPEARANCE deliberately lives in the `xlsx` node rather than here: the template must be a mirror of the exported file.')
                    ->children()
                        ->booleanNode('include_key_row')
                            ->defaultTrue()
                            ->info('Write the canonical field keys into row 1. Turning it off condemns the file to matching BY LABEL: a single word changed in the translation breaks every template users have on disk — that was the fatal flaw of the system this replaces. Turn it off only when handing the template to another system as a feed.')
                        ->end()
                        ->booleanNode('hide_key_row')
                            ->defaultTrue()
                            ->info('Whether the key row is hidden in Excel. A hidden row REMAINS in the file; the user does not see the technical keys, the import still does.')
                        ->end()
                        // An `integerNode`, but one that ACCEPTS null: `sample_rows: ~` is the
                        // only way to take back an inherited value, and a raw `IntegerNode`
                        // blows up there with "Expected int, but got null". `csv.escape` and
                        // `pdf.max_columns` had fallen into the same trap (see the notes over
                        // there).
                        //
                        // `min()` is DELIBERATELY absent: `TemplateOptions` already counts a
                        // value below zero as "none", and that rule is the value object's job.
                        // Type checking belongs to the tree, range checking to the value object.
                        ->integerNode('sample_rows')
                            ->defaultValue(0)
                            ->beforeNormalization()->ifNull()->then(static fn (): int => 0)->end()
                            ->info('How many pre-formatted empty rows are created beneath the header. 0 = none.')
                        ->end()
                    ->end()
                ->end()

                ->arrayNode('pdf')
                    ->addDefaultsIfNotSet()
                    ->info('The default is A4 LANDSCAPE: on portrait paper a ten-column list overflows the page and the last columns are never printed at all.')
                    ->children()
                        ->enumNode('page_size')
                            ->values(['a3', 'a4', 'a5', 'letter', 'legal'])
                            ->defaultValue('a4')
                        ->end()
                        ->enumNode('orientation')
                            ->values(['portrait', 'landscape'])
                            ->defaultValue('landscape')
                        ->end()
                        // That the dimensions are POSITIVE is already checked on the
                        // `Page`/`ColumnBudget`/`PdfOptions` side, and the messages over there
                        // also tell you the fix ("use A3 instead of A4, turn it landscape,
                        // reduce the margin…"). Repeating half of the same rule here with
                        // `min()` would be a small copy of the very thing this phase did away
                        // with — the same truth living in two places. Type checking belongs to
                        // the tree, range checking to the value object.
                        ->floatNode('margin_mm')
                            ->defaultValue(10.0)
                            ->info('The same margin on all four sides (mm).')
                        ->end()
                        ->floatNode('min_column_width_mm')
                            ->defaultValue(22.0)
                            ->info('The readability floor. The page budget is computed from it: (paper width − margins) ÷ this value.')
                        ->end()
                        // NOT an `integerNode`. An environment file saying `max_columns: ~`
                        // (no ceiling) blows up in `IntegerNode` with "Expected int, but got
                        // null"; yet `~` is the only meaningful way of resetting this setting
                        // — there is no other way to take back an inherited value.
                        // `csv.escape` had fallen into the same trap (see the null
                        // normalisation over there).
                        ->scalarNode('max_columns')
                            ->defaultNull()
                            ->info('Do not print more columns than this, even if the width would allow it. null = no hard ceiling.')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => null !== $value && (!is_int($value) || $value < 1))
                                ->thenInvalid('The maximum column count must be at least 1, or "~" (no ceiling); %s given.')
                            ->end()
                        ->end()
                        ->enumNode('overflow')
                            ->values(['next_page_set', 'drop', 'shrink'])
                            ->defaultValue('next_page_set')
                            ->info('Columns that do not fit: next_page_set = a separate set of pages · drop = DISCARD the low-priority ones (loses data) · shrink = squeeze them all in.')
                        ->end()
                        // Cannot be left empty: `""` lands in the CSS as `"",sans-serif` and
                        // Dompdf falls back to a Latin-1 core font — "ş ğ ı İ" are not in that
                        // set, meaning the output is SILENTLY printed with letters missing.
                        ->scalarNode('font_family')
                            ->defaultValue('DejaVu Sans')
                            ->cannotBeEmpty()
                            ->info('Must be a family that covers Latin Extended-A. The only such family shipped with Dompdf is DejaVu Sans.')
                        ->end()
                        ->floatNode('font_size_pt')->defaultValue(8.0)->end()
                        ->booleanNode('repeat_header')
                            ->defaultTrue()
                            ->info('Whether the header row is repeated on every page. With it off, from the second page on there is no telling which column is which.')
                        ->end()
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

        // The settings are built from a plain array; the container is never forced to carry an
        // enum instance.
        $services->set(NumberSettings::class)
            ->factory([SettingsFactory::class, 'numbers'])
            ->args([$config['numbers']]);

        $services->set(DateSettings::class)
            ->factory([SettingsFactory::class, 'dates'])
            ->args([$config['dates']]);

        $services->set(TabulaSettings::class)
            ->factory([SettingsFactory::class, 'settings'])
            ->args([$config, service(NumberSettings::class), service(DateSettings::class)]);

        // Port → Symfony translator. The domain chain comes from the configuration.
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

        // The import's two registries. Both carry only the built-ins and are extended by being
        // COPIED with `with()`; that is why they have no configuration nodes. When a project
        // wants to register its own date dialect or its own file type, it overrides these
        // services with its own definition (its own factory in place of `->factory()`).
        $services->set(ParserRegistry::class)
            ->factory([ParserRegistry::class, 'default']);

        $services->set(ReaderRegistry::class)
            ->factory([ReaderRegistry::class, 'default']);

        $services->set(CsvOptions::class)
            ->factory([SettingsFactory::class, 'csv'])
            ->args([$config['csv']]);

        $services->set(XlsxOptions::class)
            ->factory([SettingsFactory::class, 'xlsx'])
            ->args([$config['xlsx']]);

        $services->set(PdfOptions::class)
            ->factory([SettingsFactory::class, 'pdf'])
            ->args([$config['pdf']]);

        $services->set(DefaultWriterFactory::class)
            ->args([service(CsvOptions::class), service(XlsxOptions::class), service(PdfOptions::class)]);

        $services->alias(WriterFactory::class, DefaultWriterFactory::class);

        // It does NOT GO THROUGH `SettingsFactory`: that factory exists for the places where
        // the core settings classes carry an enum or a derived value (see the class comment).
        // `TemplateOptions` carries only three scalars and the shared `XlsxOptions`, so it can
        // be built directly.
        //
        // ★ The appearance setting comes from the service SHARED WITH EXPORT: a user who
        // downloads and fills in a template must see the same header as in the file they
        // exported (so that the one learned visual cue, the red fill of the required columns,
        // does not need maintaining separately in two places).
        $services->set(TemplateOptions::class)
            ->args([
                $config['template']['include_key_row'],
                $config['template']['hide_key_row'],
                $config['template']['sample_rows'],
                service(XlsxOptions::class),
            ]);

        // `Tabula::template()` sets up its own writer; this registration is for code that wants
        // to inject template generation by type hint without going through `Tabula`.
        $services->set(TemplateBuilder::class)
            ->args([
                service(Translator::class),
                service(TabulaSettings::class),
                service(TemplateOptions::class),
            ]);

        $services->set(Tabula::class)
            ->args([
                service(Translator::class),
                service(TabulaSettings::class),
                service(FormatterRegistry::class),
                service(ValueResolver::class),
                service(WriterFactory::class),
                service(ParserRegistry::class),
                service(ReaderRegistry::class),
                service(TemplateOptions::class),
            ])
            ->public();
    }
}
