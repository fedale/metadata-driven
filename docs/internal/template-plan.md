# Plan: templates (packaged, versioned models)

Status: **decided, not started**. Discussed with the user on 2026-11-03; name, upgrade model
and distribution decided on 2026-11-05 (see [Decisions](#decisions)).

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

## Open decisions

- **Field settings without the app.** The format must check a field's settings (each type has
  its own: `maxLength`, `accept`, `target`...) without depending on the app, where they are
  defined today (`FieldTypeInterface::settingDefinitions()`). Phase 1 decides how: the format
  carries a static description of the field types it knows (and a test in metadata-driven
  checks it matches every registered type), or it checks the envelope only and leaves settings
  to the install. The first is what the catalog needs to refuse a broken template on publish.
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
  views/account.form.html
  views/account.grid.yaml
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

1. **Format and export**: the envelope, the kinds, the JSON Schemas, the field settings without
   the app, the Deptrac boundary of `App\TemplateFormat`, the export command, ids. Test: export a
   model built in a test, then validate it against the schemas.
2. **Install**: read, check, plan, apply in one transaction, `--dry-run`, the tracking tables.
   Test: the round trip, collisions, a failure halfway that leaves nothing behind.
3. **Seeds**: the import service, `once` and `managed`, references by key, files. With the
   `record:import` CLI.
4. **Upgrade**: three-way delta, renames, archive, conflicts, explicit steps, semver checks.
5. **Indexes**: the index format, `repo add`, download, checksum, signatures, unpacking.
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
