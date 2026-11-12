# Plan: the CSV import wizard

Status: **done**, all five phases, reviewed by the user and merged into `main` on 2026-11-12.
Discussed with the user on 2026-11-11.

Item (B) of "CSV import" in [status.md](status.md): the web counterpart of `record:import`
(item A, [console.md](../console.md#recordimport)). A user uploads a spreadsheet's CSV, says
which column is which field, sees what would happen, and imports it, all or nothing, with the
same rules as the console. Item (C), large files in the background with Messenger, stays out.

## Decisions (user, 2026-11-11)

- **Who**: whoever may create the records of the object (`record.<object>.create`); an import
  with a key, which may update records, also needs `record.<object>.update`. No admin-only page.
- **Columns**: mapped in the wizard. Each column of the file gets a field, or is ignored; the
  wizard proposes the field whose machine name or label (in the user's language) matches the
  header, case and accents aside.
- **Format**: comma or semicolon (what Excel writes in Italian), detected from the header and
  correctable; UTF-8 only, with or without a byte order mark.

## The flow

`/o/<object>/import`, an **Import** button next to **New** on the list of records.

1. **Upload**: the file, and the separator (*detect*, comma, semicolon). The file is kept as a
   *draft* (below) and the wizard moves on.
2. **Columns**: one line per column of the file: its header, its first values, and a select of
   the fields (or *Ignore*). Then:
   - **Key** (optional): a unique field among those mapped. A row whose value a record holds
     updates it; without a key every row creates a record.
   - For each relation or many-to-many field mapped: what its cells hold, *record ids* or the
     values of a unique field of the target (`--match` of the console).
   - File fields are not offered: a CSV uploaded alone has no files next to it. The console
     imports them.
3. **Check**: the import runs as a dry run. Either "12 records will be created, 3 updated", or
   the list of the refused rows, each with its line. **Back** to the columns, or **Import**
   (only once the check went through).
4. **Import**: the real import, all or nothing again (the data may have changed since the
   check), then the list of records with "12 records created, 3 updated." The draft is deleted.

Every step is a page with a plain form (no JavaScript needed), translated like the record
pages. The reasons of a refused row are the importer's, in English, like the other errors of
the domain (see i18n.md, "Not yet").

## Drafts

- The uploaded file is stored as `var/imports/<uuid>.csv` (directory configurable,
  `IMPORT_DRAFTS_DIR`), and its draft in the session: `{object, file, separator, mapping,
  key, matches, checked}` under its uuid. The path never comes from a request: a request names
  the uuid, which must be one of the session's drafts for that object.
- A draft is deleted once imported, or when the user starts over. Abandoned files are deleted
  when a new draft is stored: any file of the directory older than a day.
- Limits: the upload size of PHP (`upload_max_filesize`), and a number of rows, the parameter
  `app.import.max_rows` (default 5,000): above it the wizard points to `record:import`.

## The importer

`RecordImporter` reads its file itself today. It is split:

- `CsvFile::read($path, $separator = ',')`, plus `CsvFile::detectSeparator($path)` (comma or
  semicolon, whichever the header line holds more of, outside quotes) and a refusal of a file
  that is not UTF-8.
- `RecordImporter::importRows($object, $rows, $key, $matches, $dryRun, $filesDirectory)`: the
  rows already read and named by field. Without a files directory, a file column is refused.
- `RecordImporter::import($object, $file, ...)`, as the console calls it, reads the file and
  calls `importRows()` with the file's directory: the console does not change.

The wizard reads the draft, drops the ignored columns, renames the others after their fields
and calls `importRows()`; the lines of the file are kept for the reasons.

## Phases

1. Importer: `CsvFile` separators and UTF-8 check, `importRows()`, files directory optional.
   Tests: unit (`CsvFile`), integration (`importRows`). Console unchanged.
2. Drafts and the upload step; the Import button on the list; permissions.
3. The columns step: proposed mapping, key, matches.
4. The check and import steps, flash, draft cleanup.
5. Docs (a page `docs/import.md`, links from the README, console.md, status.md), translations.

## As delivered

- `CsvFile::read()` now refuses a header repeated in one file (the rows are keyed by header:
  the second column overwrote the first), for the console as well.
- The draft is checked again before the real import: the import runs only with the columns, key
  and matches its last check passed with (`ImportDraft::fingerprint()`).
- A match chosen for a relation no column fills is dropped.

## Not in scope

- Background imports of very large files (item C, Messenger).
- Files in a web import (a ZIP with the CSV and its files): the console does it.
- Encodings other than UTF-8; tab-separated files.
- Composite forms: an import fills one object.
