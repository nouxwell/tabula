# Changelog

Notable changes, newest first. Versions follow [semantic versioning](https://semver.org/); while
the package is below 1.0 a **minor** bump is where a breaking change lands, which is how Composer's
`^0.6` constraint already treats it.

Releases before 0.5.0 were internal and carried no licence, so their tags were removed rather than
left publicly installable.

## [Unreleased]

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

[Unreleased]: https://github.com/nouxwell/tabula/compare/v0.8.2...HEAD
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
