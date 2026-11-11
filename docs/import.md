# Importing records

The records of an object can come from a CSV file, the format every spreadsheet saves: from the
page of the object, in a wizard, or from the console with
[`record:import`](console.md#recordimport). Both import **all the rows or none**: every row goes
through the rules of the record form (required, unique, types, patterns, conditions), and a single
row refused leaves the object as it was, with every refused row listed by its line.

| | Wizard (`/o/<object>/import`) | Console (`record:import`) |
|---|---|---|
| Columns | Mapped to the fields on the page, proposed from the headers | The header names the fields |
| Separator | Comma or semicolon, detected | Comma |
| Rows | Up to 5,000 | Any number |
| File fields | No | Yes: the files next to the CSV file |
| Who | Whoever may create the records | Whoever runs the console |

## The wizard

Open the object's list of records and press **Import**, next to **New**. The button is there for
whoever may create the records of the object (`record.<object>.create`).

1. **File**: a CSV file, UTF-8 encoded (in Excel: *Save as > CSV UTF-8*), with a header naming the
   columns on its first line. The separator, a comma or a semicolon as Excel writes it in Italian,
   is detected from the header; choose it yourself if the detection is wrong. A file that is not
   UTF-8, has a line with more or fewer values than the header, repeats a header or has no rows is
   refused at once.
2. **Columns**: each column of the file, with its first values, and the field it fills. The
   wizard proposes the field whose machine name or label (in your language) the header holds,
   case, accents, spaces and underscores aside: a column `Chip code` or `chip_code` fills the
   field `chip_code`. Leave a column on **Ignore** to skip it. A field fills one column only.
   - **Key**: a unique field. A row whose value a record already holds updates that record, the
     others create one; an empty cell then clears the field of the record. Without a key every
     row creates a record. Updating records needs `record.<object>.update` too.
   - **Relations**: for each relation or many-to-many field, what its cells hold: the ids of the
     records, or the values of a unique field of the related object (a code, an email). Several
     values in one cell of a many-to-many field are separated by `|`.
3. **Check**: the import runs, then is rolled back: "12 record(s) will be created, 3 updated",
   with the first rows as they will be imported, or the list of the rows refused. Nothing is
   written yet. Go **Back to the columns** to change them, or fix the file and start again.
4. **Import**: the same import, for real, all or nothing again (the records may have changed
   since the check). Then the list of records, with "12 record(s) created, 3 updated."

**Cancel the import** drops the file. The wizard keeps the file uploaded between its steps, in
your session only; a file left halfway is deleted after a day.

File fields are not offered: a CSV file uploaded alone brings no files. Import them from the
console, the files next to the CSV file.

The reasons of a refused row are in English, as the other error messages of the application
(see [Languages](i18n.md#not-yet)).

### Configuration

| Setting | Default | Meaning |
|---|---|---|
| `IMPORT_DRAFTS_DIR` (environment) | `var/imports` | Where the uploaded files wait between the steps |
| `app.import.max_rows` (`config/services.yaml`) | `5000` | The most rows the wizard imports at once; a larger file goes through `record:import` |

The largest file is also capped by PHP: `upload_max_filesize` and `post_max_size`.

## The console

See [`record:import`](console.md#recordimport): the header names the fields, `--key` and
`--match` do what the key and the relations of the wizard do, `--dry-run` is the check.

```bash
symfony console record:import province provinces.csv --key=code --match=region:code --dry-run
```

## Under the hood

| Piece | Where |
|---|---|
| Reading | `App\Shared\Csv\CsvFile`: `read($path, $separator)`, `detectSeparator($path)` |
| Importing | `RecordImporter` (Record Application): `import()` reads a file for the console, `importRows()` takes rows already named by field, with a directory for file cells or none |
| Wizard | `RecordImportController`; `ImportDrafts` keeps the file (`<id>.csv`) and its draft in the session, `ImportColumns` proposes the mapping and renames the rows |
