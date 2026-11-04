# Plan: the `file` field type

Status: **phases 1 to 7 merged** into main; phase 8 (the GC) done on `feature/file-gc`;
phase 9 (composite forms) next.
Discussed with the user on 2026-11-03.

A field type that stores a file (an image, a PDF, any document) on a record: a product's photo,
its manual, a contract's signed copy. It is a prerequisite of the blueprint seeds that ship
files (see [blueprints-plan.md](blueprints-plan.md#seeds-with-files)).

## Open decisions

- **Production storage**: local disk or S3 (or compatible)? Flysystem lets us postpone it, but it
  changes what we test.
- **Several files on one record** — decided by the user on 2026-11-04: a `multiple: true` setting
  with a link table, see [Several files](#several-files). The child object stays the way to go
  when each file carries data of its own (a caption, a kind).

## Storage

- `league/flysystem-bundle`, installed with `composer require` (Symfony has no storage
  component). One storage, `record_files`:
  - dev and test: local adapter under `var/storage/files`, outside `public/`;
  - production: whatever the open decision picks, by configuration only.
- Files are stored under a key derived from their content, `sha256[0:2]/sha256`, so the same
  bytes are stored once. The original name is metadata, never part of the key: no path
  traversal, no clashes.

## Model

A new static table, `core.file`, through a migration:

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Primary key |
| `storage_key` | string | Key in the storage (`ab/ab12…`) |
| `original_name` | string(255) | As uploaded, sanitized; used for downloads |
| `mime_type` | string(127) | Detected from the content, not from the extension |
| `size` | bigint | Bytes |
| `sha256` | char(64) | Indexed; deduplication and blueprint upgrades |
| `width`, `height` | int, nullable | Images only |
| `created_at` | datetime | |

The field's column is a `ColumnType::Guid` with a foreign key to `core.file(id)`,
`ON DELETE RESTRICT`: a file is removed by the garbage collector only, never by the database.

Which module owns `core.file`? Recommended: a new `File` module (`Contract`: a
`FileStorageInterface` with `store()`, `read()`, `metadata()`; `Infrastructure`: Flysystem and
DBAL). `FieldType` and `Record` only see its `Contract`, as Deptrac requires.

## The type

`FileFieldType` in `FieldType/Domain/Type`, name `file`.

| Setting | Default | Description |
|---|---|---|
| `accept` | `[]` (any) | Allowed MIME types, wildcards allowed: `[image/*, application/pdf]` |
| `maxSize` | `10` | Maximum size in MB (decimal megabytes, 1 MB = 1,000,000 bytes) |
| `image` | `false` | `true`: only images; thumbnails, previews in the grid and the form |

- `validateSettings()`: known MIME patterns, `maxSize` between 0.1 and an app-wide ceiling
  (parameter `app.file.max_size`, in MB), `image: true` incompatible with a non-image `accept`.
- `denormalize()`: an `UploadedFile` (new upload), a file id (unchanged value) or null.
- `constraints()`: Symfony `File` / `Image` constraints built from the settings
  (`mimeTypes`, `maxSize`). The MIME type is sniffed from the bytes (`symfony/mime`).
- `toStorage()`: stores the upload through `FileStorageInterface` and returns the id. It runs
  after validation, as the password hashing does.
- `widget()`: a new `WidgetKind::File`; the form shows the current file (name, size, thumbnail
  for images), "Replace" and "Remove".
- An empty value on update keeps the current file (as for passwords); "Remove" sets null
  explicitly.
- Not searchable, not sortable, not unique. Filterable only on "has a file / has none".

## Serving files

- `FileController` at `/files/{id}` (and `/files/{id}/thumb/{size}` for images). It finds the
  records that reference the file and requires `record.<object>.read` on at least one of them:
  files are never public.
- Headers: `X-Content-Type-Options: nosniff` always; `Content-Disposition: attachment` for
  anything that is not an image; `inline` for images. Long cache headers, the URL is keyed by id.
- SVG is **refused** by default (it can carry scripts). It can be allowed later through a
  sanitizer.
- Thumbnails: `liip/imagine-bundle`, two filters (`file_thumb_small`, `file_thumb_medium`),
  cached in the same storage.

## Grid, export, search

- A `FileRenderer` (`Record/UI/Grid/Renderer`): a thumbnail for images, otherwise an icon and the
  file name, both linking to `/files/{id}`.
- The export writes the original name (CSV, Excel). A later option can add the absolute URL.
- The search ignores file fields; the original name is not indexed.

## Lifecycle

- A record in soft delete keeps its files: restoring it must bring them back.
- `metadata:files:gc` (CLI, `--dry-run`): deletes the `core.file` rows, and their bytes, that
  no column of any table references (archived fields included), older than a grace period
  (`--older-than=24h`) so an upload in progress is never collected. Built in phase 8.
- Purging an object or a field leaves its files orphaned; the next GC removes them.
- Rename and archive need nothing new: the column follows the field.

## Several files

Decided (2026-11-04): each `file` field chooses between one file and many, with a `multiple`
setting. A gallery or a list of attachments is a `file` field with `multiple: true`; when every
file needs data of its own (a caption, a kind, a date), a child object (`product_image` with a
relation, a `file` field and a `position`) remains the tool, edited by a `mode: many` section of a
composite form.

### Settings

| Setting | Default | Description |
|---|---|---|
| `multiple` | `false` | `true`: the field holds an ordered list of files. **Fixed at creation**, like the column it replaces |
| `max` | none | With `multiple`: the most files the field holds (1–100) |

`accept`, `maxSize` and `image` apply to every file of the list. `required` means at least one
file. A `file` field with `multiple` cannot be unique, the label of the records, searched,
sorted, nor the deciding field of a condition (as today).

### Storage

No column on the object table: `FieldDefinitionDto::storesColumn()` is false, as for
`many_to_many`, and the modules that skip columns already skip it. A link table built, renamed,
archived and dropped by the Schema module with the object and the field, as the `many_to_many`
ones are:

| Column | Type | Notes |
|---|---|---|
| `record_id` | uuid | FK to the object table, `ON DELETE CASCADE` (a purge only: records are soft-deleted) |
| `target_id` | uuid | FK to `core.file(id)`, `ON DELETE RESTRICT`, indexed (access checks, GC) |
| `position` | int | 0, 1, 2… in the order the user chose |

The file column is `target_id`, as in the `many_to_many` link tables, so the names of the
indexes and constraints (`TableDefinitionFactory::linkNames()`) and the code reading links can
be shared. Primary key `(record_id, target_id)`: the same file twice in one list makes no sense (the same
bytes uploaded twice are two rows of `core.file`, so two different ids anyway). The table is
named by `DataSchema::linkTable()`, so `storesColumn()` / link table code paths are shared;
whether `FieldDefinitionDto` needs a `linkKind()` (records or files) is decided in phase 5.

### Value

- Read: a list of file ids, in order (empty list, never null).
- Write: a list whose items are a `FileUpload` (new) or the id of a file **the record already
  holds** (kept, possibly moved). An id the record does not hold is refused: a request must not
  attach somebody else's file by its id. Anything left out of the list is unlinked; the file
  itself stays for the GC.
- `toStorage()` stores the uploads; the Record store replaces the rows of the record in the
  link table in one statement set, inside the record's transaction (as `replaceLinks()` does).
- Validation: `NewFile` on each upload, `max` on the count, at least one when required.

### Form

The current files as a list (thumbnail or icon, name, size, a link), each with *Remove* and
handles to reorder (up/down buttons, no drag-and-drop library); one `<input type="file"
multiple>` adding files at the end. A Stimulus controller keeps the order in hidden inputs; the
kept ids come from the form options, never from the request (as for one file). Generic parts
(the list, the reorder buttons) go to gridview-bundle with the `gv-` prefix if they prove
reusable, otherwise `md-file-list` in `app.css`.

### Grid, export, access

- The grid source selects the ids from the link table, in order (a correlated subquery, as for
  `many_to_many`), then looks the files of the page up with the one `findMany()` call it makes
  already; `<field>__file` is the list that `FileCell` draws (built for it in phase 4). The
  yes/no filter becomes `EXISTS` on the link table.
- The export writes the names joined by ", ".
- `FileReferences::objectsHolding()` also searches the link tables, so `/files/{id}` serves a
  file held only through a list.
- The GC (its phase, below) treats the link tables as references too.

## Phases

1. **Storage and `core.file`** — done: the `File` module (`FileStorageInterface`, implemented
   by `Infrastructure/Flysystem/FlysystemFileStorage`), the `record_files` storage (local disk,
   memory in the tests), the migration, `store()` / `find()` / `readStream()`, deduplication by
   hash, names reduced to their base name. Integration tests in
   `tests/Integration/Module/File/FileStorageTest.php`. A `when@test` alias in
   `config/services.yaml` keeps the Flysystem storage reachable from the tests.
2. **The type** — done. As built:
   - `FileFieldType` (`accept` is a comma-separated string, as the HTML attribute; `maxSize` is
     an integer in MB, 1 to 100; `image`). Values: a `FileUpload` DTO (FieldType Contract: path
     and original name, so the Domain never sees `UploadedFile`), the id of a stored file (must
     exist in `core.file`), or null.
   - The File/Image constraints are wrapped in `NewFile` (Domain) / `NewFileValidator`
     (Infrastructure): they apply to a `FileUpload` only, never to a stored id.
   - `ColumnSpec::$referencesCoreTable`: the Schema module adds an index and a RESTRICT foreign
     key to `core.file`, named like the relation ones.
   - `FieldTypeInterface::supportsUnique()` (and the catalog's): a file field cannot be unique.
   - `RecordFileType` (Record UI): the current file, the upload input, "Remove the file" when not
     required. The current id comes from the form options, never from the request. Theme block
     in `templates/form/record_fields.html.twig`, styles `md-file` in `app.css`.
   - Grid: ✓ when there is a file, no sort or filter, until phase 4.
   - Tests: `FileFieldTypeTest` (unit), `FileFieldsInRecordsTest` (integration),
     `FileFieldControllerTest` (functional).
   - SVG: not refused at upload, after all. Phase 3 must serve anything that is not a raster
     image as an attachment, with `nosniff`, so an SVG never runs in the app's origin.
   - A `file` field can no longer be the label of the records nor searched (phase 5:
     `InvalidMetadataDefinition::noTextValue()`, through `isSortable()`).
3. **Serving** — done. As built:
   - `FileController` (Record UI) at `/files/{id}` and `/files/{id}/thumbnail/{small|medium}`.
     Readable when `FileReferences::objectsHolding()` (Record Domain, DBAL implementation: one
     indexed query per object with file fields, live records only) names an object the user may
     read; otherwise a 404, the same as an unknown file.
   - Inline for JPEG, PNG, GIF, WebP; attachment for anything else (SVG included). Always
     `nosniff` and `Content-Security-Policy: default-src 'none'; ...; sandbox`. ETag = SHA-256,
     `private, max-age=3600` (short, so a revoked permission takes effect within the hour).
     The UTF-8 name goes in `filename*`, an ASCII fallback in `filename`.
   - Thumbnails: `imagine/imagine` (GD) directly, not `liip/imagine-bundle`: the project has
     `allow-contrib: false`, and liip's public cache routes would have had to be turned off.
     `ImagineThumbnailer` (File Infrastructure) keeps them under
     `thumbnails/<size>/<sha256>.<format>` in the same storage; 64 and 240 px, never enlarged;
     none over 40 megapixels or for an image GD cannot decode. **The GC of phase 5 must skip,
     or clean, the `thumbnails/` prefix.**
   - The record form links the current file and previews an image (medium thumbnail).
   - Tests: `FileControllerTest` (functional; `disableReboot()`, since the memory storage lives
     in the container), `ThumbnailerTest` (integration).
   - Not done: EXIF orientation of photos (a phone picture may show rotated).
4. **Grid and export** — done, merged. As built:
   - No named renderer: the cell needs the file's name and type, which a `CellRendererInterface`
     does not get (it gets the value and its text). `FileCell` (Record UI) draws the default
     cell of a file field, in the default grid and in a grid view column without a renderer: a
     link to `/files/{id}` with the small thumbnail of an image (opens in a new tab) or a
     document icon (downloaded), then the original name; `data-turbo="false"`. A renderer picked
     in a grid view gets the name as its text.
   - The grid source looks the files of the page up in one query through the File module,
     `FileStorageInterface::findMany()`, instead of joining `core.file`, which the File module
     owns. Rows carry `<field>__file` (a list, so `multiple` can reuse it) and the names under
     `<field>__label`.
   - Filter: the bundle's yes/no filter, `IS [NOT] NULL` on the column; any other operator is
     refused by the source.
   - `FieldTypeInterface::isSortable()` (and the catalog's): false for `file` and
     `many_to_many`. The default grid, the grid view compiler (no `sortable: true`, no `sort`,
     no `{token}` in a virtual column) and the source (sort, search, virtual columns) all use
     it, in place of the many-to-many-only `storesColumn()` checks.
   - The export writes the names: the cell's only text, once the tags are stripped.
   - Tests: `FileGridTest` (functional: cell, filter, CSV export, grid view rules),
     `FileStorageTest::testFindsSeveralFilesAtOnceSkippingUnknownIds`.
   - Not done: each thumbnail is a request of its own, checked like any download
     (`FileReferences::objectsHolding()`, one query per object with file fields); fine for a
     page of 25, to watch on large grids.
5. **Several files, storage** — done, merged. As built:
   - `FileFieldType`: `multiple` (boolean, fixed) and `max` (1–100, only with `multiple`);
     the widget carries both.
   - `FileListSettings` (Metadata Contract) and `FieldDefinitionDto::fileList()`;
     `storesColumn()` is false for a file list too. The Record paths that read and write
     many-to-many links now ask `manyToMany()` instead of `!storesColumn()` (`writeLinks()`,
     `linksOf()`, the grid filter), so a file list is not taken for links to records.
   - `TableDefinitionFactory::fileListTable()`: `record_id`, `target_id` (→ `core.file`,
     RESTRICT), `position`; no `created_at`/`created_by`. `objectNames()` lists it with the
     link tables, so rename, archive, purge and drop handle it with no new code.
   - Until phases 6–7: the record form skips the field (`RecordFormFactory`), a record reads
     `[]`, the grid column is empty with no filter (the source refuses one), and
     `DbalFileReferences` skips the field. Phases 6 and 7 replaced all of these.
   - Label and search refuse any `file` field (`isSortable()` false), one file or several.
   - Tests: `FileListFieldTest` (integration: DDL, settings, renames, archive and purge, empty
     reads), `FileFieldTypeTest` (settings).
6. **Several files, records and form** — merged into main. As built:
   - `FileFieldType` with `multiple`: `denormalize()` returns the items in order (a
     `FileUpload` or a lowercase id, each id once), null for an empty list, so `required` is the
     usual `NotNull`; anything else is "This value should be a list of files.". `constraints()`:
     `All([NewFile])` and `Count(max)`; `toStorage()` stores the uploads and returns the list of
     ids (`[]` for none).
   - `MetadataRecordValidator` keys a violation by the first segment of its path, so an item's
     error (`[gallery][1]`) lands on its field.
   - `RecordManager::checkHeldFiles()`, after denormalization: an id must be one the record holds
     already (read back from the store; a new record holds none), otherwise
     `FOREIGN_FILE_MESSAGE`. The type cannot check it: it does not know the record.
   - `RecordStore::replaceFiles()` rewrites the rows of the record (DELETE, then one INSERT with
     the positions) inside the record's transaction, from `writeLinks()`, which tells the two
     kinds of links apart with `manyToMany()` / `fileList()`. `linksOf()` reads the lists ordered
     by `position`, with no join on `core.file` (the RESTRICT foreign key keeps the files there).
     `RecordTable::fileListTable()` names the table; `links()` / `LinkSource` stay many-to-many
     only.
   - `DbalFileReferences` also matches `id IN (SELECT record_id FROM <list> WHERE target_id =
     :file)` for every file list (the `target_id` index serves it).
   - `RecordFileListType` (Record UI): a hidden `kept` input (ids in order, comma-separated;
     only the ids of the current files, from the options, are taken from it) and one
     `FileType` with `multiple`; its data is the kept ids then a `FileUpload` per new file.
     Theme block `record_file_list_widget`, styles `md-file-list` in `app.css` (nothing generic
     enough for gridview-bundle yet), the `file-list` Stimulus controller (`assets/controllers/
     file_list_controller.js`: up, down, remove, the hidden input; the buttons are hidden until
     it connects). Read-only forms show the list only.
   - Tests: `FileListsInRecordsTest` (integration), `FileListFieldControllerTest` (functional:
     upload of several files, reorder and removal through the hidden input, an id not on the
     page ignored, `max`), `FileControllerTest::testAFileHeldInAListIsServedWhileTheRecordHoldsIt`,
     `FileFieldTypeTest` (list values, constraints, storage).
   - Not done: reordering the new files among the current ones before saving (they go to the
     end), drag-and-drop, and the file list in a composite form section (phase 9; the control
     is added there too, untested).
7. **Several files, grid and export** — merged into main (built on the same branch as phase
   6, at the user's request). As built:
   - `DbalRecordGridSource` selects, per file list, a correlated subquery
     `STRING_AGG(target_id, ',' ORDER BY position)` under `<field>__ids` (as for the
     many-to-many ids, no join on `core.file`), hydrated as the record's list; `withFiles()`
     takes a list as well as one id, so `<field>__file` holds every file in order and
     `<field>__label` their names joined by ", ", from the one `findMany()` of the page.
   - Filter: the same yes/no column filter, `[NOT] EXISTS (SELECT 1 FROM <list> WHERE
     record_id = t.id)` (the `record_id` index serves it).
   - `FileCell` separates the links with `<span class="md-file-cell__separator">, </span>`:
     the export strips the tags, so it writes "care.pdf, colours.png".
   - Grid views needed nothing: the compiler's rules go by `isSortable()` of the type.
   - Tests: `FileGridTest` (a list in its order, its filter, its export, `sortable: true`
     refused).
8. **GC** — done, on `feature/file-gc`. As built:
   - `metadata:files:gc [--dry-run] [--older-than=24h]` (File UI), over
     `FileGarbageCollectorInterface` (File Application) and `DbalFileGarbageCollector` (File
     Infrastructure). The references are the foreign keys to `core.file` found in the catalog
     (`pg_constraint`): file columns and file list tables, archived fields and objects included
     (archiving keeps both), records in the bin included (their rows are there). The File
     module needs no other module, and a future table with such a key counts with no change.
   - Rows: `DELETE ... WHERE created_at < :before AND NOT EXISTS (...)` per foreign key, the
     condition repeated in the DELETE (and the RESTRICT keys refuse a referenced row anyway).
   - Bytes: the storage is listed; a content key (`<sha256[0:2]>/<sha256>`) no row uses is
     deleted, the stray bytes of a rolled-back write included; a thumbnail
     (`thumbnails/<size>/<sha256>.<format>`) goes with its content. Any other key is left
     alone. No grace period on bytes: the lock below covers the uploads in progress.
   - `ContentLock`: a transaction-scoped advisory lock on the SHA-256. `store()` writes the
     bytes and the row under it (`FileRepository::underContentLock()`), held until the record's
     transaction commits; the GC takes it before checking that no row uses a content and deleting
     its bytes. Without it, a store() finding the bytes already there (deduplication) could add
     a row the GC cannot see yet, and lose its bytes.
   - Sizes in decimal units (1 MB = 1,000,000 bytes), as `maxSize`.
   - Tests: `FileGarbageCollectorTest` (integration: referenced, in the bin, unlinked from a list,
     shared bytes, archived then purged fields, grace period, stray bytes, thumbnails, foreign
     keys of the storage, the lock, dry run, the command).
   - Not done: the listing of the storage is held in memory (fine for tens of thousands of
     files; to page through for millions), and nothing schedules the command: cron or a timer,
     see `console.md`.
9. **Composite forms**: a file field in a section and in a `mode: many` row (the upload inputs
   are nested names).

Docs in the same branches: `field-types.md` (the eleventh type), `views.md` (the renderer),
`console.md` (the GC), `architecture.md` (the `File` module and Deptrac), `README.md` (the count
of the field types), `status.md`.

## Not in scope

- A `richtext` type with images inside the text (`{file:…}` references).
- Virus scanning (a hook in `store()` can host it later).
- Image editing (crop, rotate) in the form.
