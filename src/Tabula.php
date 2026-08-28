<?php

declare(strict_types=1);

namespace Nouxwell\Tabula;

use Nouxwell\Tabula\Export\ExportBuilder;
use Nouxwell\Tabula\Export\Writer\DefaultWriterFactory;
use Nouxwell\Tabula\Export\Writer\WriterFactory;
use Nouxwell\Tabula\Import\ImportBuilder;
use Nouxwell\Tabula\Import\Reader\ReaderRegistry;
use Nouxwell\Tabula\Port\PassthroughTranslator;
use Nouxwell\Tabula\Port\Translator;
use Nouxwell\Tabula\Schema\Schema;
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Template\TemplateBuilder;
use Nouxwell\Tabula\Template\TemplateOptions;
use Nouxwell\Tabula\Value\FormatterRegistry;
use Nouxwell\Tabula\Value\ParserRegistry;
use Nouxwell\Tabula\Value\ValueResolver;

/**
 * The entry point of the library.
 *
 * Without a framework it is constructed directly; the Symfony bridge registers the same object
 * as a service.
 *
 *     $tabula = new Tabula(new ArrayTranslator($catalogues), $settings);
 *
 * ONE SCHEMA, THREE DIRECTIONS — all three are fed from the same `Schema` object, which is why
 * an exported file can be imported back with no conversion at all:
 *
 *     // 1. export
 *     $tabula->export($schema)
 *         ->from(ArraySource::of($rows))
 *         ->locale('tr')
 *         ->to(Format::Xlsx)
 *         ->write('/tmp/customers.xlsx');
 *
 *     // 2. an empty template (row 1 hidden canonical keys, row 2 the translation, row 3 data)
 *     $tabula->template()->write($schema, '/tmp/template.xlsx', 'tr');
 *
 *     // 3. import
 *     $result = $tabula->import($schema)
 *         ->from('/tmp/filled.xlsx')
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
     * The writer that produces an empty import template.
     *
     *     $tabula->template()->write($schema, '/tmp/template.xlsx', 'tr');
     *
     * Unlike `export()`/`import()`, it TAKES NO SCHEMA. The reason is a design one:
     * `TemplateBuilder` is stateless and is registered as a SERVICE in the Symfony bridge, so it
     * cannot keep the schema in its constructor — the schema is handed to `write()` on every
     * call. Letting this method accept a schema and then ignore it was a silent trap that forced
     * the caller to carry the same schema twice.
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
