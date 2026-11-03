# Plan: the `file` field type

Status: **phases 1 and 2 done** (branch `feature/file-field-type`), awaiting review before
phase 3.
Discussed with the user on 2026-11-03.

A field type that stores a file (an image, a PDF, any document) on a record: a product's photo,
its manual, a contract's signed copy. It is a prerequisite of the blueprint seeds that ship
files (see [blueprints-plan.md](blueprints-plan.md#seeds-with-files)).

## Open decisions

- **Production storage**: local disk or S3 (or compatible)? Flysystem lets us postpone it, but it
  changes what we test.
- **Several files on one record**: a child object (`product_image` with a relation, a `file`
  field and a `position`), or a `file` field with `multiple: true`? Recommended: the child
  object, see [Several files](#several-files).

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
  (`--older-than=24h`) so an upload in progress is never collected.
- Purging an object or a field leaves its files orphaned; the next GC removes them.
- Rename and archive need nothing new: the column follows the field.

## Several files

A gallery, attachments: a child object, e.g. `product_image` with `product` (relation, cascade),
`image` (file, `image: true`) and `position` (number). It fits what exists already: a composite
form with a `mode: many` section edits it, and the grid of the child lists it. A `multiple: true`
setting would need a join table or a JSONB array, and its own form widget; to be decided after
the first version, if the child object proves heavy in practice.

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
   - Open for later: a `file` field can still be chosen as the label of the records
     (`metadata:object:label`), which would show an id.
3. **Serving**: `FileController`, permissions, headers, thumbnails.
4. **Grid and export**: `FileRenderer`, export as file name.
5. **GC**: `metadata:files:gc`, with tests on orphans and on archived fields.
6. **Composite forms**: a file field in a section and in a `mode: many` row (the upload inputs
   are nested names).

Docs in the same branches: `field-types.md` (the eleventh type), `views.md` (the renderer),
`console.md` (the GC), `architecture.md` (the `File` module and Deptrac), `README.md` (the count
of the field types), `status.md`.

## Not in scope

- A `richtext` type with images inside the text (`{file:…}` references).
- Virus scanning (a hook in `store()` can host it later).
- Image editing (crop, rotate) in the form.
