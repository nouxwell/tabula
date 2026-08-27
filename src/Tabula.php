<?php

declare(strict_types=1);

namespace Balin\Tabula;

use Balin\Tabula\Export\ExportBuilder;
use Balin\Tabula\Export\Writer\DefaultWriterFactory;
use Balin\Tabula\Export\Writer\WriterFactory;
use Balin\Tabula\Port\PassthroughTranslator;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Value\FormatterRegistry;
use Balin\Tabula\Value\ValueResolver;

/**
 * Kütüphanenin giriş noktası.
 *
 * Çerçevesiz kullanımda doğrudan kurulur; Symfony köprüsü aynı nesneyi servis olarak kaydeder.
 *
 *     $tabula = new Tabula(new ArrayTranslator($catalogues), $settings);
 *
 *     $tabula->export($schema)
 *         ->from(ArraySource::of($rows))
 *         ->locale('tr')
 *         ->to(Format::Xlsx)
 *         ->write('/tmp/musteriler.xlsx');
 *
 * Faz 0 kapsamı yalnızca dışa aktarmadır; `import()` ve `template()` sırasıyla Faz 4 ve Faz 3'te eklenecek.
 */
final class Tabula
{
    private readonly FormatterRegistry $formatters;

    private readonly ValueResolver $resolver;

    private readonly WriterFactory $writers;

    public function __construct(
        private readonly Translator $translator = new PassthroughTranslator(),
        private readonly TabulaSettings $settings = new TabulaSettings(),
        ?FormatterRegistry $formatters = null,
        ?ValueResolver $resolver = null,
        ?WriterFactory $writers = null,
    ) {
        $this->formatters = $formatters ?? FormatterRegistry::default();
        $this->resolver = $resolver ?? new ValueResolver();
        $this->writers = $writers ?? new DefaultWriterFactory();
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

    public function settings(): TabulaSettings
    {
        return $this->settings;
    }

    public function translator(): Translator
    {
        return $this->translator;
    }
}
