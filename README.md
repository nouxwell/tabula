# Tabula

[![CI](https://github.com/nouxwell/tabula/actions/workflows/ci.yml/badge.svg)](https://github.com/nouxwell/tabula/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/nouxwell/tabula)](https://packagist.org/packages/nouxwell/tabula)
[![PHP](https://img.shields.io/packagist/dependency-v/nouxwell/tabula/php)](https://www.php.net/)

**One schema, three directions.** A PHP library that writes tabular data to Excel, CSV and PDF from a
single schema definition — and uses that same definition to generate blank templates and to import
files back in.

Author: **Hüseyin Niyazi Balın**

---

## Why

In a typical ERP, "what a column is" lives in four separate places and none of them knows about the
others: the columns the browser happens to be showing, hand-written column arrays inside controllers,
a global decimals map keyed by *field name*, and the import templates' own schema provider. The result
is that the same column is described four times, an exported file cannot be imported back, and
changing a translation silently breaks every file users already have.

Tabula inverts that: **a column is defined in exactly one place.** Everything else — writers,
formatters, parsers, sheet strategies — are consumers of that definition.

## What it buys you

**Rename one column header in your translation file.** Every template your users have already
downloaded and half-filled stops importing — the system looks for "Customer Code", the file says
"Client Code", and nothing matches. The support ticket says "the import is broken". Nobody connects
it to a translation commit from three weeks ago.

Tabula makes that impossible. The template hides the canonical field keys in row 1 and matches on
those, so the visible header is free to change. **Re-translate every label in the file and it still
imports.**

That is one instance of the thing this library is really for: **a file that goes out and comes back.**
You hand users a template, they fill it in, they send it back, and what returns has to be trusted
enough to write into a database. Three more properties follow, and all of them are hard to add later.

**One definition behind all three directions.** The export, the blank template and the import parser
are produced by the same `Field`. Not three code paths that happen to agree this month — a file this
library wrote is a file it can read, by construction.

**Strict on the way in, lenient on the way out — deliberately.** An export prints an unreadable cell
blank and carries on; a 40,000-row report should not die over one bad value. An import doing the same
writes wrong data into your database, so it raises an error naming the row and the field, and keeps
going. The directions are asymmetric because the cost of being wrong is asymmetric.

**Locale handling is the actual work, and it is where the money is lost.** `1.234` is 1234 in
Turkish and 1.234 in English; guessing wrong is a silent thousand-fold error in a balance column.
Money cells carry both a real number Excel can sum and the localised text a human reads. Accounting
notations — `(1.234,56)` for a negative, the trailing minus an ERP feed emits — are understood
rather than quietly turned into positives.

> **Not this library:** if the file only ever goes one way — a report nobody sends back — none of the
> above earns its keep, and `league/csv` or `spatie/simple-excel` will do it in three lines without
> asking you to declare a schema. If you need millions of rows, `openspout` streams properly where
> this holds the workbook in memory ([USAGE §16](USAGE.md#16-how-much-can-it-export)).

## Installation

```bash
composer require nouxwell/tabula
```

Requires PHP 8.3+. For PDF output also install `dompdf/dompdf`; if it is missing, asking for
`Format::Pdf` fails **when the writer is created** — not after fifty thousand rows have been processed.

Worked examples for the common jobs — a Doctrine query to Excel, one tab per warehouse, a PDF that
fits the paper, a template and the import that reads it back — are in **[USAGE.md](USAGE.md)**.

## Quick start

```php
use Nouxwell\Tabula\Tabula;
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Schema\{Schema, Field, Priority};
use Nouxwell\Tabula\Source\ArraySource;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Settings\{TabulaSettings, NumberSettings};

$schema = Schema::make('customer')
    ->title('export.customer.title')
    ->fields(
        Field::string('code')->label('export.customer.code')->width(14)->required()
            ->priority(Priority::Always),
        Field::string('name')->label('export.customer.name'),
        Field::string('city')->label('export.customer.city')->from('address.city'),
        Field::quantity('stock')->label('export.customer.stock')->decimals(3),
        Field::money('balance')->label('export.customer.balance')
            ->currency(fn (array $row): string => $row['currencyCode']),
        Field::bool('isActive')->label('export.customer.active'),
        Field::date('createdAt')->label('export.customer.created')->priority(Priority::Optional),
    );

$tabula = new Tabula(
    new ArrayTranslator(['en' => ['export.customer.code' => 'Code', /* … */]]),
    new TabulaSettings(numbers: new NumberSettings(currencySymbols: ['USD' => '$'])),
);

$result = $tabula->export($schema)
    ->from(ArraySource::of($rows))
    ->locale('en')
    ->to(Format::Xlsx)
    ->write('/tmp/customers.xlsx');

$result->path();  // /tmp/customers.xlsx
$result->rows;    // number of rows written
```

## Field definition

| Key | What it does |
| --- | --- |
| `key` | Canonical field name. The file's real identity; it stays put when translations change. |
| `label` | A translation key, plain text, or `fn(string $locale): string`. |
| `from` | Where the value comes from: array key, dot path (`address.city`), DQL alias, or a closure. |
| `type` | `string · integer · decimal · money · quantity · bool · date · datetime · enum · options` |
| `decimals` | Digit count — **on the field itself**, not in a global map. |
| `currency` | A fixed code or `fn($row): string`. Symbol and its position come from settings. |
| `enumClass` | A PHP enum class; values resolve to translation keys automatically. |
| `options` | The option set for the `options` type (array or closure). |
| `width` | Column width; automatic when omitted. |
| `align` | Derived from the type when omitted: numbers right, bool/date centre, text left. |
| `required` | Marks the header in the template and makes the field non-empty on import. |
| `priority` | Rank in the PDF column budget: `Always` · `Normal` · `Optional`. |
| `only` | Restricts the field to specific output formats. |
| `format` | A closure that takes over formatting entirely. |

## Data sources

You can hand the rows over yourself, or let the library page through them. The schema side treats
both identically.

```php
use Nouxwell\Tabula\Source\{ArraySource, IteratorSource, CallableSource, DoctrineSource};

ArraySource::of($rows);                      // rows already in hand
IteratorSource::of(fn () => $generator());   // streaming in constant memory
CallableSource::of(                          // server-side pagination
    fn (int $page, int $limit) => $repo->fetchPaged($page, $limit),
    pageSize: 2000,
);
DoctrineSource::of($queryBuilder);               // one query, row-by-row streaming
DoctrineSource::of($queryBuilder)->chunk(2000);  // page by page (ORDER BY required)
```

Every source is lazy: rows are produced as they are consumed, never collected up front.

### DoctrineSource has two modes

**Streaming (default)** opens a single query and hydrates rows one at a time via
`Query::toIterable()`. No pagination arithmetic is involved, so rows can neither be skipped nor
repeated, and any `setFirstResult`/`setMaxResults` window the caller set is preserved.

**Chunked** (`chunk(n)`) issues one query per page. It enforces two hard rules, both of them there to
prevent silent data corruption:

- **ORDER BY is mandatory.** Without a stable sort, `LIMIT/OFFSET` skips and repeats rows.
- **A caller-supplied `setMaxResults` is rejected.** Chunking would overwrite it, so a query built as
  a "preview, at most 50 rows" would quietly stream the entire table.

Paging stops only on a **genuinely empty** page, never on a short one: `getResult()` returns *hydrated*
results, and in joined queries repeated root rows collapse into a single object — 2000 SQL rows can
become 900 roots. A "short page means we're done" rule would silently end the export on page one.

> ⚠ If you use `hydrateAs(AbstractQuery::HYDRATE_OBJECT)`, manage memory yourself: entities stay
> managed in the UnitOfWork, and the library deliberately does not call `detach()` behind your back.

## Output formats

| Writer | Engine | Multiple sheets |
| --- | --- | --- |
| `Format::Xlsx` | PhpSpreadsheet | Real tabs |
| `Format::Csv` | Native `fputcsv`, fully streamed | One file per chunk |
| `Format::Pdf` | Dompdf | Sheet name rendered above the table |

The CSV delimiter defaults to `;`. In Turkish/European Excel, `,` is the **decimal** separator, so a
comma-delimited file splits numbers across two columns. Files are written with a UTF-8 BOM; without
it Excel mangles non-ASCII characters.

## PDF: paper and the column budget

Xlsx and CSV have no notion of page size; a PDF has a **physical width**. That makes the column count
not a preference but a computable budget:

```
budget = floor( (paper width − left/right margins) ÷ minimum column width )
```

A4 landscape gives 297 − 2×10 = 277 mm; at a 22 mm minimum that is 12 columns. Moving the same schema
to A3 landscape raises the budget to 18 — **enlarging the page widens the table on its own**, with no
extra configuration.

```php
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Export\Page\{Page, ColumnBudget, Overflow};

$tabula->export($schema)
    ->from(ArraySource::of($rows))
    ->locale('en')
    ->to(Format::Pdf)
    ->page(Page::a3()->landscape()->margins(8))
    ->columns(ColumnBudget::fit()->minWidth(25)->anchor('code', 'name'))
    ->write('/tmp/customers.pdf');
```

`Page` is the paper geometry: `a3() · a4() · a5() · letter() · custom(w, h)`, then
`landscape()/portrait()` and `margins(mm)` / `marginsOf(t, r, b, l)`. Dimensions are always given in
**portrait**; `Page` applies the orientation. The default is **A4 landscape**.

`ColumnBudget` decides how the columns are fitted onto that paper. When they do not fit, there are
three behaviours:

| `Overflow` | What it does | Data loss |
| --- | --- | --- |
| `NextPageSet` (default) | Splits columns into groups; each group gets its own set of pages | none |
| `Drop` | Sheds low-priority columns (`Optional` first, `Always` never) | **yes** |
| `Shrink` | Never splits, tries to fit everything | none (may become unreadable) |

```php
->columns(ColumnBudget::fit()->max(8)->overflow(Overflow::Drop))   // one-page summary
->columns(ColumnBudget::unlimited())                               // few columns anyway
```

Columns passed to `anchor('code', 'name')` are **repeated in every group**: without them, a reader
looking at the second group cannot tell which row they are on. An anchor key that is not part of the
export is silently ignored — if the user did not select that column, there is nothing to anchor.

`Drop` never sheds an `Always` column. If the mandatory columns alone exceed the budget the export
stops with an error instead of quietly shipping an incomplete document.

> ⚠ Passing `->page()` or `->columns()` to a format that has no notion of paper (Xlsx, CSV) **aborts**
> the export with an `ExportException`. Ignoring them silently would be the very bug this design
> exists to remove: in the original code the page size was declared both in PHP (`Dompdf::setPaper()`)
> and in the template's `@page` rule, Dompdf applied the CSS at render time, and the `setPaper()` call
> was effectively decorative. The `@page` rule is now produced **only** by `Page::cssPageRule()`.

The font family must cover Latin Extended-A. Dompdf's core fonts (Helvetica, Times, Courier) are bound
to WinAnsi, which does **not** contain `ş ğ ı İ`; the only bundled family that carries them is
`DejaVu Sans`, which is therefore the default.

## Sheet strategies (tabs)

```php
use Nouxwell\Tabula\Export\Sheet\{SingleSheet, ChunkedSheets, GroupedSheets};

->sheets(new SingleSheet('Customers'))         // everything on one sheet (default)
->sheets(new ChunkedSheets(50_000))            // a new sheet every 50,000 rows
->sheets(new GroupedSheets('warehouseName'))   // one sheet per field value
```

> `GroupedSheets` does not reorder rows. For a group to land on a single sheet, the data source must
> already be **sorted** by that field.

A workbook split this way is read back one sheet at a time — the import refuses a file with more
than one data sheet rather than picking for you, because reading the first and stopping would drop
the rest without saying so:

```php
foreach (['Ankara', 'Izmir'] as $sheet) {
    $tabula->import($schema)->from($path)->sheet($sheet)->each(…)->run();
}
```

The refusal names the sheets, so the choice is visible without opening the file. A single-sheet file
needs none of this: a filled-in template carries a hidden `_lists` helper sheet, and hidden sheets do
not count as data.

## Templates and import

The same schema produces a blank template and reads filled files back, which is what makes the
round trip work by construction.

```php
// 1 · generate a blank template
$tabula->template()->write($schema, '/tmp/template.xlsx', 'en');

// 2 · the user fills it in, then:
$result = $tabula->import($schema)
    ->from('/tmp/filled.xlsx')
    ->locale('en')
    ->each(static fn (ImportedRow $row) => $repository->save($row->toArray()))
    ->run();

$result->imported;        // 4812
$result->errors;          // list<RowError>: row number + field key + message
$result->errorsByRow();   // grouped for display
```

### Matching is by key, not by label

The generated template is laid out as:

```
row 1  →  canonical field KEYS      (hidden in xlsx)
row 2  →  translated labels          (what the user reads)
row 3+ →  data
```

Detection needs no marker: if every non-empty cell of row 1 matches a schema key, it *is* a key row.

This is the point of the whole design. When the translated header string is the file's identity —
as it commonly is — renaming one word in a translation catalogue silently invalidates every template
users already downloaded, and a file with English headers will not match in a Turkish session. Here,
**re-translating every label leaves existing files perfectly readable.**

`MatchStrategy::Auto` (default) uses the key row when present and falls back to labels, so files
produced elsewhere or filled in by hand still work. `Key` requires the key row; `Label` ignores it.

> ⚠ Known limit: the key row protects **headers**, not cell *contents*. Enum and boolean values are
> written as translated text, so re-translating those option labels does break an already-filled file.

### Values come back typed

`ImportedRow` hands you real types, not strings: `bool` is a bool, an enum field is an enum instance,
a date is a `DateTimeImmutable`, a quantity is a float.

### Parsers are strict where formatters are lenient

An export may swallow a broken cell and print a blank; an import doing the same writes wrong data into
your database. So an unparseable value raises a `RowError` for that row and field, and the run
continues. Two consequences worth knowing:

- An empty value is **not** an error — the required check happens against the field, not in the parser.
- `ErrorMode::Collect` (default) processes the valid rows and reports the rest;
  `ErrorMode::FailFast` stops at the first error.

Transactions are **yours**: the library parses and validates, it never writes to your database.

### Templates refuse bad input in the cell

Bool, enum and options columns get real Excel data-validation dropdowns, backed by a hidden `_lists`
sheet with identical option sets de-duplicated. The dropdown entries and the values the parser accepts
are derived from the same translation call, so a value written by the template is always a member of
its own allowed list.

Typed columns are validated as they are typed: a date column takes dates, an integer column whole
numbers, the other numeric types decimals. The parser catches these anyway — but only once the file
has been filled in and sent back, which is the difference between "row 348 could not be read" and the
cursor never leaving the cell.

Blank stays allowed on every rule, because requiredness belongs to the import where the failure can
name the row and the field. Made Excel's job it fires a warning box for merely tabbing through an
unfinished row, and a user who meets that box twice switches validation off for good — taking the
rules that do matter with it.

### What a boolean cell says

`Yes` and `No`, unless you say otherwise:

```php
new TabulaSettings(boolTrueKey: 'Evet', boolFalseKey: 'Hayır');   // plain words
new TabulaSettings(boolTrueKey: 'app.yes', boolFalseKey: 'app.no'); // translation keys
```

Plain words are written as they stand; anything the translator recognises is resolved. The defaults
are words rather than keys deliberately — a translator hands back whatever it cannot translate, so a
key with no catalogue entry would be printed into the cell verbatim, and `BoolParser` has never heard
of `tabula.bool.yes` coming back.

## Translation

The core does not know about Symfony; it talks through a `Translator` port.

```php
interface Translator {
    public function trans(string $key, array $params = [], ?string $locale = null): string;
}
```

Bundled implementations: `ArrayTranslator` (flat or nested catalogues) and `PassthroughTranslator`
(returns the key unchanged). The Symfony bridge binds the port to the framework translator.

The locale is passed **explicitly on every call** — there is no "language from the current request"
inside a queue worker.

### Enum translation

An enum value resolves to a translation key by trying, in order:

1. `Nouxwell\Tabula\Contract\TranslatableEnum::translationKey()`
2. A `label(): string` method on the enum (a widespread convention — existing enums work unchanged)
3. `BackedEnum::$value` or `UnitEnum::$name`

## Using it with Symfony

`config/bundles.php`:

```php
Nouxwell\Tabula\Bridge\Symfony\TabulaBundle::class => ['all' => true],
```

`config/packages/tabula.yaml`:

```yaml
tabula:
    default_locale: '%kernel.default_locale%'
    empty_text: '-'
    translation:
        domains: ['messages', 'enum']       # tried in order, first DEFINING domain wins
        addressable_domains: ['validators'] # also reachable as 'validators:key'
    numbers:
        currency_symbols:
            USD: '$'
    csv:                     # defaults target Excel
        delimiter: ';'
        write_bom: true
        line_ending: crlf    # crlf | lf
    xlsx:
        creator: 'My App'
        freeze_header: true
        auto_filter: true
    pdf:
        page_size: a4            # a3 | a4 | a5 | letter | legal (portrait dimensions)
        orientation: landscape   # portrait | landscape
        margin_mm: 10.0
        min_column_width_mm: 22.0
        max_columns: ~           # ~ = no hard cap
        overflow: next_page_set  # next_page_set | drop | shrink
        font_family: 'DejaVu Sans'
        font_size_pt: 8.0
        repeat_header: true
```

`Nouxwell\Tabula\Tabula` can then be injected anywhere.

**Writer settings.** There is no single right CSV default: a human opens the file in Excel (`;` plus a
BOM), a machine expects RFC 4180 (`,`, no BOM). When you need both, pass the options at the call site:

```php
use Nouxwell\Tabula\Export\Writer\{CsvWriter, CsvOptions, XlsxWriter, XlsxOptions};

->writer(new CsvWriter(CsvOptions::rfc4180()))   // machine-to-machine feed
->writer(new XlsxWriter(XlsxOptions::plain()))   // undecorated intermediate file
```

`line_ending` is given by name (`crlf`/`lf`) because writing a literal `"\r\n"` in YAML runs into
escaping rules and silently becomes two characters. `pdf.page_size`, `orientation` and `overflow` are
named for the same reason, rather than millimetres or class names.

Range rules for the `pdf` values (positive dimensions, sane minimums) live in the value objects
(`Page`, `ColumnBudget`, `PdfOptions`), not in the config tree: those messages also state the fix
("use A3 instead of A4, rotate to landscape, reduce the margin…"), and copying half of a rule into the
tree would be a small instance of exactly what this library exists to remove — one truth living in two
places. `->page()`/`->columns()` at the call site override the configured defaults.

**About translation domains.** Labels commonly live in `messages` while enum captions live in `enum`,
yet an enum's `label()` returns a key that carries no domain. The bridge resolves this with a **domain
chain**: it asks the catalogue which domain *defines* the key and the first one that does wins.
Guessing from the returned string instead ("does it look like a miss?") is wrong in three real cases —
translations equal to their own key (`IBAN`, ISO codes), ICU domains, and pluralised keys containing
`|` — so `TranslatorBagInterface::defines()` is used.

**Applications without a translator.** If `symfony/translation` is absent or `framework.translator` is
disabled, the bundle falls back to `PassthroughTranslator` instead of taking the container down.

## Development

```bash
composer install
composer test     # PHPUnit
composer stan     # PHPStan
composer cs       # php-cs-fixer
```

The suite runs with `failOnWarning`, `failOnRisky` and `failOnDeprecation` enabled, and PHPStan runs
at level 8 over both `src` and `tests`.

## Versioning

Below 1.0, a **minor** bump is where a breaking change lands — which is how Composer already treats
`^0.8`. [CHANGELOG.md](CHANGELOG.md) says what each release changed and, for the breaking ones, what
to do about it.

## License

MIT — see [LICENSE](LICENSE). Copyright © 2026 [IONSIS](https://ionsis.com).

Written by Hüseyin Niyazi Balın.
