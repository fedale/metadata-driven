# Plan: blueprints (packaged, versioned models)

Status: **proposal**, not started. Discussed with the user on 2026-11-03. "Blueprint" is a
working name (see [Open decisions](#open-decisions)).

A blueprint packages a model, meaning its objects, fields, relations, composite forms, views,
translations and seed data, so it can be:

- installed in one step, on any machine;
- published online by anyone, and downloaded from metadata-driven;
- upgraded from one version to the next: installing 2.0 over 1.0 applies only the delta;
- exported from an existing model, in the same format.

It solves a known gap first: the "registration" model exists only in the dev database of the
original machine (see [status.md](status.md#setting-up-on-a-new-machine)). It also covers the
"YAML export/import of metadata" item of the roadmap.

## Open decisions

- **Name**: "blueprint" or "package". "Template" is already taken twice: the `template` column of
  the views, and the Twig `templates/` directory.
- **Local changes to installed items**: a three-way merge (the user may edit what a blueprint
  installed, and upgrades report conflicts), or read-only managed items, as in Salesforce managed
  packages (simpler, more rigid). Recommended: three-way.
- **Hosting**: decentralized indexes only (recommended), or also a central catalog.

## Principles

- **Data, never code.** A blueprint holds no PHP, no scripts, no SQL. Every item goes through
  the same compilers, validators and sanitizers as the admin. This is what makes downloading
  one from a stranger acceptable.
- **One canonical format for every item.** Export produces it, install reads it. The round trip
  `export(install(b)) == b` is the test that keeps the format complete.
- **Stable identities.** Every item carries an `id` that never changes, so an upgrade can tell a
  rename from a removal followed by an addition, and a rename never drops a column.
- **Nothing is lost by an upgrade.** Removals archive, they don't purge (the current lifecycle
  rules). Purging stays an explicit CLI action.
- **All or nothing.** A whole install or upgrade is checked first, then applied in one
  transaction. `--dry-run` stops after the check and prints the plan.

## The format

Every document shares one envelope:

```yaml
format: metadata-driven/v1        # version of the format, not of the blueprint
kind: Object                      # Blueprint | Object | Form | View | Translations | Seed
id: crm.account                   # stable identity, unique within the blueprint
spec: { ... }                     # the item, by kind
```

- `format` versions the representation. A new format version comes with a reader for the old
  one, so old blueprints keep installing.
- A JSON Schema per `kind` is published under `docs/schema/v1/`. It validates files before any
  database work, and gives editors autocompletion.
- References between items use ids (`target: crm.region`, `object: crm.account`), not machine
  names. Ids are mapped to local items at install time.

### Kinds

| Kind | Spec | Built on |
|---|---|---|
| `Blueprint` | The manifest: `name` (`vendor/name`), `version` (semver), `label`, `description`, `requires` (metadata-driven version, other blueprints), `resources` (globs) | — |
| `Object` | Name, labels, plural, search configuration, and its `fields`: each with `id`, name, type, settings, required, unique, condition, relation settings | `ObjectDefinitionDto`, `FieldDefinitionDto`, `RelationSettings`, `FieldConditionDto` |
| `Form` | The composite form YAML as it is today, with `object` and `via` as ids | `FormDefinitionCompiler` |
| `View` | `kind: form` (the HTML layout) or `kind: grid` (the YAML), `object`, `name`, `default` | `SaveViewHandler`, `GridViewCompiler` |
| `Translations` | One locale, keyed by `TranslationKey` | `TranslateHandler` |
| `Seed` | Records of one object, inline or from a CSV file | See [Seeds](#seeds) |

### A package on disk

```
acme-registration-1.0.0/
  blueprint.yaml
  objects/account.yaml
  objects/profile.yaml
  forms/registration.yaml
  views/account.form.html
  views/account.grid.yaml
  translations/it.yaml
  seeds/qualifications.csv
  files/...                       # files referenced by seeds, see the file type plan
```

Distributed as a `.tar.gz` archive with a `sha256` checksum.

## Installing

`metadata:blueprint:install <path|archive|name> [--version=] [--dry-run]`, and later from
**Admin → Blueprints**. Requires `admin.metadata`.

1. **Read and check the documents**: JSON Schema, unknown keys, duplicate ids, dangling
   references, `requires` satisfied (other blueprints installed in a compatible version).
2. **Check collisions**: an object or form with the same machine name that the blueprint does not
   own is refused.
3. **Plan**, ordered by dependency:
   1. the objects;
   2. their non-relation fields;
   3. the relation fields, topologically;
   4. conditions, `dependsOn`, search;
   5. forms, views and translations;
   6. seeds.
4. **Apply** the plan through `MetadataManagerInterface`, in one transaction. PostgreSQL DDL is
   transactional. **To check**: the schema sync (`Schema/Application/Listener`) must run inside
   the same transaction, otherwise a failure halfway leaves tables behind.
5. **Record** the installation (below).

The compilers check forms and views against the objects. Because steps 3.1 to 3.5 run in the same
transaction, a form can be compiled against objects that the same blueprint has just created.
Errors are collected with the path of their document (`forms/registration.yaml: profile.phones:
...`).

### Tracking what is installed

Two static tables in `core`, through a migration:

| Table | Columns |
|---|---|
| `core.blueprint_installation` | `name`, `version`, `snapshot` (JSONB: the documents as installed, the base of the next upgrade), `installed_at`, `upgraded_at` |
| `core.blueprint_item` | `blueprint`, `item_id` (the blueprint id), `kind`, `local_id` (the metadata row it maps to) |

An item in `core.blueprint_item` is **owned** by its blueprint. Anything else is local: a field a
user added to an installed object belongs to nobody, and no upgrade touches it.

## Upgrading

`metadata:blueprint:upgrade <name> [--to=] [--dry-run] [--strategy=keep|overwrite]`

The delta is computed from three states, not from a list of migration scripts:

| State | Source |
|---|---|
| **base** | The installed version, from `snapshot` |
| **theirs** | The new version |
| **mine** | The current local metadata of the owned items |

- `base → theirs` gives the changes the new version brings. They are applied to `mine`.
- An item changed in `theirs` and **also** changed locally (`base ≠ mine`) is a **conflict**.
  The plan lists it. `--strategy=keep` keeps the local version, `overwrite` takes the
  blueprint's, and without a strategy the upgrade stops.
- **Renames**: same id, different name. Applied with the existing rename handlers, so the
  table, the columns and every reference follow.
- **Removals**: an id in `base` and not in `theirs`. The item is archived (refused, as today,
  while it is still used).
- **Field type changes**: the type of a field cannot be changed today. The plan refuses them
  unless the blueprint declares an explicit step.
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

The operations are a **closed list** implemented by the app, never code from the blueprint. They
run, in version order, for every version between `base` and `theirs`.

### Semver

- **Major**: may archive, rename and carry explicit steps. The plan highlights them.
- **Minor**: additions only (objects, fields, forms, views, seed rows).
- **Patch**: labels, translations, layouts, settings that don't narrow existing data.

Install checks the declared bump against the computed plan. A minor version that removes a field
is refused as a malformed blueprint.

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
- `key` must name a unique field. Relations are resolved by the key of the target's seed, in
  dependency order.
- Every row is validated with the rules of its object (required, unique, types, conditions),
  in two passes (check everything, then write), all or nothing.
- The engine is the **CSV import service** of the roadmap (plan A in `status.md`), so the two
  are built together. The `record:import` CLI is a by-product.

### Seeds with files

Requires the `file` field type ([file-type-plan.md](file-type-plan.md)).

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
  `.jpg` that is something else is refused.
- Order, because the storage is not transactional:
  1. check every row and every file without writing (`--dry-run` stops here);
  2. store the files;
  3. write the records in one transaction.

  A failure at step 3 leaves orphaned files for `metadata:files:gc`. A record never points at a
  missing file.
- Upgrades compare hashes: an unchanged file is not stored again, and a replaced one becomes an
  orphan.
- Export writes the files into `files/` of the archive.

## Online distribution

- An **index** is a static JSON file listing blueprints, versions, archive URLs and checksums,
  in the manner of Helm repositories. Anyone can host one (GitHub Pages, a CDN, any web server).

  ```json
  {
    "format": "metadata-driven/index/v1",
    "blueprints": {
      "acme/registration": {
        "1.0.0": { "url": "https://…/acme-registration-1.0.0.tar.gz", "sha256": "…", "requires": { "metadata-driven": ">=1.4" } }
      }
    }
  }
  ```

- `metadata:blueprint:repo add|remove|list` manages the configured indexes (stored in `core`).
  `metadata:blueprint:search` and **Admin → Blueprints** show the catalog, the installed versions
  and the available upgrades.
- Downloads use `symfony/http-client`. They are `https` only, have a size limit (in MB,
  configurable) and check the checksum before anything is unpacked. Unpacking refuses absolute
  paths, `..` and symlinks.
- Signing (a public key per index) can come later. The checksum only proves the archive is the
  one the index lists.

## Exporting

`metadata:blueprint:export <name> --version= --object=… [--form=…] [--with-seed=object:key]`

- Writes the canonical format for the chosen items, assigning ids to items that have none
  (`<vendor>.<object>`, `<vendor>.<object>.<field>`) and recording them, so a later export of the
  same model keeps the same ids.
- Relations to objects outside the export are refused, unless the target belongs to an installed
  blueprint, which then becomes a `requires`.
- Exporting the items of an installed blueprint, with no local changes, must reproduce it.

## Phases

1. **Format and export**: the envelope, the kinds, the JSON Schemas, the export command, ids.
   Test: export a model built in a test, then validate it against the schemas.
2. **Install**: read, check, plan, apply in one transaction, `--dry-run`, the tracking tables.
   Test: the round trip, collisions, a failure halfway that leaves nothing behind.
3. **Seeds (text)**: the import service, `once` and `managed`, references by key. With the
   `record:import` CLI.
4. **Upgrade**: three-way delta, renames, archive, conflicts, explicit steps, semver checks.
5. **Seeds with files**: once the `file` type is done.
6. **Online**: indexes, download, checksum, unpacking, the catalog.
7. **Admin UI**: catalog, install, upgrade with the plan shown before applying.

Reference fixture, used in the tests and as the first published blueprints:

- `acme/geo-italy`: regions, provinces, municipalities, with `managed` seeds from the ISTAT CSV;
- `acme/registration`: account, profile, phone, qualification, the `registration` form;
  requires `acme/geo-italy`.

Docs in the same branches: a new user guide `docs/blueprints.md`, plus `console.md`, `admin.md`,
`architecture.md` (the module, probably `Blueprint`, and its Deptrac rules), `README.md`,
`status.md`.

## Not in scope

- Blueprints that ship code (PHP services, Twig templates, Stimulus controllers).
- Permissions and roles in a blueprint. They could come later as a `Role` kind, after the roles
  admin UI.
- Many-to-many relations, until they exist in the app.
