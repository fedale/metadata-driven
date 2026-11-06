# Plan: templates (packaged, versioned models)

Status: **phases 1 to 4 done, phase 5 step 1 done** (format and export on
`feature/template-format`, install on `feature/template-install`, seeds on
`feature/template-seeds`, upgrade on `feature/template-upgrade`, indexes on
`feature/template-indexes`, each branched from the one before), awaiting review before phase 5
step 2 (signatures). Discussed with the user on 2026-11-03; name, upgrade model and distribution
decided on 2026-11-05 (see [Decisions](#decisions)).

A template packages a model, meaning its objects, fields, relations, composite forms, views,
translations and seed data, so it can be:

- installed in one step, on any machine;
- published to a central catalog, and downloaded from metadata-driven;
- upgraded from one version to the next: installing 2.0 over 1.0 applies only the delta;
- exported from an existing model, in the same format.

It solves a known gap first: the "registration" model exists only in the dev database of the
original machine (see [status.md](status.md#setting-up-on-a-new-machine)). It also covers the
"YAML export/import of metadata" item of the roadmap.

## Decisions

- **Name: "template".** In code: the `Template` module, the `metadata:template:*` commands, the
  `core.template_*` tables, the `Template` kind of the manifest. "Template" already names two
  other things, which keep their names: the `template` column of the views (`core.metadata_view`)
  and the Twig `templates/` directory. Docs and code say "view template" or "Twig template"
  whenever one of those is meant next to this one.
- **Local changes: three-way upgrade.** The user may change anything a template installed; an
  upgrade compares the installed version, the new one and the current state, applies what only
  the new version changed, keeps what only the user changed, and reports a conflict where both
  did. See [Upgrading](#upgrading).
- **Distribution: a central catalog, as an application of its own**, plus any number of other
  indexes (a company's private one, a vendor's own). See [The catalog](#the-catalog).
- **The format lives in metadata-driven first, behind a hard boundary** (2026-11-05). The
  format (schemas, reader, structural checks, archives, indexes) is written in
  `src/TemplateFormat/` (`App\TemplateFormat`), apart from the modules, and extracted into a
  package of its own, `fedale/template-format`, when the catalog starts (after phase 5). What
  keeps the extraction a mechanical half day:
  - a Deptrac ruleset lets `App\TemplateFormat` depend on itself and a short list of external
    libraries only (YAML, JSON Schema, archives): no `App\Module\*`, no `App\Shared`, no Doctrine,
    no Symfony services. `make deptrac` fails otherwise;
  - its tests are plain unit tests (`tests/Unit/TemplateFormat/`), with no kernel and no
    database;
  - the `Template` module (`src/Module/Template/`) is the only bridge: it maps the format's
    documents to the metadata commands, and back for the export.

  The extraction: `git filter-repo --subdirectory-filter` for the history, a new namespace, a
  `composer.json`, a path repository as for the other bundles.
- **Catalog sign-in: GitHub** (2026-11-05). A vendor namespace (`acme/`) is tied to a GitHub
  user or organization.
- **`requires` names the format version** (2026-11-05), since the app has no version number;
  the app version joins it when the app gets one.
- **Field settings without the app: a static description** (phase 1). The format carries
  `Resources/field-types.v1.json`: every field type, every setting with its kind, range, choices
  and default, and which settings name an object or a field (and of which object). The catalog
  can then refuse a broken template on publish. `FieldTypeCatalogMatchesTheAppTest` fails when
  the app gains a type or a setting the description lacks.

## Open decisions

- **The catalog**: its domain and hosting, and whether a template is reviewed before it is listed
  or only reported and removed after. To decide when the catalog starts. See
  [The catalog](#the-catalog).

## Principles

- **Data, never code.** A template holds no PHP, no scripts, no SQL. Every item goes through
  the same compilers, validators and sanitizers as the admin. This is what makes downloading
  one from a stranger acceptable.
- **One canonical format for every item.** Export produces it, install reads it. The round trip
  `export(install(t)) == t` is the test that keeps the format complete.
- **Stable identities.** Every item carries an `id` that never changes, so an upgrade can tell a
  rename from a removal followed by an addition, and a rename never drops a column.
- **Nothing is lost by an upgrade.** Removals archive, they don't purge (the current lifecycle
  rules). Purging stays an explicit CLI action.
- **All or nothing.** A whole install or upgrade is checked first, then applied in one
  transaction. `--dry-run` stops after the check and prints the plan.
- **A published version never changes.** Its archive and checksum are fixed once listed; a bad
  version is yanked (hidden from new installs) and fixed by a new one.

## The format

Every document shares one envelope:

```yaml
format: metadata-driven/v1        # version of the format, not of the template
kind: Object                      # Template | Object | Form | View | Translations | Seed
id: crm.account                   # stable identity, unique within the template
spec: { ... }                     # the item, by kind
```

- `format` versions the representation. A new format version comes with a reader for the old
  one, so old templates keep installing.
- A JSON Schema per `kind` is published with the format (`schema/v1/`). It validates files
  before any database work, and gives editors autocompletion.
- References between items use ids (`target: crm.region`, `object: crm.account`), not machine
  names. Ids are mapped to local items at install time.

### Kinds

| Kind | Spec | Built on |
|---|---|---|
| `Template` | The manifest: `name` (`vendor/name`), `version` (semver), `label`, `description`, `license` (SPDX id), `requires` (format version, other templates), `resources` (globs), `migrations` | — |
| `Object` | Name, labels, plural, search configuration, and its `fields`: each with `id`, name, type, settings, required, unique, condition, relation or many-to-many settings | `ObjectDefinitionDto`, `FieldDefinitionDto`, `RelationSettings`, `ManyToManySettings`, `FieldConditionDto` |
| `Form` | The composite form YAML as it is today, with `object` and `via` as ids | `FormDefinitionCompiler` |
| `View` | `kind: form` (the HTML layout) or `kind: grid` (the YAML), `object`, `name`, `default` | `SaveViewHandler`, `GridViewCompiler` |
| `Translations` | One locale, keyed by `TranslationKey` | `TranslateHandler` |
| `Seed` | Records of one object, inline or from a CSV file | See [Seeds](#seeds) |

### A package on disk

```
acme-registration-1.0.0/
  template.yaml
  README.md                       # shown on the catalog page
  objects/account.yaml
  objects/profile.yaml
  forms/registration.yaml
  views/account.form.main.yaml    # every document is YAML: a layout is a literal block
  views/account.grid.main.yaml
  translations/en.yaml            # the {t:name} texts, in the default language too
  translations/it.yaml
  seeds/qualifications.csv
  files/...                       # files referenced by seeds
```

Distributed as a `.tar.gz` archive with a `sha256` checksum.

## Installing

`metadata:template:install <path|archive|name> [--version=] [--dry-run]`, and later from
**Admin → Templates**. Requires `admin.metadata`.

1. **Read and check the documents**: JSON Schema, unknown keys, duplicate ids, dangling
   references, `requires` satisfied (other templates installed in a compatible version).
2. **Check collisions**: an object or form with the same machine name that the template does not
   own is refused.
3. **Plan**, ordered by dependency:
   1. the objects;
   2. their fields that point nowhere;
   3. the relation and many-to-many fields, topologically;
   4. conditions, `dependsOn`, search;
   5. forms, views and translations;
   6. seeds.
4. **Apply** the plan through `MetadataManagerInterface`, in one transaction. PostgreSQL DDL is
   transactional. **To check**: the schema sync (`Schema/Application/Listener`) must run inside
   the same transaction, otherwise a failure halfway leaves tables behind.
5. **Record** the installation (below).

The compilers check forms and views against the objects. Because steps 3.1 to 3.5 run in the same
transaction, a form can be compiled against objects that the same template has just created.
Errors are collected with the path of their document (`forms/registration.yaml: profile.phones:
...`).

### Tracking what is installed

Two static tables in `core`, through a migration:

| Table | Columns |
|---|---|
| `core.template_installation` | `name`, `version`, `source` (the index it came from, or a local path), `snapshot` (JSONB: the documents as installed, the base of the next upgrade), `installed_at`, `upgraded_at` |
| `core.template_item` | `template`, `item_id` (the template's id), `kind`, `local_id` (the metadata row it maps to) |

An item in `core.template_item` is **owned** by its template. Anything else is local: a field a
user added to an installed object belongs to nobody, and no upgrade touches it.

## Upgrading

`metadata:template:upgrade <name> [--to=] [--dry-run] [--strategy=keep|overwrite]`

The delta is computed from three states, not from a list of migration scripts:

| State | Source |
|---|---|
| **base** | The installed version, from `snapshot` |
| **theirs** | The new version |
| **mine** | The current local metadata of the owned items |

- `base → theirs` gives the changes the new version brings. They are applied to `mine`.
- An item changed only locally (`base ≠ mine`, `base = theirs`) keeps the local version.
- An item changed in `theirs` and **also** changed locally is a **conflict**. Conflicts are per
  item (an object's labels, one field, one form, one view, one translation key), never per line:
  a layout is either the local one or the template's. The plan lists them; `--strategy=keep`
  keeps the local version, `overwrite` takes the template's, and without a strategy the upgrade
  stops. The admin shows both versions side by side and lets the user pick, item by item.
- After an upgrade, `snapshot` becomes `theirs`: the next upgrade compares against it, so a
  conflict resolved once is not asked again.
- **Renames**: same id, different name. Applied with the existing rename handlers, so the
  table, the columns and every reference follow.
- **Removals**: an id in `base` and not in `theirs`. The item is archived (refused, as today,
  while it is still used).
- **Field type changes**: the type of a field cannot be changed today. The plan refuses them
  unless the template declares an explicit step.
- Upgrades can **skip versions** (1.0 → 3.0 directly), because the delta is between states.
- Downgrades are refused.

### Explicit steps

For what a diff cannot infer, such as moving the values of one field into another, splitting a
field or changing a type, the manifest may declare steps per version:

```yaml
migrations:
  '2.0.0':
    - { op: copy_values, object: crm.account, from: crm.account.phone, to: crm.profile.phone }
    - { op: archive_field, field: crm.account.phone }
```

The operations are a **closed list** implemented by the app, never code from the template. They
run, in version order, for every version between `base` and `theirs`.

### Semver

- **Major**: may archive, rename and carry explicit steps. The plan highlights them.
- **Minor**: additions only (objects, fields, forms, views, seed rows).
- **Patch**: labels, translations, layouts, settings that don't narrow existing data.

Install checks the declared bump against the computed plan. A minor version that removes a field
is refused as a malformed template. The catalog runs the same check on publish (see below).

## Seeds

```yaml
format: metadata-driven/v1
kind: Seed
id: geo.municipalities
spec:
  object: geo.municipality
  key: istat_code                       # natural key: rows are matched on it
  mode: managed                         # managed | once
  source: seeds/municipalities.csv      # or `records:` inline
  relations:
    province: { by: code }              # references by the target's key, never by id
```

- **`once`**: loaded at install only. Afterwards the records belong to the user: sample data,
  initial states.
- **`managed`**: reference data kept in line by upgrades, matched on `key`. New keys are inserted,
  changed rows updated, missing keys soft-deleted (or refused if a `restrict` relation still
  points at them).
- `key` must name a unique field. Relations (and many-to-many links, as a list of keys) are
  resolved by the key of the target's seed, in dependency order.
- Every row is validated with the rules of its object (required, unique, types, conditions),
  in two passes (check everything, then write), all or nothing.
- The engine is the **CSV import service** of the roadmap (plan A in `status.md`), so the two
  are built together. The `record:import` CLI is a by-product.

### Seeds with files

Built on the `file` field type ([file-type-plan.md](file-type-plan.md), complete).

```yaml
spec:
  object: shop.product
  key: sku
  mode: managed
  source: seeds/products.csv            # sku,name,price,image,manual
  files: [image, manual]                # these columns hold paths inside the package
```

```csv
sku,name,price,image,manual
OSL-01,Oslo chair,129.00,files/products/chair-oslo.jpg,files/manuals/chair-oslo.pdf
```

- By default files are inside the package. The archive is self-contained and its checksum covers
  everything.
- Large files may be remote: `{ url: https://…, sha256: … }`, with `https` only, a size limit,
  streamed download, and a refusal when the checksum differs. The `sha256` is mandatory.
- Files are checked like an upload: MIME type sniffed from the bytes, `accept`, `maxSize`. A
  `.jpg` that is something else is refused. A file becomes a `FileUpload` and goes through the
  record pipeline like any other.
- Order, because the storage is not transactional:
  1. check every row and every file without writing (`--dry-run` stops here);
  2. store the files;
  3. write the records in one transaction.

  A failure at step 3 leaves orphaned files for `metadata:files:gc`. A record never points at a
  missing file.
- Upgrades compare hashes: an unchanged file is not stored again, and a replaced one becomes an
  orphan.
- Export writes the files into `files/` of the archive.

## Indexes

An **index** is a JSON file listing templates, versions, archive URLs and checksums, in the
manner of Helm repositories. The catalog serves one; anybody else may host one (a company's
private templates, a vendor's own) on any web server.

```json
{
  "format": "metadata-driven/index/v1",
  "templates": {
    "acme/registration": {
      "1.0.0": {
        "url": "https://…/acme-registration-1.0.0.tar.gz",
        "sha256": "…",
        "signature": "…",
        "requires": { "format": "metadata-driven/v1", "acme/geo-italy": "^1.0" },
        "yanked": false
      }
    }
  }
}
```

- metadata-driven comes with the catalog configured (`TEMPLATE_CATALOG_URL`), and
  `metadata:template:repo add|remove|list` manages the other indexes (stored in `core`).
  `metadata:template:search` and **Admin → Templates** show every configured index, the
  installed versions and the available upgrades.
- The same name in two indexes is an error unless the user picks the index; an installed
  template remembers its `source` and upgrades from it only.
- Downloads use `symfony/http-client`. They are `https` only, have a size limit (in MB,
  configurable) and check the checksum before anything is unpacked. Unpacking refuses absolute
  paths, `..` and symlinks.
- **Signatures**: the catalog signs every archive it lists (Ed25519, `sodium`); metadata-driven
  ships the catalog's public key and refuses a catalog archive whose signature does not match.
  Another index may sign with its own key, added with `repo add --key=`; an unsigned index is
  accepted only after the user confirms it.

## The catalog

A separate Symfony application, in a repository of its own (`fedale/template-catalog`), built
on the same stack and the user's bundles where they fit (`fedale/gridview-bundle` for its lists,
`fedale/rbac-bundle` for its roles). It depends on the format library (`fedale/template-format`,
extracted from metadata-driven first), never on metadata-driven.

**For the people who install:**

- Search and browse: by name, label, tags, objects inside, author; sorted by downloads or by
  date.
- A page per template: its README, its objects and fields (read from the documents, so the
  user sees what the template will create before installing), its versions with their changes,
  what it requires, its license, its downloads.
- The index (`/index.json`) that metadata-driven reads, and a JSON search API for **Admin →
  Templates**.

**For the people who publish:**

- An account, and a **vendor namespace** (`acme/`) owned by one account or organization: only
  its members publish under it.
- Publishing: upload an archive from the site, or `metadata:template:publish` from metadata-driven
  with an API token. The catalog then:
  1. checks the archive with the format library: schemas, ids, references, the safety rules of
     the archive, the semver bump against the previous version;
  2. refuses a version that already exists (versions never change);
  3. stores the archive (Flysystem, S3 in production), computes its checksum and signs it;
  4. lists it in the index.
- Yank a version: hidden from new installs and upgrades, still downloadable by its exact
  version for those who have it.

**Trust:**

- The checks of the format library are structural: they cannot compile a form against the
  objects, as metadata-driven does at install. A later step can run a real dry-run install in a
  throw-away metadata-driven instance and show the result on the page.
- A "verified" badge for vendors whose identity was checked; reports from users; removal of a
  template, which yanks every version.

**Not in the catalog**: paid templates, private templates (a private index covers them),
comments and ratings (later, if wanted).

The catalog gets a plan of its own (`template-catalog/docs/internal/plan.md`) when its phases
start; this section is its brief.

## Exporting

`metadata:template:export <name> --version= --object=… [--form=…] [--with-seed=object:key]`

- Writes the canonical format for the chosen items, assigning ids to items that have none
  (`<vendor>.<object>`, `<vendor>.<object>.<field>`) and recording them, so a later export of the
  same model keeps the same ids.
- Relations to objects outside the export are refused, unless the target belongs to an installed
  template, which then becomes a `requires`.
- Exporting the items of an installed template, with no local changes, must reproduce it.

## Phases

In metadata-driven (the format in `src/TemplateFormat/` until the catalog starts):

1. **Format and export** — done. As built:
   - `App\TemplateFormat` (`src/TemplateFormat/`): `DocumentCodec` (envelope, YAML both ways,
     layouts as literal blocks), the JSON Schemas of Template, Object, Form, View and
     Translations (`Resources/schema/v1/`, checked with `opis/json-schema`), `FieldTypeCatalog`
     (settings: check, defaults, references), `PackageChecker` (schemas, settings, unique ids
     and names, every reference resolved inside the package), `PackageDirectory` (read and
     write; resources stay inside the package). Its boundary: `deptrac.template-format.yaml`,
     in `make deptrac`. Tests: `tests/Unit/TemplateFormat/`, with an example package in
     `Fixtures/shop/`.
   - Ids are unique **per kind** (an object and a form may share one); field ids are unique
     across the package, so that a reference names a field by its id alone. A setting naming a
     field holds its id too (`dependsOn`, `dependsVia`, `throughSource`, `throughTarget`).
   - Settings, `required` and `unique` are written only when they differ from their default.
   - Views and forms keep naming fields by name inside their templates and definitions; only
     `object` and `via` become ids. A rename in phase 4 rewrites them, as the app does today.
   - The `Template` module: `TemplateExporter`, `core.template_item` (the id of each exported
     object, field, view and form, kept across exports; phase 2 records installed items there
     too), `metadata:template:export <name> <release> <directory>` (`release`, since
     `--version` is the console's own) and `metadata:template:validate`. Tests:
     `tests/Integration/Module/Template/`.
   - References outside the package (to another template, through `requires`) are refused for
     now: they come with phase 2.
2. **Install** — done. As built:
   - `TemplateInstaller` (Template Application) through `MetadataManagerInterface`, in one
     `TransactionRunner` transaction; the metadata handlers join it, and the schema sync runs
     inside it (checked: `SynchronizeSchemaOnDefinitionChange`). Order: objects; fields, those
     naming another field (cascades, junctions) after it; conditions, label, search, unique
     sets; `text.*` translations (default language first: a layout may only place a defined
     text); views; forms (definition dumped back to YAML, ids turned into names); the other
     translations. A refused step: `InstallRefused::at(path, at, message)`, all rolled back.
   - `--dry-run` runs everything, then throws `DryRunCompleted` inside the transaction to roll
     it back. Safe for the caches: the metadata registry never caches inside a transaction,
     and **fedale/rbac-bundle was fixed for it** (branch `fix_transaction_cache` there): its
     item graph and rules were cached even when read mid-transaction, so a rollback left
     permissions that a later install then skipped. `TransactionCacheWriteGuard` now keeps
     them out of the cache while a transaction is open.
   - Refused before anything is written: an invalid package, a template already installed,
     `requires` naming another template (not supported yet), a local object (archived ones
     too) or form with one of its names, a translation key naming no object or form of the
     template, a `text.*` key this instance already has (found by a security review: without
     it a template could rewrite local labels and texts).
   - Languages the instance does not have are skipped with a warning, not refused.
   - `core.template_installation` (name, version, source, snapshot of the documents as
     installed, dates) and the installed items in `core.template_item`. Exporting an installed
     template under its name gives it back, ids included (tested).
   - Commands: `metadata:template:install <directory> [--dry-run]`, `metadata:template:list`.
   - Tests: `TemplateInstallerTest` (round trip, dry run then real install, a refused view
     rolling everything back, collisions, languages). The example package now has
     `translations/en.yaml` (its `{t:identity}` text in the default language).
   - Not done: references to another installed template (`requires`), moved to after phase 4;
     an admin page (phase 6).
3. **Seeds** — done (branch `feature/template-seeds`). As built, step 1:
   - Format: `seed.json` (`object` id, `key` a unique field *name*, `mode`, `records` inline or
     `source` CSV in `seeds/`); fields by name, as views and forms do. One seed per object.
     `SeedRecords` reads them (CSV: header of names, RFC 4180, BOM stripped, empty cell = no
     value; `|` separates the values of a many-to-many field or a list of files).
     `Package::$directory` and `Package::file()` resolve CSV files and files inside the package
     only (no `..`, absolute paths or symlinks). `PackageChecker` checks key, records, keys,
     columns, references (a relation or link holds keys of the target's seed) and files.
     Exactly one of `records`/`source` is checked in the checker, for a readable message.
   - The `relations: {province: {by: code}}` of this plan's first draft is not needed: a
     reference goes by the key of the target's seed.
   - Install: `SeedWriter`, after everything else, seeds ordered by what they point at (a
     cycle between two seeds is refused), self-references set in a second pass. Through
     `RecordManagerInterface` (validation, files as `FileUpload`). The snapshot keeps each
     seed's records as read. A dry run stores the files before rolling back: the GC removes
     them.
   - `once`/`managed` only matter to upgrades (phase 4).
   - Step 2 — done. Export: `--seed=object[:key]` (`SeedExporter`): one CSV per object, every
     record via `RecordFinderInterface::each()` (keyset paging), relations and links written as
     keys (the target needs its own `--seed`), files copied to
     `files/<object>/<file id>/<name>`, passwords left out, an empty key refused.
     `Package::$files` holds data files not on disk yet (`PackageFile`: `StringFile`,
     `StoredPackageFile` streaming from the storage); `PackageDirectory::write()` writes them;
     `Package::has()`/`open()` read them on disk or in memory. Exported seeds have no `mode`
     (so `once`).
   - `record:import <object> <file> [--key] [--match=field:targetField] [--dry-run]`
     (`RecordImporter`, Record module): upsert by a unique key, relations by id or by a unique
     field of the target (`RecordStore::idsByValues()`), files relative to the CSV, all or
     nothing with every refused row listed. CSV reading in `App\Shared\Csv\CsvFile` (the
     format keeps its own copy in `SeedRecords`, to stay free of the app). Not shared with
     `SeedWriter`: seeds reference by the keys of other seeds, an import by ids or by values
     already in the database.
4. **Upgrade** — done (branch `feature/template-upgrade`). As built, step 1:
   - `ItemStates` turns a package into items compared by value: `object:<id>` (its own
     properties), `field:<id>`, `view:<id>`, `form:<id>`, `translation:<locale>:<key>` (its key
     naming items by id, `PackageIds`, so a rename keeps it the same item). Defaults filled in
     through the catalog, unique sets sorted, layouts right-trimmed; compared with `===`.
   - The three states: `Snapshot::toPackage()` of the installation (base), the new package
     (theirs), `TemplateExporter::ownedState()` (mine: the owned items read back, ids from
     `core.template_item`, fields the user added left out, a reference to a local item written
     `local:<name>`). **The install now records `ownedState()` as its snapshot**, not the
     package's text: a layout the app sanitized would otherwise look changed by the user.
   - `UpgradePlan::compute()`: changed only in theirs, applied; only in mine, kept; in both,
     differently, a conflict; added; removed (a conflict if mine changed it). `--strategy`
     keep/overwrite.
   - `TemplateUpgrader` applies, in one transaction: renames (objects, then fields), new objects
     and fields (fields naming new fields after them), field and object changes, texts, views
     and forms (another name, kind or object = delete and save), translations, then removals
     (views and forms deleted, fields and objects archived). `CurrentNames` follows the names
     as they change. Then the snapshot and version are updated.
   - Refused up front: not installed, not newer (`version_compare`), conflicts without a
     strategy, collisions, and what the API cannot change on an existing item: a field's type,
     required, unique, object, an object's icon. Those need API work first (a type change needs
     a data conversion).
   - **The ORM keeps entities changed by a rolled-back transaction**: read again, a relabelled
     object kept its new label after a dry run. `PersistenceReset` (Doctrine: `clear()`) runs
     after every rollback of an install or upgrade. It detaches every entity: the admin UI
     (phase 6) must not flush an entity loaded before it.
   - Step 2: `VersionCheck` compares the installed version with the new one (not the
     instance): a removal, a rename or migration steps need a major; additions and structural
     changes a minor; a patch only changes labels (object, field), views, forms, translations.
     The plan's "settings that don't narrow existing data" became "a minor": telling widening
     from narrowing settings needs a rule per setting.
   - `migrations`: `copy_values` only (same object, no files), schema-checked, its `to` field
     checked by `PackageChecker`, its `from` by the upgrade (it may be a field this version
     removes). Run after the field changes, before removals, through `RecordManager::update()`.
   - Seeds: `SeedFingerprints` (sha256 of a record's values in the template's fields, dates as
     ATOM, lists as sets) recorded per key in the snapshot at install and after each upgrade;
     `SeedPlanner` decides per key (create, update, delete, conflict), before the transaction;
     `SeedWriter::write()` now takes the rows to write (create or update, an empty value
     clearing the field on update) and the keys of records already there. A delete refused by
     a `restrict` relation refuses the upgrade.
5. **Indexes** — step 1 done (branch `feature/template-indexes`). As built:
   - Format: `Archive` (a ustar reader and writer of its own, not PharData: regular files and
     directories only, no `..`/absolute paths, header checksums, entry count and size limits
     while reading the gzip stream; reproducible archives: no dates, owners or modes, sorted
     entries), `TemplateIndex`/`IndexEntry` (`metadata-driven/index/v1`; relative urls
     resolved against the index's address; `latest()` skips yanked versions and other formats).
   - `TemplateSources`: the catalog (`TEMPLATE_CATALOG_URL`, optional, no default) then
     `core.template_source`; https only (redirects too), limits (index 5 MB,
     `app.template.max_download_mb` 50, `app.template.max_unpacked_mb` 200), sha256 checked
     before unpacking into a temporary directory, removed after. `PackageLocator` lets install
     and upgrade take a directory or `vendor/name[@version]`; an archive whose template is not
     the listed name and version is refused; an upgrade looks first in the index recorded as the
     installation's source.
   - Commands: `metadata:template:repo [list|add|remove]`, `metadata:template:search`,
     `metadata:template:pack <directory> <archive> [--index] [--url]`; `install`/`upgrade` take
     `--repo`.
   - Tests: the HTTP client answers from `tests/Support/FakeTemplateServer` in the test env
     (`framework.http_client.mock_response_factory`).
   - Step 2, next: signatures (Ed25519 with sodium: a key pair command, `pack --sign`, the
     catalog's public key shipped, `repo add --key`, an unsigned index accepted only once
     confirmed).
6. **Admin UI**: the catalog, install, upgrade with the plan and the conflicts shown before
   applying, item by item.
7. **Publish**: `metadata:template:publish` to the catalog.

In the catalog (its own plan, from phase 5 on):

- **C0** extract `src/TemplateFormat/` into `fedale/template-format`, required by both;
- **C1** skeleton, accounts (GitHub sign-in), vendor namespaces;
- **C2** publish and check, storage, checksum, signature, `/index.json`;
- **C3** search, template pages, the search API;
- **C4** yank, reports, verified vendors, the publish API for phase 7.

Reference fixture, used in the tests and as the first published templates:

- `acme/geo-italy`: regions, provinces, municipalities, with `managed` seeds from the ISTAT CSV;
- `acme/registration`: account, profile, phone, qualification, the `registration` form;
  requires `acme/geo-italy`.

Docs in the same branches: a new user guide `docs/templates.md`, plus `console.md`, `admin.md`,
`architecture.md` (the `Template` module and its Deptrac rules), `README.md`, `status.md`.

## Not in scope

- Templates that ship code (PHP services, Twig templates, Stimulus controllers).
- Permissions and roles in a template. They could come later as a `Role` kind, after the roles
  admin UI.
