<?php

declare(strict_types=1);

namespace Balin\Tabula\Export;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Export\Sheet\SheetStrategy;
use Balin\Tabula\Export\Sheet\SingleSheet;
use Balin\Tabula\Export\Writer\CsvWriter;
use Balin\Tabula\Export\Writer\Writer;
use Balin\Tabula\Export\Writer\XlsxWriter;
use Balin\Tabula\Format;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\FormatterRegistry;
use Balin\Tabula\Value\ValueResolver;
use Closure;

/**
 * Tek bir dışa aktarmanın akıcı kurulumu ve çalıştırılması.
 *
 * Kurulum değiştirilemezdir: her ayar yeni bir kopya döndürür, böylece aynı temel
 * yapılandırmadan birden çok çıktı türetilebilir:
 *
 *     $base = $tabula->export($schema)->from($source)->locale('tr');
 *     $base->to(Format::Xlsx)->write('a.xlsx');
 *     $base->to(Format::Csv)->write('a.csv');
 */
final class ExportBuilder
{
    private ?\Balin\Tabula\Source\DataSource $source = null;

    /** @var list<string>|null null = şemadaki tüm alanlar */
    private ?array $keys = null;

    private ?string $locale = null;

    private Format $format = Format::Xlsx;

    private ?SheetStrategy $sheets = null;

    private ?Writer $writer = null;

    public function __construct(
        private readonly Schema $schema,
        private readonly Translator $translator,
        private readonly TabulaSettings $settings,
        private readonly FormatterRegistry $formatters,
        private readonly ValueResolver $resolver,
    ) {
    }

    // ---------------------------------------------------------------- kurulum

    public function from(\Balin\Tabula\Source\DataSource $source): self
    {
        return $this->with(static function (self $b) use ($source): void {
            $b->source = $source;
        });
    }

    /**
     * Yalnızca bu alanları, VERİLEN SIRAYLA yaz.
     *
     * İstemci artık etiket göndermez — yalnız anahtar gönderir; bilinmeyen anahtar sessizce
     * yutulmaz, hata olur. (Eskiden kolon listesi tarayıcıdan `{value,label}` olarak geliyor
     * ve hiç doğrulanmıyordu.)
     *
     * @param list<string> $keys
     */
    public function only(array $keys): self
    {
        return $this->with(static function (self $b) use ($keys): void {
            $b->keys = array_values($keys);
        });
    }

    public function locale(string $locale): self
    {
        return $this->with(static function (self $b) use ($locale): void {
            $b->locale = $locale;
        });
    }

    public function to(Format $format): self
    {
        return $this->with(static function (self $b) use ($format): void {
            $b->format = $format;
        });
    }

    public function sheets(SheetStrategy $strategy): self
    {
        return $this->with(static function (self $b) use ($strategy): void {
            $b->sheets = $strategy;
        });
    }

    /** Yerleşik yazıcı yerine kendi yazıcını kullan. */
    public function writer(Writer $writer): self
    {
        return $this->with(static function (self $b) use ($writer): void {
            $b->writer = $writer;
        });
    }

    // ---------------------------------------------------------------- çalıştırma

    public function write(string $path): ExportResult
    {
        $source = $this->source ?? throw ExportException::noSource();

        $context = new FormatContext(
            locale: $this->locale ?? $this->settings->defaultLocale,
            translator: $this->translator,
            settings: $this->settings,
            format: $this->format,
        );

        $schema = $this->resolveSchema();
        $fields = array_values($schema->getFields());
        $columns = array_map(
            static fn (Field $field): Column => Column::fromField($field, $context),
            $fields,
        );

        // Biçim elemesi tüm alanları düşürmüş olabilir; kolonsuz bir dosya yazmak sessiz veri
        // kaybıdır (başlıksız/boş bir çıktı "sonuç yok" gibi okunur). Erken ve yüksek sesle dur.
        if ([] === $columns) {
            throw ExportException::noColumns($schema->getName(), $this->format);
        }

        $defaultSheetName = $this->defaultSheetName($context);
        // Varsayılan strateji taşma korumalıdır: Excel'in satır tavanı aşıldığında sessizce
        // bozuk dosya üretmek yerine yeni sayfaya geçilir.
        $strategy = $this->sheets ?? new SingleSheet($defaultSheetName, $this->settings->maxRowsPerSheet);
        $writer = $this->writer ?? $this->writerFor($this->format);

        $this->ensureTargetIsWritable($path);

        $currentSheet = null;
        $sheetCount = 0;
        $rowIndex = 0;
        $paths = [];

        $writer->open($path);

        // `finally`: satırın ortasında patlayan bir kaynak dosya tanıtıcısını açık bırakmamalı.
        // Her iki yazıcının `close()` metodu da tekrar çağrılabilir olacak şekilde yazılmıştır.
        try {
            foreach ($source->rows() as $row) {
                $sheetName = $strategy->sheetFor($rowIndex, $row, $context);

                if ($sheetName !== $currentSheet) {
                    if (null !== $currentSheet) {
                        $writer->finishSheet();
                    }

                    $writer->startSheet($sheetName, $columns);
                    $currentSheet = $sheetName;
                    ++$sheetCount;
                }

                $writer->writeRow($this->buildRow($fields, $row, $context));
                ++$rowIndex;
            }

            // Hiç satır yoksa bile başlıklı boş bir sayfa üret: kullanıcı boş bir dosya değil,
            // "sonuç yok" diyen bir tablo görmeli.
            //
            // Sayfa adını stratejiye SORMUYORUZ: ortada satır olmadığı için uydurma bir `null`
            // satır geçirmek gerekirdi ve `GroupedSheets`in kullanıcı kapanışı `fn (array $row)`
            // olarak yazıldığında bu doğrudan TypeError'a dönerdi.
            if (null === $currentSheet) {
                $writer->startSheet($defaultSheetName, $columns);
                ++$sheetCount;
            }

            $writer->finishSheet();
        } finally {
            $paths = $writer->close();
        }

        return new ExportResult(
            paths: array_values($paths),
            rows: $rowIndex,
            sheets: $sheetCount,
            columns: array_map(static fn (Column $column): string => $column->key, $columns),
            format: $this->format,
        );
    }

    // ---------------------------------------------------------------- iç

    /**
     * @param list<Field> $fields
     *
     * @return list<\Balin\Tabula\Value\Cell>
     */
    private function buildRow(array $fields, mixed $row, FormatContext $context): array
    {
        $cells = [];

        foreach ($fields as $field) {
            $raw = $this->resolver->resolve($field, $row);
            $cells[] = $this->formatters->for($field->getType())->format($raw, $field, $row, $context);
        }

        return $cells;
    }

    private function resolveSchema(): Schema
    {
        // Sıra önemli: önce kullanıcı seçimi (tam şemaya karşı doğrulanır), sonra biçim elemesi.
        $schema = null === $this->keys ? $this->schema : $this->schema->only($this->keys);

        return $schema->forFormat($this->format);
    }

    private function defaultSheetName(FormatContext $context): string
    {
        $title = $this->schema->getTitle();

        if ($title instanceof Closure) {
            return (string) $title($context->locale);
        }

        return null === $title ? $this->schema->getName() : $context->trans($title);
    }

    private function writerFor(Format $format): Writer
    {
        return match ($format) {
            Format::Xlsx => new XlsxWriter(),
            Format::Csv => new CsvWriter(),
            Format::Pdf => throw ExportException::unsupportedFormat($format),
        };
    }

    private function ensureTargetIsWritable(string $path): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('"%s" klasörü yok.', $directory));
        }

        if (!is_writable($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('"%s" klasörüne yazma izni yok.', $directory));
        }
    }

    private function with(Closure $mutator): self
    {
        $clone = clone $this;
        $mutator($clone);

        return $clone;
    }
}
