# Changelog

Notable changes, newest first. Versions follow [semantic versioning](https://semver.org/); while
the package is below 1.0 a **minor** bump is where a breaking change lands, which is how Composer's
`^0.6` constraint already treats it.

Releases before 0.5.0 were internal and carried no licence, so their tags were removed rather than
left publicly installable.

## [Unreleased]

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

[Unreleased]: https://github.com/nouxwell/tabula/compare/v0.8.0...HEAD
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
