# Changelog

Notable changes, newest first. Versions follow [semantic versioning](https://semver.org/); while
the package is below 1.0 a **minor** bump is where a breaking change lands, which is how Composer's
`^0.6` constraint already treats it.

Releases before 0.5.0 were internal and carried no licence, so their tags were removed rather than
left publicly installable.

## [Unreleased]

### Added

- **A schema for the browser's grid.** `GridSchema` adds to a `Schema` what the grid of
  @nouxwell/tabula-grid needs: a version, lookups, fields that are read-only or not sent, and
  rules (`Rule::min` and `Rule::max` as decimal text, `minLength`, `maxLength`, `unique`), each
  refused on a field it does not fit. `Tabula::gridSchema()` writes it as the module schema the
  grid reads, with labels, the title and option labels translated as a template translates them.
- **`Tabula::checkRows()`** checks the rows a grid sends, for its pre-flight check or its run,
  against the schema and its rules as the browser checked them. It answers in the grid's issue
  shape (`GridIssue::toArray()`): the row's id, the field, the code, the params, and for a value
  on several rows, the other rows. Values are read in the form the grid sends them (decimal text
  with a point, ISO dates, booleans, an option's value, a lookup's id), never in a user's writing.
  A request not in the grid's shape throws the new `GridException`.

### Fixed

- **A dropdown cell's fill handle works in a protected template.** Also released for the 0.8
  line as 0.8.9; see there.

### Notes

- The shared file of cases the browser side is checked against now holds whole rows too: the
  grid's `SheetValidator` and `checkRows()` must find the same issues in them.

## [0.9.0] — 2026-11-17

### Fixed

- **A date that does not exist is refused, wherever it was read.** The pattern path already
  refused "32.13.2024"; a date the pattern missed went on to PHP's free-form reader, which rolled
  "31.02.2026" into 3 March and "2026-02-30" into 2 March and only left a warning behind. Both
  are now `not_a_date`, as is an hour such as 24:00.

### Changed

- **A date the pattern does not read is no longer guessed.** After the field's pattern and an
  Excel serial number, the parser now tries the other temporal pattern (the date-and-time pattern
  for a date field, the date pattern for a date-time field) and ISO 8601 (`2024-01-05`,
  `2024-01-05 09:30`, `2024-01-05T09:30:00`), and nothing else. PHP's free-form reader is gone:
  with Turkish settings it read "05/03/2026" as 3 May, without an error.

### Notes

- **Breaking** for a file whose dates were only readable by the free-form reader: a slashed date
  such as "05/03/2026" or "01/31/2026", a month written as a word ("1 March 2026"), and an ISO time
  with an offset or a `Z` ("2024-01-05T09:30:00Z"). Each is now a `not_a_date` error naming the
  expected pattern. Give the field a `pattern()` to read slashed dates, in the order the file
  writes them.
- The rule now matches @nouxwell/tabula-core, the browser side, which reads dates the same way.
  The two are checked against one shared file of cases.
- `EnumParser`'s description said "AÇIK" matches "açık". It does not: case is folded without a
  locale, and "AÇIK" folds to "açik". Only the description changed.

## [0.8.9] — 2026-11-18

### Fixed

- **A dropdown cell's fill handle works in a protected template.** With `protectHeader` on, a
  cell with a dropdown could not be dragged down: the handle was drawn, but a press on it opened
  the list, so a country had to be picked again on every row. The protection prohibited editing
  objects, as Excel's own dialog does by default, and with that prohibited Excel lets the
  dropdown's arrow take over its cell's fill handle. Editing objects is now allowed. The header
  stays locked, formatting cells and inserting or deleting columns stay prohibited, and the
  arrow still opens the list. Measured in Excel 16 with a real drag of the handle.

### Notes

- A template written before keeps its old protection. In one, Ctrl+D or a drag from two selected
  cells, whose handle sits away from the arrow, still fills a dropdown column.
- Released from v0.8.8 for installs still on 0.8. The next 0.9 release carries the same fix.

## [0.8.8] — 2026-11-16

### Changed

- **A template's dropdown column is sized to its header again, as before 0.8.7.** Sized to its
  longest option, a country column came out about three times as wide, while the open list,
  drawn in a smaller font than the cell, used two thirds of it at most. A user who wants a wider
  column widens it by hand, and a schema can still set one with `Field::width()`.

## [0.8.7] — 2026-11-16

### Changed

- **A template's dropdown column is wide enough for its longest option.** Excel draws the open
  list from the column's width, and the options sit on the hidden `_lists` sheet, so the column
  was sized to its header alone: a country column headed "Ülke" showed "Amerika Birleşik
  Devletleri" as "Amerika Birleşik Devlet". The column now takes the wider of its header (with
  the filter button's room) and its longest option, with or without the auto-filter.

### Notes

- The widening stops at 40, so one option written as a sentence does not stretch its column to
  the sentence; a header wider than that still widens its column as before. A width set with
  `Field::width()` is left as given.
- Only the width can be set from a file. The eight rows the open list shows, its scroll bar and
  its look are Excel's own.

## [0.8.6] — 2026-11-03

### Changed

- **An xlsx or xls import loads the workbook once instead of twice.** Before reading a row the
  import checks how many visible sheets the file has, and that check loaded the whole workbook
  just to look at its sheet names; reading the rows then loaded it again. The sheet names and
  their visibility now come from the workbook's own index (`listWorksheetInfo()`), without building
  a single cell. On a 5,000-row, 40-column sheet the check took 8.7 s of a 16.3 s import; it now
  takes 0.8 s and the import 9.7 s.

### Notes

- The result is the same: hidden sheets still do not count, a workbook with more than one visible
  sheet is still refused with their names, and a broken file is still reported as unreadable. A
  reader whose index cannot be read falls back to loading the workbook, as before.

## [0.8.5] — 2026-11-03

### Added

- **`ImportBuilder::only()` reads the named fields and leaves the file's other columns alone.**
  The file is still matched against the whole schema: a schema narrowed with `Schema::only()` does
  not recognise its own template's key row, because a key row counts only when every key in it
  belongs to the schema, and the file was refused as the wrong template. Columns left out are not
  parsed, so a blank required cell or an unreadable value in them is no error.
- **`ImportedRow::only()` returns some of a row's values**, keyed by field key in the order asked,
  each key checked like `get()`.

### Notes

- An unknown key or an empty list fails on the `only()` call itself, with the same
  `SchemaException` as `Schema::only()`. A picked field the file lacks is absent from the rows,
  like any missing column; a file with none of the picked fields is refused rather than handing
  over empty rows.
- `ImportResult::$columns` lists the columns that were read, so with `only()` it holds the picked
  ones only. Columns left out are not added to `ImportResult::$ignored`, which stays for headers
  the schema does not know.
- `ImportedRow::only([])` returns an empty array.
- USAGE.md still said a date column refuses what is not a date; it now says what 0.8.4 does.

## [0.8.4] — 2026-11-03

### Changed

- **A template's date column no longer refuses a value Excel does not read as a date; it says so
  and keeps it on OK.** Excel decides whether "31.01.2026" is a date from the regional format of
  the user's Windows, not from the file. On a machine whose region writes "01/31/2026" the date
  stays text, and the stop rule refused it — the very format the column's example shows, and one
  the import reads with the date pattern. The only way past it was the slash, which follows that
  machine's month-first order: 05/03 went in as the 3rd of May and imported without an error.
  Integer and decimal columns still refuse what is not a number.

### Notes

- A date column still shows Excel's own "does not match" message, in Excel's language. The rule
  is an information rule now rather than a warning: a warning asks "Continue?" on every such
  cell, which pushes the user to the slash all the same.
- Text that is no date at all now gets past the cell too and becomes a `not_a_date` row error on
  import.

## [0.8.3] — 2026-09-16

### Added

- **`RowError::$code` and `RowError::$params` let an application word import errors in its own
  language.** `RowError::$message` is a sentence in English. An application whose users read
  another language could show it anyway, or check every cell a second time in its own code to get
  its own wording — a second copy of the rules the schema is there to hold once. Every cell failure
  now also carries a `RowErrorCode` and the values its wording needs, under plain names the
  library's `Translator` port takes as they are:

  ```php
  $tabula->translator()->trans('import.error.'.$error->code->value, $error->params, 'tr');
  // import.error.not_a_number: '"%value%" bir sayı değil.'
  ```

  The codes are `required`, `not_a_number`, `not_an_integer`, `not_a_date`, `not_a_boolean` and
  `not_an_option`.
- **`ImportBuilder::keepRawValues()` keeps the value the reader produced, before the field's parser
  touched it; `ImportedRow::raw()` and `rawValues()` return it.** The parsed value says what a cell
  means, not what was in it: a quantity written as `1.234,5` comes back as `1234.5` and the text is
  gone. That is right for saving the record, and not enough for showing the user what they
  uploaded, for an audit trail, or for moving code that normalises strings itself over to typed
  values one field at a time.

### Notes

- No existing method, argument or message changed. Every message is the same sentence byte for
  byte, `toArray()` still returns only parsed values, a required-field error still has no `value`,
  and the new constructor parameters come last with defaults. The objects do carry more: a
  `RowError` now has `code` and `params`, and a row imported with raw values holds them, so
  comparing one with a hand-built object, or its `json_encode`/`serialize` output, sees the new
  fields.
- Raw values are off by default. Rows streamed through the callback cost the same either way; code
  that keeps the `ImportedRow` objects was measured holding about half as much memory again per row
  with them on. Without `keepRawValues()`, `raw()` refuses rather than answering null for every
  field.
- The codes name the failure, not the field type: money and quantity columns both report
  `not_a_number`, enum and options columns both `not_an_option`; `params['type']` tells them apart.
  A mistake in the schema or the set-up — no parser for a type, an enum field pointing at a class
  that is not an enum — still stops the run with an exception and has no code. New codes may come
  in a later release, so a `match` over them should keep a `default` arm.
- The params are plain names because the `Translator` port adds the `%…%`. Symfony's own
  `TranslatorInterface` does not, and takes the domain rather than the locale as its third
  argument, so going around the port means wrapping the names first.
- A custom parser that throws `new ParseException(...)` produces an error whose code is null; its
  message still reaches the user. The code lives in `ParseException::rowErrorCode()`, not in
  `$code`, which PHP keeps for the exception's own integer code.
- With `ErrorMode::FailFast` the exception's message is still English; `rowErrors()` carries the
  codes.
- `raw()` is the reader's value, not the user's keystrokes. A CSV cell is the text in the file (an
  empty cell is an empty string); an xlsx cell is what the workbook stores — a number as an int or
  float, a date as its serial number, a formula as its result as PhpSpreadsheet calculates it, an
  empty cell as null. Only the schema's fields are kept, and only accepted rows reach the callback.

## [0.8.2] — 2026-09-15

### Added

- **`TemplateOptions::$protectHeader` (`template.protect_header`) locks the key and label rows
  of a template in Excel.** A hidden key row keeps the keys out of sight, not out of reach: a
  user who unhides it can type over a key, and two keys swapped between columns of the same type
  import without an error, each column's values landing in the other's field. With the option
  on, the sheet is protected without a password. The header cannot be typed over, cleared,
  pasted over or unhidden; typing, pasting from another workbook, filling down, inserting and
  deleting rows, sorting and filtering through the header buttons and column widths keep
  working.

### Notes

- Off by default, because protection takes some things away: formatting cells, inserting or
  deleting columns, sorting a range that includes the header, and typing into a row inserted
  directly under the header (Excel copies the header's locked format into it).
- The whole sheet is unlocked first and the header locked back, rather than unlocking only the
  schema's columns. Measured in Excel: with the columns after the schema left locked, deleting or
  clearing a whole row and pasting a block one column wider were all refused.
- No password, on purpose — Review › Unprotect Sheet is one click. It stops accidents; an import
  that must not trust the file still has to check the key row itself.

## [0.8.1] — 2026-09-15

### Added

- **`Field::example()` shows a sample value in the template without writing it into a cell.**
  Selecting a cell of the column pops up Excel's input message — "Example: 120.01.001", with
  "Required" on a second line for a required column. Give it a value of the field's own type
  and it is formatted the way the export formats that type: `1250.5` reads "1.250,50" under
  Turkish number settings, a date follows the date pattern, `true` is the translated yes-word,
  an options column takes the option key. On a text column a string resolves like a label.
- `TemplateOptions::$exampleWord` / `$requiredWord` (`template.example_word` /
  `template.required_word` in the bundle) — plain words by default, translated when the
  catalogue defines them, like the boolean words.
- `Field::format()` and `Field::currency()` accept `null`, taking a formatter or a currency set
  earlier back off a copy of the field.

### Notes

- A sample row in the data area was the obvious alternative and the wrong one: a reader takes
  every non-empty row as data, so an example a user forgets to delete is imported as a real
  record. A message cannot be imported.
- A text column that has an example now carries an "any value" validation. It holds the message
  and refuses nothing; a text column without an example still gets no validation at all.
- Excel refuses an input-message title over 32 characters and a text over 255 UTF-16 units — an
  emoji counts twice there, as a check in Excel showed. Both are cut without splitting a
  character, and when the text is too long the example gives way before the "Required" line.
- A template has no data row. On text, number, money and date columns the example is formatted
  by the column's type alone: a `format()` closure is not applied and money shows no currency
  symbol, exactly like the column's own cell format. So a closure typed `fn (array $row)` — the
  README's own currency pattern — is never called with `null`; before a pre-release review
  caught it, adding an example to such a column made the whole template throw.
- Bool, enum and options columns format their example through the same call as their dropdown
  list, `format()` closure and `null` row included, so the example reads exactly like its entry in
  the list. Those closures already had to accept a `null` row for the list itself; the README now
  says so.
- Templates format with the built-in formatters, as their dropdown lists always have. A custom
  `FormatterRegistry` given to `Tabula` reaches the export only.

## [0.8.0] — 2026-08-31

### Fixed

- **A boolean cell no longer prints a translation key.** The defaults for what a true or false
  cell says were `tabula.bool.yes` and `tabula.bool.no`, which no catalogue defines — so an
  export made without configuring them wrote those strings into the file, and `BoolParser` could
  not read them back. They are now the plain words `Yes` and `No`, which survive both
  directions. Naming a key still translates exactly as before.

### Added

- `KeyRow` makes the hidden-key-row rule public. An application with its own reader has to
  answer "is row 1 the key row?" before it knows where the data starts; sharing the rule keeps
  it from being written a second time and drifting.

### Notes

- **Breaking** for anyone whose catalogue defines `tabula.bool.yes`: it is no longer consulted.
  Name the key in the settings to restore the old behaviour.
- Found by using the library for real: of twenty schemas written against it during an
  integration, nineteen avoided `Field::bool()` entirely.

## [0.7.3] — 2026-08-31

### Fixed

- Column widths are clamped to Excel's maximum of 255. Auto-sizing measures the header text and
  nothing clamped the result, so a header of around 210 characters produced a width Excel
  refuses and the workbook opened saying it needed repairing.

## [0.7.2] — 2026-08-31

### Fixed

- **A numeric header no longer sits under the auto-filter button.** The button is drawn at the
  cell's right edge and a right-aligned label is pushed to that same edge, so they overlapped
  however wide the column was — widening a right-aligned cell adds the room on the left. The
  header cell loses its right alignment; the data column keeps it.

## [0.7.1] — 2026-08-31

### Fixed

- Auto-sized template columns leave room for the auto-filter button, which used to cover the
  last characters of the header. A width set by hand is untouched.

## [0.7.0] — 2026-08-31

### Fixed

- **A workbook split across sheets is no longer half-read.** The export can split a file per group
  or per chunk, so the library could produce a workbook it then could not read back: the import took
  sheet one and stopped, dropping the rest with no error. Three rows exported across two sheets came
  back as two. Such a file is now refused, and the message names the sheets.

### Added

- `ImportBuilder::sheet(string $name)` selects which sheet to read. Naming a sheet on a format that
  has none (CSV) is refused rather than ignored.

### Notes

- Hidden sheets do not count as data, so a filled-in template — which always carries the hidden
  `_lists` helper — is still a single-sheet file and is read as before.
- **Breaking** for anyone whose multi-sheet import appeared to work: it will now throw. What it was
  doing before was losing rows silently.

## [0.6.0] — 2026-08-28

### Added

- **Templates validate types in the cell.** Date columns accept dates, integer columns whole
  numbers, other numeric types decimals. The import parser caught these anyway, but only after the
  file had been filled in and sent back.

### Notes

- Blank cells stay allowed on every rule, and the error text is left to Excel so it appears in the
  user's own language. Both match what the dropdowns already did.
- **Breaking** in output: values a template used to accept are now refused as they are typed. The
  API did not change.

## [0.5.3] — 2026-08-28

### Changed

- Re-tagged onto a rewritten history. Identical in content to 0.5.2; the earlier tags pointed at
  commits that no longer existed, and a package index will not accept a tag whose commit moved
  underneath it.

## [0.5.2] — 2026-08-28

### Added

- `homepage` and `support` metadata, so the package page links to its issue tracker.

## [0.5.1] — 2026-08-28

### Changed

- The copyright notice identifies the holder by website.

## [0.5.0] — 2026-08-28

First public release. Licensed under MIT.

The library is complete in all three directions:

- **Export** to Excel, CSV and PDF from one schema, with sheet strategies, a PDF column budget and
  locale-aware number, date, money and enum handling.
- **Templates** — a blank file generated from that same schema, carrying a hidden canonical key row
  so a changed translation cannot break files users already hold.
- **Import** — the filled file read back, matched on those keys, with per-row errors instead of a
  single failed batch.

Requires PHP 8.3 or newer.

[Unreleased]: https://github.com/nouxwell/tabula/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/nouxwell/tabula/compare/v0.8.8...v0.9.0
[0.8.9]: https://github.com/nouxwell/tabula/compare/v0.8.8...v0.8.9
[0.8.8]: https://github.com/nouxwell/tabula/compare/v0.8.7...v0.8.8
[0.8.7]: https://github.com/nouxwell/tabula/compare/v0.8.6...v0.8.7
[0.8.6]: https://github.com/nouxwell/tabula/compare/v0.8.5...v0.8.6
[0.8.5]: https://github.com/nouxwell/tabula/compare/v0.8.4...v0.8.5
[0.8.4]: https://github.com/nouxwell/tabula/compare/v0.8.3...v0.8.4
[0.8.3]: https://github.com/nouxwell/tabula/compare/v0.8.2...v0.8.3
[0.8.2]: https://github.com/nouxwell/tabula/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/nouxwell/tabula/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/nouxwell/tabula/compare/v0.7.3...v0.8.0
[0.7.3]: https://github.com/nouxwell/tabula/compare/v0.7.2...v0.7.3
[0.7.2]: https://github.com/nouxwell/tabula/compare/v0.7.1...v0.7.2
[0.7.1]: https://github.com/nouxwell/tabula/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/nouxwell/tabula/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/nouxwell/tabula/compare/v0.5.3...v0.6.0
[0.5.3]: https://github.com/nouxwell/tabula/compare/v0.5.2...v0.5.3
[0.5.2]: https://github.com/nouxwell/tabula/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/nouxwell/tabula/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/nouxwell/tabula/releases/tag/v0.5.0
