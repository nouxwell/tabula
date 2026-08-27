<?php

declare(strict_types=1);

namespace Balin\Tabula\Export;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Export\Sheet\SheetStrategy;
use Balin\Tabula\Export\Sheet\SingleSheet;
use Balin\Tabula\Export\Writer\PageAware;
use Balin\Tabula\Export\Writer\Writer;
use Balin\Tabula\Export\Writer\WriterFactory;
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
 * Fluent set-up and execution of a single export.
 *
 * The set-up is immutable: every setting returns a new copy, so several outputs can be
 * derived from the same base configuration:
 *
 *     $base = $tabula->export($schema)->from($source)->locale('tr');
 *     $base->to(Format::Xlsx)->write('a.xlsx');
 *     $base->to(Format::Csv)->write('a.csv');
 */
final class ExportBuilder
{
    private ?\Balin\Tabula\Source\DataSource $source = null;

    /** @var list<string>|null null = every field in the schema */
    private ?array $keys = null;

    private ?string $locale = null;

    private Format $format = Format::Xlsx;

    private ?SheetStrategy $sheets = null;

    private ?Page $page = null;

    private ?ColumnBudget $budget = null;

    private ?Writer $writer = null;

    public function __construct(
        private readonly Schema $schema,
        private readonly Translator $translator,
        private readonly TabulaSettings $settings,
        private readonly FormatterRegistry $formatters,
        private readonly ValueResolver $resolver,
        private readonly WriterFactory $writers,
    ) {
    }

    // ---------------------------------------------------------------- set-up

    public function from(\Balin\Tabula\Source\DataSource $source): self
    {
        return $this->with(static function (self $b) use ($source): void {
            $b->source = $source;
        });
    }

    /**
     * Write these fields only, IN THE GIVEN ORDER.
     *
     * The client no longer sends labels — it sends keys only; an unknown key is not swallowed
     * silently, it is an error. (Previously the column list arrived from the browser as
     * `{value,label}` and was never validated at all.)
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

    /**
     * Paper geometry: size, orientation, margins.
     *
     *     ->page(Page::a3()->landscape()->margins(8))
     *
     * Only meaningful for formats that print onto paper. If it is given for Xlsx/CSV the export
     * DOES NOT START: ignoring it silently would be exactly the same bug this phase exists to
     * eliminate (see `applyPage()` inside `write()`).
     */
    public function page(Page $page): self
    {
        return $this->with(static function (self $b) use ($page): void {
            $b->page = $page;
        });
    }

    /**
     * How the columns are fitted onto the page; the overflow strategy lives here too.
     *
     *     ->columns(ColumnBudget::fit()->minWidth(25)->anchor('code', 'name'))
     *
     * The method is named `columns()`, not `budget()`: the sentence read at the call site should
     * be "let the COLUMNS of this export behave like this", not the name of the class being used.
     */
    public function columns(ColumnBudget $budget): self
    {
        return $this->with(static function (self $b) use ($budget): void {
            $b->budget = $budget;
        });
    }

    /** Use your own writer instead of the built-in one. */
    public function writer(Writer $writer): self
    {
        return $this->with(static function (self $b) use ($writer): void {
            $b->writer = $writer;
        });
    }

    // ---------------------------------------------------------------- execution

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

        // The format filter may have dropped every field; writing a file with no columns is
        // silent data loss (a header-less/empty output reads as "no results"). Stop early and
        // loudly.
        if ([] === $columns) {
            throw ExportException::noColumns($schema->getName(), $this->format);
        }

        $defaultSheetName = $this->defaultSheetName($context);
        // The default strategy is overflow-safe: when Excel's row ceiling is exceeded it moves on
        // to a new sheet instead of silently producing a corrupt file.
        $strategy = $this->sheets ?? new SingleSheet($defaultSheetName, $this->settings->maxRowsPerSheet);
        // The writer comes from the factory, it is not `new`ed here: so that settings such as the
        // delimiter or the BOM can be fed from the application's configuration (otherwise every
        // machine-bound feed had to write `->writer(new CsvWriter(...))` by hand at the call site).
        $writer = $this->applyPage($this->writer ?? $this->writers->for($this->format));

        $this->ensureTargetIsWritable($path);

        $currentSheet = null;
        $sheetCount = 0;
        $rowIndex = 0;
        $paths = [];

        $writer->open($path);

        // `finally`: a source that blows up in the middle of a row must not leave the file handle
        // open. The `close()` method of both writers is written so that it can be called again.
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

            // Even when there is not a single row, produce an empty sheet with headers: the user
            // should see a table that says "no results", not an empty file.
            //
            // We do NOT ASK the strategy for the sheet name: since there is no row, we would have
            // to pass a made-up `null` row, and when `GroupedSheets`'s user closure is written as
            // `fn (array $row)` that would turn straight into a TypeError.
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

    // ---------------------------------------------------------------- internals

    /**
     * Pushes the page setting to the writer — or STOPS THE EXPORT if the writer knows nothing
     * about paper.
     *
     * ★ The `throw` here is the essence of this phase. Silently not touching a writer that is not
     * `PageAware` means "the setting was never applied", and the user can only find that out by
     * measuring the output — which is exactly what the decorative `setPaper()` call of the system
     * this replaces used to do. Either the setting is applied or it makes noise; there is no third
     * option.
     *
     * The check DOES NOT CARE whether the writer came from the factory or was handed over by hand
     * through `->writer()`: a hand-supplied `CsvWriter` swallowing the page setting is no less
     * harmful than the factory-built one doing it. `withPage()` never mutates in place anyway, it
     * returns a copy — so A3 does not bleed into a shared writer instance (see `PageAware`).
     */
    private function applyPage(Writer $writer): Writer
    {
        if (null === $this->page && null === $this->budget) {
            return $writer;
        }

        if (!$writer instanceof PageAware) {
            throw ExportException::pageSettingsUnsupported($this->format);
        }

        // A null argument means "keep what you have"; that is what makes it possible to change
        // only the page without touching the budget (or the other way round).
        return $writer->withPage($this->page, $this->budget);
    }

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
        // The order matters: the user's selection first (validated against the full schema), then
        // the format filter.
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

    private function ensureTargetIsWritable(string $path): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('the "%s" folder does not exist.', $directory));
        }

        if (!is_writable($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('no write permission for the "%s" folder.', $directory));
        }
    }

    private function with(Closure $mutator): self
    {
        $clone = clone $this;
        $mutator($clone);

        return $clone;
    }
}
