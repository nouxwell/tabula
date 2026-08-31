# Usage

Worked examples for the jobs people actually have. The [README](README.md) explains what each part
is; this file shows them doing something.

Every example is complete enough to paste and adapt. Where a decision could go either way, the
reason for the one taken is stated — those are usually the parts worth stealing.

- [1. A list of rows to Excel](#1-a-list-of-rows-to-excel)
- [2. A Doctrine query, without loading it all](#2-a-doctrine-query-without-loading-it-all)
- [3. Money that changes currency per row](#3-money-that-changes-currency-per-row)
- [4. Enums and yes/no columns](#4-enums-and-yesno-columns)
- [5. One tab per warehouse](#5-one-tab-per-warehouse)
- [6. A PDF that fits the paper](#6-a-pdf-that-fits-the-paper)
- [7. A blank template, and reading it back](#7-a-blank-template-and-reading-it-back)
- [8. Reporting import errors to the user](#8-reporting-import-errors-to-the-user)
- [9. A machine-to-machine CSV feed](#9-a-machine-to-machine-csv-feed)
- [10. Reading a workbook split across sheets](#10-reading-a-workbook-split-across-sheets)
- [11. Columns that only appear in one format](#11-columns-that-only-appear-in-one-format)
- [12. Taking over the formatting yourself](#12-taking-over-the-formatting-yourself)
- [13. Building the schema at runtime](#13-building-the-schema-at-runtime)
- [14. Wiring it into Symfony](#14-wiring-it-into-symfony)
- [15. Adopting it where a reader already exists](#15-adopting-it-where-a-reader-already-exists)
- [16. How much can it export?](#16-how-much-can-it-export)

---

## 1. A list of rows to Excel

The shortest thing that does real work.

```php
use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Port\ArrayTranslator;
use Nouxwell\Tabula\Schema\{Field, Schema};
use Nouxwell\Tabula\Settings\TabulaSettings;
use Nouxwell\Tabula\Source\ArraySource;
use Nouxwell\Tabula\Tabula;

$schema = Schema::make('customer')->fields(
    Field::string('code')->label('Code')->required(),
    Field::string('name')->label('Name'),
    Field::decimal('balance')->label('Balance')->decimals(2),
);

$rows = [
    ['code' => 'C-001', 'name' => 'Acme Ltd',   'balance' => 1234.5],
    ['code' => 'C-002', 'name' => 'Globex Inc', 'balance' => -89.9],
];

$tabula = new Tabula(new ArrayTranslator([]), new TabulaSettings());

$result = $tabula->export($schema)
    ->from(ArraySource::of($rows))
    ->locale('en')
    ->to(Format::Xlsx)
    ->write('/tmp/customers.xlsx');

$result->rows;   // 2
$result->path(); // /tmp/customers.xlsx
```

Labels here are plain text. Give a translation key instead and it is resolved through the
translator — see [§14](#14-wiring-it-into-symfony).

---

## 2. A Doctrine query, without loading it all

`ArraySource::of($repo->findAll())` loads every row into memory before the first one is written.
`DoctrineSource` hands them over one at a time.

```php
use Nouxwell\Tabula\Source\DoctrineSource;

$qb = $em->createQueryBuilder()
    ->select('c.code', 'c.name', 'c.balance')
    ->from(Customer::class, 'c')
    ->where('c.active = true');

$tabula->export($schema)
    ->from(DoctrineSource::of($qb))     // one query, streamed row by row
    ->locale('en')
    ->to(Format::Xlsx)
    ->write('/tmp/customers.xlsx');
```

**Scalar projections keep memory flat.** Selecting whole entities (`select('c')`) hydrates objects
that stay managed in the UnitOfWork, and the export ends up holding the table anyway.

### When to reach for `chunk()`

```php
DoctrineSource::of($qb->orderBy('c.id', 'ASC'))->chunk(2000);
```

One query per page instead of one long-lived cursor. It enforces two rules rather than trusting you
to remember them:

- **`ORDER BY` is mandatory.** Without a stable sort, `LIMIT`/`OFFSET` silently skips and repeats rows.
- **A `setMaxResults` you set yourself is rejected.** Chunking would overwrite it, turning a query
  meant as "a preview, 50 rows at most" into a full table scan.

---

## 3. Money that changes currency per row

A currency column is not one symbol. Pass a closure and each row answers for itself.

```php
use Nouxwell\Tabula\Settings\{NumberSettings, SymbolPosition, TabulaSettings};

$schema = Schema::make('invoice')->fields(
    Field::string('no')->label('Invoice'),
    Field::money('total')->label('Total')
        ->currency(fn (array $row): string => $row['currencyCode']),
);

$settings = new TabulaSettings(
    numbers: new NumberSettings(
        currencySymbols: ['TRY' => '₺', 'USD' => '$', 'EUR' => '€'],
        symbolPosition: SymbolPosition::After,
        decimalSeparator: ',',
        thousandSeparator: '.',
    ),
);
```

`1234.5` with `TRY` becomes `1.234,50 ₺` in the cell text — and the cell still holds the **number**
`1234.5`, so Excel can sum the column. Both representations go in; the text is what the user reads,
the number is what the spreadsheet computes with.

That matters on the way back too. The import strips the symbol before parsing, because a symbol
containing a dot (`S/.`, or a code written next to the amount) leaves `1.234,56.` behind after
cleanup, whose right-most dot stops being the decimal point — a hundred-fold error in accounting
data.

---

## 4. Enums and yes/no columns

```php
enum OrderStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return 'order.status.'.$this->value;
    }
}

$schema = Schema::make('order')->fields(
    Field::string('no')->label('Order'),
    Field::enum('status', OrderStatus::class)->label('Status'),
    Field::bool('isPaid')->label('Paid'),
);
```

The enum resolves to a translation key by trying `TranslatableEnum::translationKey()`, then a
`label()` method, then the backing value. An enum you already have usually works untouched.

**Booleans say `Yes`/`No` by default.** To say something else, give the words — or translation keys
if you want them translated:

```php
new TabulaSettings(boolTrueKey: 'Evet', boolFalseKey: 'Hayır');
new TabulaSettings(boolTrueKey: 'app.yes', boolFalseKey: 'app.no');  // resolved by the translator
```

Both survive the round trip: `BoolParser` accepts `yes/no`, `true/false`, `evet/hayır`, `1/0` and
real booleans.

---

## 5. One tab per warehouse

```php
use Nouxwell\Tabula\Export\Sheet\{ChunkedSheets, GroupedSheets};

->sheets(new GroupedSheets('warehouseName'))   // a tab per distinct value
->sheets(new ChunkedSheets(50_000))            // a new tab every 50,000 rows
```

> **Sort first.** `GroupedSheets` does not reorder anything — it starts a new sheet when the value
> changes. An unsorted source produces `Ankara`, `Izmir`, `Ankara (2)`.

A row whose grouping value is empty lands on a sheet called `Other`; pass a second argument to name
it yourself.

Reading such a file back is [§10](#10-reading-a-workbook-split-across-sheets).

---

## 6. A PDF that fits the paper

Excel has no width; paper does. The column count is therefore arithmetic, not taste:

```
budget = floor( (paper width − margins) ÷ minimum column width )
```

```php
use Nouxwell\Tabula\Export\Page\{ColumnBudget, Overflow, Page};

$tabula->export($schema)
    ->from(ArraySource::of($rows))
    ->locale('en')
    ->to(Format::Pdf)
    ->page(Page::a4()->landscape()->margins(10))
    ->columns(ColumnBudget::fit()->minWidth(22)->anchor('code'))
    ->write('/tmp/report.pdf');
```

A4 landscape is 297 − 20 = 277 mm; at 22 mm that is 12 columns. Switch to A3 and the same schema
gets 18 — the paper widens the table, nothing else changes.

**With more columns than budget**, pick the behaviour:

| | What happens | Loses data |
| --- | --- | --- |
| `Overflow::NextPageSet` *(default)* | Columns split into groups, each with its own pages | no |
| `Overflow::Drop` | Sheds `Optional` columns first, never `Always` | **yes** |
| `Overflow::Shrink` | Squeezes everything onto one width | no, but may be unreadable |

```php
->columns(ColumnBudget::fit()->max(8)->overflow(Overflow::Drop))   // one-page summary
```

`anchor('code')` repeats that column in every group — without it, a reader looking at the second
group cannot tell which row they are on.

Mark what must never be dropped:

```php
Field::string('code')->label('Code')->priority(Priority::Always),
Field::string('note')->label('Note')->priority(Priority::Optional),
```

> Passing `->page()` or `->columns()` to Xlsx or CSV **fails loudly**. Silently ignoring a page size
> is how a PDF ends up on the wrong paper while the code says otherwise.

---

## 7. A blank template, and reading it back

```php
// Generate
$tabula->template()->write($schema, '/tmp/template.xlsx', 'en');

// The user fills it in and uploads it
$result = $tabula->import($schema)
    ->from('/tmp/filled.xlsx')
    ->locale('en')
    ->each(fn (ImportedRow $row) => $repository->save($row->toArray()))
    ->run();

$result->imported;  // 4812
$result->errors;    // list<RowError>
```

The template is laid out:

```
row 1  →  canonical field keys   (hidden)
row 2  →  translated labels      (what the user reads)
row 3+ →  data
```

**Row 1 is why any of this holds together.** The file's identity is the field key, not the header
text — so renaming a label in your translation catalogue leaves every template users already
downloaded perfectly readable. Where the header text is the identity, that same rename invalidates
all of them at once, and nobody finds out until an import is rejected.

Values come back **typed**: a bool is a bool, an enum field is an enum instance, a date is a
`DateTimeImmutable`, a quantity is a float.

Templates carry real Excel dropdowns for bool, enum and options columns. The dropdown entries and
the values the parser accepts come from the same call, so a value the template offered can never be
rejected by the import that reads it.

Typed columns are also validated **in the cell**: a date column takes dates, an integer column whole
numbers, the other numeric types decimals. The import would catch these too, but only after the user
had filled the whole file in and uploaded it.

---

## 8. Reporting import errors to the user

An unreadable cell does not stop the run. It becomes a `RowError` naming the row and the field, and
the remaining rows carry on.

```php
$result = $tabula->import($schema)
    ->from($path)
    ->locale('tr')
    ->each(fn (ImportedRow $row) => $repository->save($row->toArray()))
    ->run();

foreach ($result->errorsByRow() as $rowNumber => $errors) {
    foreach ($errors as $error) {
        // "row 37 · balance · "N/A" could not be read as a number."
        $messages[] = sprintf('row %d · %s · %s', $rowNumber, $error->field, $error->message);
    }
}
```

The row number is **the one the user sees in Excel**, so "row 37" sends them to row 37.

To stop at the first problem instead:

```php
use Nouxwell\Tabula\Import\ErrorMode;

->onError(ErrorMode::FailFast)
```

> **Transactions are yours.** The library parses and validates; it never touches your database. That
> is deliberate: wrapping a 5,000-row import in one transaction means a typo on row 37 discards the
> other 4,999.

---

## 9. A machine-to-machine CSV feed

The defaults target a human opening the file in Excel: `;` as the delimiter, a UTF-8 BOM, CRLF. A
consumer expecting RFC 4180 wants none of that.

```php
use Nouxwell\Tabula\Export\Writer\{CsvOptions, CsvWriter};

$tabula->export($schema)
    ->from($source)
    ->locale('en')
    ->to(Format::Csv)
    ->writer(new CsvWriter(CsvOptions::rfc4180()))   // comma, no BOM, LF
    ->write('/tmp/feed.csv');
```

Why `;` by default: in a Turkish or European Excel, `,` is the **decimal** separator, so a
comma-delimited file splits every number across two columns. The BOM is there because without it
Excel reads the file as cp1254 and mangles `ş ğ ı İ ö ç ü` on the first line.

---

## 10. Reading a workbook split across sheets

An export split by group or chunk comes back one sheet at a time:

```php
foreach (['Ankara', 'Izmir'] as $sheet) {
    $tabula->import($schema)->from($path)->sheet($sheet)->each($save)->run();
}
```

Without `->sheet()`, a workbook holding more than one **data** sheet is refused, and the message
names them. Reading the first and stopping would drop the rest silently — the row count would look
plausible and nothing would point at what was missing.

Hidden sheets are not data, so a filled-in template (which always carries a hidden `_lists` helper)
is still a single-sheet file and needs none of this.

---

## 11. Columns that only appear in one format

A PDF has no room for the note column; the Excel file should still carry it.

```php
Field::string('note')->label('Note')->only(Format::Xlsx, Format::Csv),
```

The template drops PDF-only fields too: offering a column the user cannot fill in and the import
does not expect only breeds the question "why does this field not work?".

---

## 12. Taking over the formatting yourself

When a column needs something the type system will not give you:

```php
Field::string('label')->label('Label')->format(
    fn (mixed $value, array $row): string => sprintf('%s / %s', $row['code'], $row['name']),
),
```

The result is written as text and stays text in Excel — an explicit choice, since the library can no
longer tell what kind of value it is.

For a value that lives somewhere awkward:

```php
Field::string('city')->from('address.city'),              // dot path
Field::string('city')->from(fn ($row) => $row->city()),   // closure
```

---

## 13. Building the schema at runtime

A schema is ordinary PHP. When the columns depend on data — price levels, a variant, whatever the
user picked — build the field list and spread it:

```php
$fields = [
    Field::string('code')->label('Code')->required(),
    Field::string('name')->label('Name'),
];

foreach ($priceLevels as $level) {
    $fields[] = Field::money('price_'.$level->id)
        ->label($level->name)
        ->decimals(2);
}

$schema = Schema::make('item')->fields(...$fields);
```

Keys must stay **stable** across runs. A key derived from position (`price_1`, `price_2`) means
inserting a level silently re-points every column after it; derive it from the level's identity.

---

## 14. Wiring it into Symfony

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
        domains: ['messages', 'enum']   # tried in order; the first that DEFINES the key wins
    numbers:
        decimal_separator: ','
        thousand_separator: '.'
        currency_symbols:
            TRY: '₺'
    pdf:
        page_size: a4
        orientation: landscape
```

Then inject `Nouxwell\Tabula\Tabula` anywhere.

**If you only need the translator adapter**, register that one service instead of the whole bundle:

```yaml
Nouxwell\Tabula\Bridge\Symfony\SymfonyTranslator:
    arguments:
        $translator: '@translator'
        $domains: ['messages']
```

**The locale is always explicit.** Every call takes it, and nothing reads "the language of the
current request" — because inside a queue worker there is no such thing, and a header built in the
wrong language is the kind of bug that only shows up in production.

---

## 15. Adopting it where a reader already exists

Most applications meet this library with an importer already in place, and that importer assumes
row 1 is the header. A template with a hidden key row would have its label row read as data — one
fabricated record per import.

Two ways through.

**Turn the key row off** and change nothing else:

```php
use Nouxwell\Tabula\Template\TemplateOptions;

new TemplateBuilder($translator, $settings, new TemplateOptions(includeKeyRow: false));
```

Row 1 becomes the labels, data starts at row 2, and your existing reader works untouched. You lose
the protection against translation changes — that is the trade.

**Or teach the reader the rule**, and keep it:

```php
use Nouxwell\Tabula\Import\KeyRow;

$firstRow = $this->cellsOfRow($sheet, 1);

if (KeyRow::matches($firstRow, $knownFieldKeys)) {
    $headers = $firstRow;   // canonical keys
    $firstDataRow = 3;
} else {
    $headers = $this->resolveLabels($firstRow);
    $firstDataRow = 2;
}
```

`KeyRow` is public precisely so this rule is not written twice. Two copies drift, and the day they
do, the reader stops being able to read the files the writer produces.

Existing files are unaffected either way: their row 1 holds translated labels, which are not field
keys, so the detection does not fire.

---

## 16. How much can it export?

Be honest with yourself about the ceiling before you promise a user "export everything".

| Writer | Memory | Practical ceiling |
| --- | --- | --- |
| `Format::Csv` | streams — one row at a time | none worth naming |
| `Format::Xlsx` | whole workbook in memory | tens of thousands of rows |
| `Format::Pdf` | whole document in memory | far lower; it is a document, not a database |

The xlsx limit is PhpSpreadsheet's, not this library's, and it cannot be worked around from here.
Splitting into sheets does **not** help: `ChunkedSheets` makes more tabs inside the same in-memory
workbook.

So: for a report someone reads, xlsx. For "give me everything", CSV.

Excel's own hard limit is 1,048,576 rows per sheet. `SingleSheet` rolls over to `Name (2)` rather
than producing a file Excel refuses — but by then memory has long since been the real constraint.
