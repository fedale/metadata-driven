# Importing records

The records of an object can come from a CSV file, the format every spreadsheet saves: from the
page of the object, in a wizard, or from the console with
[`record:import`](console.md#recordimport). Every row goes through the rules of the record form
(required, unique, types, patterns, conditions), and the console and the wizard's files up to
5,000 rows import **all the rows or none**: a single row refused leaves the object as it was, with
every refused row listed by its line. Larger files go to the [background](#large-files-in-the-background),
checked and imported in batches.

| | Wizard (`/o/<object>/import`) | Console (`record:import`) |
|---|---|---|
| Columns | Mapped to the fields on the page, proposed from the headers | The header names the fields |
| Separator | Comma or semicolon, detected | Comma |
| Rows | Up to 5,000 at once, up to 1,000,000 in the background | Any number |
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
     values in one cell of a many-to-many field are separated by `|`, and so are the labels of a
     `tags` field (`urgent|client x`).
   - **Codes**: a `country`, `language` or `currency` cell holds the code (`IT`, `it`, `EUR`, in
     any case) or the name, in the language of the page or in English, ignoring case (`Italia`,
     `United Kingdom`, `euro`). A `time` cell is `14:30` or `14:30:15`.
3. **Check**: the import runs, then is rolled back: "12 record(s) will be created, 3 updated",
   with the first rows as they will be imported, or the list of the rows refused. Nothing is
   written yet. Go **Back to the columns** to change them, or fix the file and start again.
4. **Import**: the same import, for real, all or nothing again (the records may have changed
   since the check). Then the list of records, with "12 record(s) created, 3 updated."

Above 5,000 rows, the check and the import run in the background: see the next section.

**Cancel the import** drops the file. The wizard keeps the file uploaded between its steps, in
your session only; a file left halfway is deleted after a day.

Some fields are not offered, by the same rules as the record form:

- file fields: a CSV file uploaded alone brings no files. Import them from the console, the
  files next to the CSV file;
- many-to-many fields going through a junction object: their links are records of the
  junction, with permissions of their own;
- relation and many-to-many fields whose related records you may not read: the form would not
  let you pick them either.

The reasons of a refused row are in the language of the page. A background import stores them
as messages, so its page shows them in the language of whoever opens it; the messages of a
validator (a text too long, an invalid email address) are translated as the check runs, in the
language of the user who started the import. The reasons of an import refused before the
reasons were translated stay in English.

## Large files, in the background

A file of more than 5,000 rows (`app.import.max_rows`) goes through the same wizard, the file and
the columns steps alike. At the check step it becomes a **background import**: the worker that
already sends the emails and runs the workflows checks it, then imports it, and the wizard takes
you to its page.

1. **Checking**: the page shows how many rows the worker has read, and follows it by itself
   (you may leave it). The check is the same dry run, batch by batch: "12,000 record(s) will be
   created, 300 updated", or the rows refused. At most 200 reasons are listed, with how many there
   were.
2. **Import** (once checked): the worker writes the rows in **batches** of 1,000, each in a
   transaction of its own. If the records changed since the check and a batch is refused, the
   import **stops** there: the batches before it stay written, and the page says how many
   records were created and updated, and from which line nothing was. Fix the rows and import the
   file again: with a key, the rows already imported update their records instead of repeating
   them.
3. A notification (the bell, and an email if you chose it on your account page) tells you when
   the check and when the import end.

While the worker has it, **Stop** ends it before its next batch: an import keeps the batches it
wrote. A checked or refused import can go **Back to the columns**: the file is a draft of the
wizard again.

**Your imports**, a link on the first step (`/o/<object>/imports`), lists your last 20
background imports of the object. An import is yours only: no one else sees its page, since its
reasons quote the file's values. The worker acts as you: with your permissions, checked again
before it starts (losing one fails the import), and your name as the author of the records and
`$user` of the workflows they trigger.

Some limits of a check done in batches: a unique value repeated in two rows of different batches
passes the check (each batch is rolled back before the next) and stops the import at the second
one. Repeated keys are found across the whole file. The workflows of the object run for every row
imported, within their limits (see [Workflows](workflows.md)).

What the worker needs:

- the worker running, `messenger:consume async scheduler_workflows` (see
  [Workflows](workflows.md)): without it, an import waits on *Checking*;
- the same files as the web server: the background imports' files are in `IMPORT_DRAFTS_DIR/jobs`,
  so a worker on another machine needs that directory on a shared disk;
- a job's file is deleted when the import ends; a checked or refused import keeps it for a week,
  then the file must be uploaded again.

### Configuration

| Setting | Default | Meaning |
|---|---|---|
| `IMPORT_DRAFTS_DIR` (environment) | `var/imports` | Where the uploaded files wait between the steps; the background imports' in its `jobs/` directory |
| `app.import.max_rows` (`config/services.yaml`) | `5000` | The most rows the wizard imports at once, in the request; a larger file goes to the background |
| `app.import.max_background_rows` (`config/services.yaml`) | `1000000` | The most rows of a background import; a larger file goes through `record:import` |
| `app.import.batch_size` (`config/services.yaml`) | `1000` | The rows of one batch of a background import, written in one transaction |

The largest file is also capped by PHP: raise `upload_max_filesize` and `post_max_size` (and,
behind a proxy, its own body limit) for files of hundreds of megabytes.

## The console

See [`record:import`](console.md#recordimport): the header names the fields, `--key` and
`--match` do what the key and the relations of the wizard do, `--dry-run` is the check.

```bash
symfony console record:import province provinces.csv --key=code --match=region:code --dry-run
```

## Under the hood

| Piece | Where |
|---|---|
| Reading | `App\Shared\Csv\CsvFile`: `read($path, $separator)`, `rows()` one at a time, `header()`, `detectSeparator($path)` |
| Importing | `RecordImporter` (Record Application): `import()` reads a file for the console, `importRows()` takes rows already named by field, with a directory for file cells or none; inside, `prepare()` checks the columns once, `keyProblems()` the keys of the whole file, `importBatch()` writes rows in one transaction |
| Wizard | `RecordImportController`; `ImportDrafts` keeps the file (`<id>.csv`) and its draft in the session, `ImportColumns` proposes the mapping and renames the rows |
| Background | `BackgroundImports` (Record Application) queues `CheckImport` and `RunImport` (Messenger, `async`) and runs them for the worker, as the job's creator (`ActingUserInterface`, User contract); the jobs in `core.record_import` (`ImportJobRepository`); `ImportFailureListener` fails a job whose message failed for good; the pages, `RecordImportJobController` |
