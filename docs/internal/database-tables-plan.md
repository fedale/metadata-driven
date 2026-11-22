# Tables loaded into the database: a key type per object, then adoption (plan)

Proposed on 2026-11-22. The approach (alternative 2 below) and the details were agreed with the
user on 2026-11-22 (see "Decisions" at the end).

The user saw it in Directus: tables loaded straight into the database (a dump, `psql`, an ETL)
show up in the data model greyed out, as "database only"; clicking one turns it into a managed
collection. The goal is the same here: load tables into the database, see them in the admin, and
turn each one (or a set of related ones) into an object whose metadata is built from the table.

The last phase is about the other direction, exporting a set of objects with their records,
which packages already do from the console.

## How Directus does it

What its documentation confirms (directus.com/docs: data-model/collections, configuration/cache,
api/schema), plus what is known of its code (`api/src/utils/get-schema.ts`, `@directus/schema`),
not checked line by line:

- A collection **is** the table. Directus keeps its own settings apart, in `directus_collections`,
  `directus_fields` and `directus_relations`, and reads everything else from the database catalog
  (`information_schema`, `pg_catalog`): columns, types, the primary key's name and type, foreign
  keys. The result, the *schema overview*, is cached (`CACHE_SCHEMA`, on by default): this is why
  the new tables only showed up after a restart or a cache clear.
- Every query is built at request time from that overview (Knex): `SELECT ... FROM <table> WHERE
  <primary key> = ?`. Item ids are typed after the key: auto-increment integer, UUID, string.
- Nothing is required of a table but a single-column primary key. Soft delete (`archive_field`),
  manual sort (`sort_field`), creation and update dates and users (`special` flags on fields) are
  options, on only when configured on a column that exists.
- A table without a `directus_collections` row is the grey, "database only" entry; clicking it
  creates that row with defaults and does not change the table. Columns without a
  `directus_fields` row work, with an interface guessed from the type; foreign keys are read as
  relations.

## Why this app is different

An object's table is `<data schema>.c_<object>` (`ObjectDefinition::TABLE_PREFIX`), and it always
has the system columns (`ObjectDefinition::SYSTEM_COLUMNS`, built by `TableDefinitionFactory`):
`id` (UUID, the primary key, made by the app with `Uuid::v7()` in `RecordManager::create()`),
`created_at`, `updated_at` (NOT NULL), `created_by`, `deleted_at` (soft delete), `sort` (NOT NULL
DEFAULT 0). Field columns are named after the field (a machine name, `MachineName::PATTERN`, 2 to
50 characters), always nullable ("required" is validation), and a relation is a UUID column with a
foreign key to the target's `id`. Record, grid, search, links, files, workflows, packages: all of
them rely on this, and the record routes and lookups refuse an id that is not a UUID.

## Alternatives considered (decided 2026-11-22)

1. **Adoption with conversion, the app unchanged**: the table renamed, a new UUID `id` added, the
   old key kept as a `legacy_id` field, every foreign key between loaded tables rewritten to
   UUIDs. No change to the core, but the table's ids stop being its key, and rewriting the
   foreign keys of a set of tables is the most fragile part.
2. **A key type per object, then adoption** — **chosen**. An object's key is a UUID (the default),
   an integer or a string, fixed when the object is made. An adopted table keeps its own key: no
   `legacy_id`, no rewritten foreign keys; adoption adds the missing system columns and renames
   the table. More work in the core (records, relations, links, workflows, the package format),
   but useful beyond adoption too (a readable number, a code as the key).
3. **Key and system features optional, the table used as it is**, as Directus does. The only one
   that suits a table another program keeps writing, but by far the largest: soft delete is
   assumed everywhere (archive and restore, the delete rules the app applies, the partial unique
   indexes, the grids, the workflows), and every feature would need two behaviours. Not now; the
   loaded tables are meant to be managed by the app from then on.

## Phases

One branch per phase, as usual. Each phase ships with its docs (the topic pages, admin.md,
console.md, field-types.md, packages.md where they change; a new guide page,
`docs/database-tables.md`, from phase 1) and its tests.

### Phase 0: a key type per object

**0a. The model and integer keys** — done on branch `object_key_type` (2026-11-22), with 0b.
As delivered: `KeyType` in the Metadata contract (`normalize()`, `canonical()` for an id of any
type, `fromDatabase()`, `ROUTE_REQUIREMENT`); `core.metadata_object.key_type` and
`core.workflow_run.record_id` as `TEXT` (migration `Version20261122065252`); the field types
`relation` and `many_to_many` take any id text, checked against the target's key by the record
module's `RecordInputDenormalizer`. Checked by running the whole suite with `integer` as the
default key: the only failures left were the tests naming UUIDs on purpose (column types,
messages, SQL written by hand). Tests: `KeyTypeTest`, `IntegerKeysTest`,
`IntegerKeysControllerTest`.

- `ObjectDefinition` gets a key type, `KeyType` (`uuid`, `integer`; `string` later, see 0c), in the metadata
  table (a migration), in `ObjectDefinitionDto`, in the cached snapshot (bump the snapshot key's
  version, see status.md, "Metadata cache"). Fixed once the object exists, as a field type is:
  changing it would rewrite every table pointing at it.
- `createObject()` takes it, default `uuid`; the admin's new-object page and
  `metadata:object:create` offer it (`--key=integer`).
- `TableDefinitionFactory`: `id` is `uuid`, or `bigint GENERATED BY DEFAULT AS IDENTITY`, or
  `varchar(n)`. The identity (or, for an adopted table, the sequence of a `serial`) is left out of
  the DBAL comparison, as the trigram and unique-together indexes are, so the synchronizer never
  tries to change it. An adopted `integer` (not `bigint`) key stays `integer`: the object keeps
  the column's exact type.
- A relation column, a many-to-many link table's `target_id`, a link table's and a file list's
  `record_id` take the type of the key they point at.
- Ids travel as strings in PHP, as today (`RecordOutput::$id`, the record events, the forms): a
  `RecordKey` (Record contract) checks an id against the object's key type in one place, where
  `Uuid::isValid()` is called today (`RecordManager`, `DbalRecordLookup`, the reference and unique
  checks, the import's `id` column, the grid's selection, the field types `relation` and
  `many_to_many`). The record routes take any id (`[^/]+`), checked by `RecordKey`: a malformed
  one is a 404, as an unknown one.
- `RecordManager::create()`: a UUID is still made by the app; an integer comes back from the
  insert (`RETURNING id`). The forms already take the id `create()` returns
  (`FormRecordManager`), so they need no change; a test proves it with an integer-key child.
- Integer ids in URLs can be guessed; reading a record is checked by permission all the same, so
  this tells nothing a user may not read. Said in the guide.

**0b. Record ids outside the record tables** — done with 0a: `core.workflow_run.record_id` was
the only one. The columns holding a record id of any object
(`core.workflow_run.record_id`, and whatever else a search finds: notifications, imports, the
workflow tester, the waiting runs) become `TEXT` (a migration), so they hold any key. A list of
them goes into architecture.md, with the rule: a new one is `TEXT`.

**0c. String keys — later, by the user's choice (2026-11-22)**, when an object needs one. Until
then `KeyType` has `uuid` and `integer` only, and phase 2 refuses a table whose primary key is a
text. As planned: the key typed by the user when the record is created (a code: `IT-PG`), a
`maxLength` and an optional pattern, refused when taken (the primary key), never editable
afterwards: a changed key would have to be rewritten in every table pointing at it. The new-record
form and the forms show it on create only; the CSV import maps a column to it.

**0d. Packages** — done on branch `package_key_type` (2026-11-22), the format on its branch
`object_key`, released as 2.4.0 on 2026-11-22 and required by the app (`^2.4`). As delivered:
`key` in the Object schema, an enum, left out meaning `uuid`; `ItemStates` reads a key left
out as `uuid`, so versions written before 2.4 compare equal; `VersionCheck` asks a major version
for a change of key, and the app refuses it even then (`package.upgrade.key_changed`). The
export writes `key` only when it is not `uuid`. The seed's key stays a field: an object's own
key is never written in a package (its records get new ids at each install). As planned: The package format gets the key type in the `Object` kind (the format library,
`../metadata-driven-package-format`: a minor version, 2.4.0, with its tests, absent meaning
`uuid`); export, install and the three-way upgrade carry it, an upgrade changing it is refused.
Seeds already refer to records by a unique field, not by id: nothing changes there.

### Phase 1: discovery (read only)

- A `DatabaseTables` service in the Schema module (behind a Schema contract interface) reads the
  workspace's data schema (`CurrentWorkspace::dataSchema()`) from the catalog and returns the
  tables that belong to nothing: not the table of an object (active or archived), not a link
  table of a field (`DataSchema::linkTable()`, file lists included). Only the workspace's own
  data schema: with a schema per workspace, a table is never visible to another workspace.
- For each table: its columns (name, type, nullable, default), primary key, unique constraints,
  foreign keys, an estimated row count (`pg_class.reltuples`, no `COUNT(*)` on a big table), and
  whether it can be adopted, with the reasons when not (see "What is refused" below).
- The admin's object list shows them after the objects, greyed, with an icon of their own, as in
  Directus; a table's page lists what was read. `admin.metadata` only.
- Console: `metadata:table:list` (and `--table=<name>` for the details).
- No restart, unlike Directus: the list is read live on the page, and the metadata cache is keyed
  by the revision the triggers bump, so an adoption is seen by every server at once.

### Phase 2: adopting one table

The admin clicks a grey table and gets a **preview** of the adoption, which they can change
before confirming:

- The object: name (from the table name, made a valid machine name), singular and plural labels,
  and its key type, read from the primary key: `uuid` → `uuid`, `smallint` / `integer` / `bigint`
  → `integer` (`varchar` / `text` / `char` → `string` once 0c is done; refused until then).
- One row per column: the field name (made valid: `CustomerName` → `customer_name`), the label,
  the proposed field type, or "leave out" (the column stays in the table, unknown to the app).
- What happens to the table, in plain words: the renames, the columns added.

The proposed type, from the column's type:

| PostgreSQL | Field type |
|---|---|
| `varchar(n)`, `char(n)` | `text`, `maxLength` n |
| `text` | `text`, long, multiline |
| `smallint`, `integer`, `bigint` | `number`, 0 decimals |
| `numeric(p,s)`, `real`, `double` | `number`, s decimals (a float: the admin picks the decimals) |
| `boolean` | `boolean` |
| `date` | `date` |
| `timestamp`, `timestamptz` | `date` with `includeTime` |
| an enum type, or a `CHECK (col IN (...))` | `select`, its options |
| a foreign key to an object's key | `relation` to that object |
| anything else (`json`, arrays, `bytea`, geometry...) | left out by default |

A text column whose name says `email`, `url`, `phone`/`tel` is proposed as that type, the values
checked first (a value that does not fit makes the proposal fall back to `text`).

Confirming runs, in one transaction, through a new `MetadataManagerInterface::adoptObject()` (the
metadata) and a new `SchemaSynchronizer::adopt()` (the table):

1. Rename the table to `c_<object>`; rename the primary key column to `id` when it has another
   name, and a column whose name was made valid to the field's name. PostgreSQL foreign keys
   follow a renamed table or column: those pointing at it keep working.
2. The system columns: added when missing (`created_at` / `updated_at` filled with `now()`,
   `sort` 0). A column already there under that name is reused when its type fits (a
   `created_at timestamp` becomes the system one, its NULLs filled), renamed `<name>_legacy` and
   mapped as a field otherwise.
3. `NOT NULL` dropped on the mapped columns, the field made required instead (a required field
   with NULLs left is allowed: the records are refused at their next save, as today when a
   required field is added). A single-column unique constraint becomes `unique`; a multi-column
   one becomes a unique-together set (rebuilt as the app's partial `uniqc_` index).
4. The metadata rows, the permissions (`record.<object>.*`, as `createObject()` does), and then
   the usual synchronization, which must find nothing left to do but the app's own indexes and
   constraint names (trigram, `idx_` / `uniq_`, `<table>_pkey`): a test checks that the plan
   after an adoption is that.

The preview is a dry run of the same transaction, rolled back (as the package install does), so
what it shows is what will happen, refusals included. Console: `metadata:table:adopt <table>
[--name=] [--dry-run]`, with the proposed mapping, or one from a YAML file (`--mapping=file.yaml`),
to be decided in the phase.

### Phase 3: adopting a set of related tables

With the key types of phase 0, a foreign key between loaded tables already has the right type:
`project_task.project_id integer → project.id integer` is a relation as it is. What is left:

- The preview takes several tables at once (the admin ticks them; the page proposes the tables a
  ticked one points at), ordered so that the targets come first.
- A foreign key to a table of the set, or to an object's key: the column becomes a `relation`
  field; the constraint is renamed the app's way (`fk_<table>_<column>`) and its `ON DELETE`
  mapped (`SET NULL` → `set_null`, `RESTRICT` / `NO ACTION` → `restrict`, `CASCADE` → `cascade`),
  an index added on the column if missing.
- A foreign key to a table outside the set and not managed: the page proposes adding it to the
  set; left out, the column stays a plain field and keeps its foreign key, unknown to the app,
  until that table is adopted (a relation can then be made of it).
- A foreign key to something other than the target's primary key (another unique column): left
  out, with its reason.
- The object's label field: the first unique text column, else the first text one.

### Phase 4: junction tables

A table whose primary key is the pair of its two foreign keys (`project_task_tag (task_id,
tag_id)`) is a many-to-many link: proposed as a `many_to_many` field on the first target, the
table renamed to the app's link table `l_<object>__<field>` and its columns to `record_id` /
`target_id`, `created_at` added. With other columns besides the two keys, it becomes a junction
object instead (`through`), adopted as in phase 3 with a key of its own added (`integer`).

### What is refused

Shown on the table's page, with the reason, and checked again at adoption:

- A table outside the workspace's data schema (only that schema is read), a view, a partitioned
  table, a foreign table.
- No primary key, or a composite one that is not a junction (phase 4), or a key of a type that is
  not a UUID or an integer (a text too, once 0c is done).
- A table name or column name that cannot be made a valid machine name of at most 50 characters
  without colliding with another (the admin then picks a name in the preview).
- A column used by a trigger, a rule or a view of the user: adoption renames things, and would
  break them. Listed, refused until they are dropped (by hand).

### Reverse: archiving and purging

An adopted object is an object like any other: rename, archive, restore and purge work unchanged.
There is no "un-adopt" back to the original shape; the dry run, the preview and a database backup
before adopting (the guide will say so) are the safety net. The changes are small, though (a
rename, columns added, `NOT NULL` dropped): the table's data and keys are left as they were.

### Phase 5: exporting objects with their records, from the admin

**What already exists.** A package holds objects, their fields, views, forms, translations,
workflows and **seeds**, the records of an object (see packages.md):

```
symfony console metadata:package:export acme/projects 1.0.0 packages/projects \
    --object=project --object=project_task --seed=project --seed=project_task
```

writes the package in a directory (seeds in CSV, relations written by key), and
`metadata:package:install packages/projects` installs it elsewhere, all or nothing, with a dry
run. From the admin, today, a package can only be **installed from an index** (Admin >
Packages): exporting, and installing from a directory, are console commands only.

**What is missing:**

- **Export from the admin**: a page under Admin > Packages to pick the objects (the page adds the
  ones they point at, as the export already requires), the forms, the workflows, and the objects
  whose records go as seeds; it downloads the packed package (`.zip`, the format the pack command
  writes).
- **Install from a file**: upload that `.zip` on the install page, the same trial install first,
  then the real one. `admin.metadata`, a size limit, the archive checked as
  `metadata:package:validate` does before anything is written.
- A seed needs a key, a unique field of the object. With phase 0, an object whose key is a
  string or an integer could use its key itself as the seed's key: to be decided with 0d.
- Seeds are written record by record through the record manager (validation, events): fine for
  reference data and a few thousand records, slow for large tables. Installing a large seed in the
  background, as the CSV import does above `app.import.max_rows`, is a follow-up if it is needed.

A plain dump of the tables (`pg_dump --schema=data`) remains the tool to move a whole workspace's
data; the package is for a model with its data, to install next to other things.

## Decisions (2026-11-22)

1. **Where the tables are loaded**: only into the workspace's data schema (`data` for the first
   workspace; `pg_restore --schema=data`, or `SET search_path TO data` before a `psql` script).
   `public` is not read.
2. **Tables without a primary key**: refused.
3. **String keys** (0c): later, when an object needs one.
4. **The table name**: renamed to `c_<object>` on adoption.
5. **Order**: phase 0 (0a, 0b, 0d), then 1–3, then 4 and 5 in either order.
