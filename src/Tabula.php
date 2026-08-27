<?php

declare(strict_types=1);

namespace Balin\Tabula;

use Balin\Tabula\Export\ExportBuilder;
use Balin\Tabula\Export\Writer\DefaultWriterFactory;
use Balin\Tabula\Export\Writer\WriterFactory;
use Balin\Tabula\Import\ImportBuilder;
use Balin\Tabula\Import\Reader\ReaderRegistry;
use Balin\Tabula\Port\PassthroughTranslator;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Template\TemplateBuilder;
use Balin\Tabula\Template\TemplateOptions;
use Balin\Tabula\Value\FormatterRegistry;
use Balin\Tabula\Value\ParserRegistry;
use Balin\Tabula\Value\ValueResolver;

/**
 * Kütüphanenin giriş noktası.
 *
 * Çerçevesiz kullanımda doğrudan kurulur; Symfony köprüsü aynı nesneyi servis olarak kaydeder.
 *
 *     $tabula = new Tabula(new ArrayTranslator($catalogues), $settings);
 *
 * TEK ŞEMA, ÜÇ YÖN — üçü de aynı `Schema` nesnesinden beslenir, bu yüzden dışa aktarılan
 * dosya hiçbir dönüşüm olmadan geri içe aktarılabilir:
 *
 *     // 1. dışa aktarma
 *     $tabula->export($schema)
 *         ->from(ArraySource::of($rows))
 *         ->locale('tr')
 *         ->to(Format::Xlsx)
 *         ->write('/tmp/musteriler.xlsx');
 *
 *     // 2. boş şablon (1. satır gizli kanonik anahtarlar, 2. satır çeviri, 3. satır veri)
 *     $tabula->template()->write($schema, '/tmp/sablon.xlsx', 'tr');
 *
 *     // 3. içe aktarma
 *     $sonuc = $tabula->import($schema)
 *         ->from('/tmp/doldurulmus.xlsx')
 *         ->locale('tr')
 *         ->each(static fn (ImportedRow $row) => $repository->save($row->toArray()))
 *         ->run();
 */
final class Tabula
{
    private readonly FormatterRegistry $formatters;

    private readonly ValueResolver $resolver;

    private readonly WriterFactory $writers;

    private readonly ParserRegistry $parsers;

    private readonly ReaderRegistry $readers;

    private readonly TemplateOptions $templateOptions;

    public function __construct(
        private readonly Translator $translator = new PassthroughTranslator(),
        private readonly TabulaSettings $settings = new TabulaSettings(),
        ?FormatterRegistry $formatters = null,
        ?ValueResolver $resolver = null,
        ?WriterFactory $writers = null,
        ?ParserRegistry $parsers = null,
        ?ReaderRegistry $readers = null,
        ?TemplateOptions $templateOptions = null,
    ) {
        $this->formatters = $formatters ?? FormatterRegistry::default();
        $this->resolver = $resolver ?? new ValueResolver();
        $this->writers = $writers ?? new DefaultWriterFactory();
        $this->parsers = $parsers ?? ParserRegistry::default();
        $this->readers = $readers ?? ReaderRegistry::default();
        $this->templateOptions = $templateOptions ?? new TemplateOptions();
    }

    public function export(Schema $schema): ExportBuilder
    {
        return new ExportBuilder(
            schema: $schema,
            translator: $this->translator,
            settings: $this->settings,
            formatters: $this->formatters,
            resolver: $this->resolver,
            writers: $this->writers,
        );
    }

    public function import(Schema $schema): ImportBuilder
    {
        return new ImportBuilder(
            schema: $schema,
            translator: $this->translator,
            settings: $this->settings,
            parsers: $this->parsers,
            readers: $this->readers,
        );
    }

    /**
     * Boş içe aktarma şablonu üreten yazıcı.
     *
     *     $tabula->template()->write($schema, '/tmp/sablon.xlsx', 'tr');
     *
     * `export()`/`import()`ten farklı olarak ŞEMA ALMAZ. Sebebi tasarımsal: `TemplateBuilder`
     * durumsuzdur ve Symfony köprüsünde SERVİS olarak kaydedilir, dolayısıyla şemayı
     * kurucusunda tutamaz — şema her çağrıda `write()`e verilir. Bu metodun şema alıp onu
     * yok sayması, çağıranı aynı şemayı iki kez taşımaya zorlayan sessiz bir tuzaktı.
     */
    public function template(): TemplateBuilder
    {
        return new TemplateBuilder(
            translator: $this->translator,
            settings: $this->settings,
            options: $this->templateOptions,
        );
    }

    public function settings(): TabulaSettings
    {
        return $this->settings;
    }

    public function translator(): Translator
    {
        return $this->translator;
    }
}
