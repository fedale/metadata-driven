# Architecture

Symfony 8.1, PostgreSQL 17, server-rendered (Twig, Symfony UX Turbo and Stimulus). The grid comes
from `fedale/gridview-bundle`, the runtime settings from `fedale/setting-bundle`.

## Schemas

| Schema | Holds | Managed by |
|---|---|---|
| `core` | The tenant registry (`core.tenant`) and who may sign in to each tenant (`core.tenant_membership`); object, field, view and composite form definitions, the schema change log, users and profiles, the metadata of stored files (`core.file`), the templates installed (`core.template_installation`) and the ids of items in templates (`core.template_item`), the settings of `fedale/setting-bundle`, `doctrine_migration_versions` | Doctrine ORM and migrations |
| a tenant's data schema (`data` for the first tenant) | One table per object, `c_<object>`, holding the records, and one link table per many-to-many field, `l_<object>__<field>` | The Schema module, from the tenant's metadata |
| a tenant's RBAC schema (`rbac` for the first tenant) | The tables of `fedale/rbac-bundle` (`auth_*`): the tenant's roles, permissions and assignments | Created with the tenant |

Every session's `search_path` starts with `core`, so the tables whose mapping names no schema
(Doctrine's migration table, the settings) are found there; then comes the current tenant's
RBAC schema, where `fedale/rbac-bundle` finds its tables unqualified; then `public`, which holds
no table, only the `pg_trgm` extension. The application sets the path itself (see "Tenants")
because `DATABASE_URL` can't: `pdo_pgsql` ignores the libpq `options` parameter, and the Symfony
CLI replaces the URL with the one from the Docker container.

Records are read and written with DBAL, never as ORM entities: their tables do not exist when the
code is written.

### Tenants

The installation serves **tenants**, each with its own model, records, roles and files, created
and managed with the [`tenant:*` commands](console.md#tenants); the [Tenants](multi-tenancy.md)
guide says how to use and deploy them. Under the hood (see also
[the plan](internal/multi-tenancy-plan.md)):

- `core.tenant` registers them: a slug, a name, a `number` (for `fedale/setting-bundle`, whose
  tenant ids are integers), its data schema and its RBAC schema, and a status (`active` or
  `suspended`). The installation's first tenant is `default`, with `data` and `rbac`.
- The core tables whose rows belong to a tenant (the definitions, the schema change log, the
  files, the templates: `TenantScope::TABLES`) carry a `tenant_id`. Unique names are unique per
  tenant: two tenants may both have an `account` object.
- **The current tenant is a setting of the PostgreSQL session**, `app.tenant_id`, with the
  `search_path` that goes with it (`TenantSessionSql`). `TenantSwitcher` sets both, and forgets
  what the previous tenant left in memory: the ORM's entities and, through `TenantSwitched`,
  the metadata snapshot, the translations, the revision. `CurrentTenant` reads the session, so
  PHP and SQL can't disagree.
- **A request** is for the tenant its host names, `<slug>.<base host>` (`HostTenantListener`,
  above the firewall): `acme.example.com`, or `acme.localhost` in development, which browsers
  resolve to 127.0.0.1 without any DNS. The base host itself, and any other host, is the
  default tenant's. An unknown or suspended tenant is a 404.
- **A command** of the application or of a `fedale/*` bundle is for the tenant of its global
  `--tenant=<slug>` option, the default tenant without it (`ConsoleTenantListener`). The other
  commands (Doctrine's, the migrations, `cache:clear`) and the `tenant:*` commands run in the
  landlord's session, unless `--tenant` names a tenant.
- **Without a tenant** (the landlord's session), the ORM reads no tenant's rows, and an insert
  fails on `NOT NULL`. A trigger fills in `tenant_id` on insert from the session (no model maps
  the column), the ORM's `tenant` filter adds it to every read, and the DBAL queries on these
  tables add it themselves.
- Doctrine's filters apply to SELECTs only. A DQL `UPDATE` or `DELETE` on these tables would
  reach every tenant's rows: write it in SQL with `TenantScope::CURRENT`, as
  `DoctrineTranslationRepository` does.
- **Row-level security** is the safety net under those filters: a policy on each of these tables
  lets a session with a tenant see and write that tenant's rows only,
  a session without a tenant none, and the landlord's session every tenant's. The landlord's is
  an explicit mark, `app.landlord = 'on'`, set by `TenantSwitcher::landlord()` for the
  migrations and the tenant commands, and remembered for a connection opened later
  (`TenantSessionState`): a session that lost its tenant sees nothing, not everything. The policies apply
  to the owner of the tables too (`FORCE`), but **not to a superuser or a role with
  `BYPASSRLS`**: connect the application as an ordinary role in production. The Docker database
  of development connects as a superuser, so there the filters work alone; the tests check the
  policies under a role of their own.
- **Rows offered to every tenant**: `template_source` (`TenantScope::SHARED`) may have no
  tenant, an index the operator offers to all (`tenant:template:repo`, as the landlord). The
  `tenant` filter and the policies let every tenant read such a row, and none write it.
- `CurrentTenant` gives the tenant's id and data schema to the code that names record tables
  (the Schema and Record modules).
- **Users are global**, with their profile; a membership (`core.tenant_membership`, the
  landlord's, without row-level security) lets them sign in to a tenant. `UserProvider` finds
  only the members of the request's tenant, at sign-in and on every request; the
  `SecurityUser` it loads holds that tenant, so the session and the remember-me cookie are bound
  to it (see [Users and tenants](access.md#users-and-tenants)).
- `fedale/rbac-bundle`'s cache is off: it keeps the roles and permissions under one key for the
  whole installation. It comes back once the bundle knows about tenants.
- `DeclareTenantSchemaListener` brings the schema tools in line (the unmapped column, the RBAC
  tables, the tenants' schemas), so `migrations:diff` stays empty.

The settings: `TENANT_BASE_HOST` (default `localhost`) and `TENANT_DEFAULT` (default `default`;
empty: every request must name its tenant, and the commands run without one unless they name it).
`app.tenant.fallback` is the tenant every new connection starts in, before a request or a command
selects one: empty, except in the tests, which have neither.

The bytes of stored files are in neither schema: they are in the `record_files` storage of
`league/flysystem-bundle` (`config/packages/flysystem.yaml`), on the local disk, and in memory in
the tests. The directory is the `RECORD_FILES_DIR` environment variable, `var/storage/files` of
the project when it's not set.

Each tenant has a directory of its own there, named after its id (`StorageKeys`):
`<tenant id>/<aa>/<SHA-256>` for the contents, `<tenant id>/thumbnails/<size>/<SHA-256>.<format>`
for the thumbnails. The same bytes are stored once per tenant, never shared between tenants, so
no tenant learns what another stores, the GC of one tenant looks at its own directory and rows
only, and `tenant:delete` deletes the directory with the tenant. Each row of `core.file` keeps the
key it was stored under: the files stored before tenants had directories, at the root of the
storage (`<aa>/<SHA-256>`), stay readable until
[`tenant:files:move`](console.md#tenantfilesmove) moves them, once.

In production, set `RECORD_FILES_DIR` to a directory that outlives the deploys, such as
`/srv/metadata-driven/files`: a deploy that builds each release in a directory of its own (or a
new container image) would otherwise leave the files behind. The web server's user needs to write
there. Back it up together with the database: a row of `core.file` without its bytes is a file
that can't be downloaded, and bytes without a row are deleted by
[`metadata:files:gc`](console.md#metadatafilesgc). Another Flysystem adapter (S3 or compatible)
would be a change of `config/packages/flysystem.yaml` only.

## Modules

Code lives in `src/Module/<Module>/`, each module split into the same layers:

| Layer | Holds | May use |
|---|---|---|
| `Contract` | The module's public API: interfaces, readonly DTOs, events, exceptions | Shared |
| `Domain` | Models and rules, free of the framework | Contract, Shared |
| `Application` | Use cases (command handlers), services | Domain, Contract, Shared, framework |
| `Infrastructure` | Doctrine mappings, DBAL, cache | everything above |
| `UI` | Controllers, forms, console commands, Twig | Application, Domain, Contract, Shared, framework |

A module only sees the `Contract` of the other modules. Two Deptrac rulesets enforce both rules
(`make deptrac`): `deptrac.layers.yaml` and `deptrac.modules.yaml`.

The template format is a library of its own, `fedale/metadata-driven-template-format`
(`Fedale\TemplateFormat`, a repository of the same name, required from Packagist): documents, JSON
Schemas, the field type catalog, the structural checks, packages as directories and as
`.tar.gz` archives, signed indexes. The `Template` module is its only user in the app; the
template catalog uses it too (see [Templates](templates.md)). It depends on `symfony/yaml` and
`opis/json-schema` only, and has its own tests (`composer qa` in its repository).

| Module | Responsibility | Depends on (contracts) |
|---|---|---|
| Metadata | Object, field, view and composite form definitions, their translations, the cached registry and its localized variant, the `metadata:*` commands | — |
| FieldType | The field types: settings, columns, conversion, constraints, widgets | Metadata |
| Schema | Turns definitions into DDL: tables, indexes, foreign keys, trigram and unique-together indexes; `schema:sync` | Metadata, FieldType, Search |
| Validation | One validator for every record write, built from the metadata | Metadata, FieldType |
| Search | Search handlers and the search configuration of objects | Metadata, FieldType |
| Record | Record storage, the write pipeline, delete rules, lookups, the grid source, forms (with their view layout), composite forms and pages, the download of the files of the records (`/files/{id}`), CSV imports (`record:import`) | Metadata, FieldType, Validation, Search, Access, File |
| Admin | The no-code builder at `/admin`, on `MetadataManagerInterface` | Metadata, FieldType, Schema |
| User | Users and their profiles (with their language), sign-in (Symfony Security), `user:create` | — |
| Access | The permissions of objects and admin, checked through `fedale/rbac-bundle`; `access:sync` | Metadata |
| Template | Templates: the export of objects, views, forms and translations (`metadata:template:export`), their validation (`metadata:template:validate`), their install and their upgrades through `MetadataManagerInterface` in one transaction (`metadata:template:install`, `metadata:template:upgrade`, `metadata:template:list`), the installations (`core.template_installation`), the ids items keep in a template (`core.template_item`), the indexes looked into (`core.template_source`), downloads over https (`symfony/http-client`) checked against their checksum and signature; the admin pages at `/admin/templates`; the records of seeds, through the record pipeline; built on `Fedale\TemplateFormat` | Metadata, Record, FieldType, File, Access |
| File | Stored files: their bytes in the `record_files` Flysystem storage, keyed by their SHA-256 (the same bytes are stored once), their metadata in `core.file`, the type detected from the content; their thumbnails (`imagine/imagine`, GD); the GC, `metadata:files:gc`, which finds the references in the foreign keys to `core.file` (no other module needed); `FileStorageInterface`, `ThumbnailerInterface` | — |
| Settings | Reserved for the next phases | |

Modules that must react to another one without depending on it go through **ports**: the
consumer defines an interface in its own `Contract`, the provider implements it. For example
Metadata validates field settings through `FieldTypeCatalogInterface`, implemented by FieldType;
Validation checks unique values through `UniqueValueCheckerInterface`, implemented by Record;
Metadata counts the records breaking a new set unique together through
`DuplicateRecordsInterface`, implemented by Record too.

`MetadataRegistryInterface` has two implementations. The default one serves the metadata as
written; the pages and the validation of the records get the one in the user's language with
`#[Target('localizedMetadata')]` (see [Languages](i18n.md#under-the-hood)).

## From a definition to a table

```
metadata:field:create pet owner relation …
  └─ CreateFieldHandler (Metadata)                 ┐
       ├─ settings validated by the field type     │
       ├─ definition saved in core.metadata_field  │ one PostgreSQL
       │    └─ trigger: metadata revision + 1      │ transaction
       └─ FieldDefinitionCreated (event)           │
            └─ SchemaSynchronizer (Schema)         │
                 └─ ALTER TABLE data.c_pet …       ┘
  └─ MetadataChanged (after commit) → the local metadata cache is dropped
```

Inside a transaction the registry reads the definitions from the database, so the schema sync sees
the uncommitted change; the cache is only refreshed once the change is committed.

The cache keys of the metadata (the snapshot of the objects, the composite forms, the
translations) carry the **metadata revision** and the **instance's id**, the current tenant's
row of `core.metadata_state`, read once per request (`MetadataRevision`). Triggers on the
metadata tables bump the revision of the tenant whose rows changed, in the transaction of every
write, whatever makes it (the admin, the console, a template, a migration), so:

- a change made on one server reaches every server at its next request, whatever cache each one
  keeps (the filesystem by default): their keys have changed;
- two databases never share cached metadata, even behind one cache (a shared Redis, a checkout
  pointed at another database). A copy of a database (`pg_dump` and restore) keeps the instance's
  id: give the copy a cache of its own, or a new id (`UPDATE core.metadata_state SET instance =
  gen_random_uuid()`);
- two tenants never share cached metadata either: each has an instance id of its own, and a
  change to one tenant's metadata leaves the other tenants' keys alone.

`MetadataChanged` still drops the cache of the server that made the change, early; elsewhere the
stale items expire after a day. `core.metadata_state` is kept out of the ORM mapping
(`schema_filter` in `config/packages/doctrine.yaml`): the triggers own it.

A field is one column of its object's table, with one exception: a `many_to_many` field has no
column, its links live in a link table of its own. `FieldDefinitionDto::storesColumn()` tells them
apart: every module reading or writing columns (the table factory, the record store, the grid,
the validation) leaves the fields that answer false out of its column lists, and the record store
and the grid source read and write their links through `LinkSource` (`RecordTable::links()`), the
one description of where the links are: the field's link table, or the table of the junction
object it goes through (`through`), whose live records are the links. The Schema module builds the
link tables with their object's table, and renames and drops them with the field or the object;
a field going through a junction object has none.

A rename goes the same way: `RenameObjectHandler` / `RenameFieldHandler` save the definitions,
rewrite the views and composite forms naming the old name (compiled again against the renamed
definitions, which the registry reads from the database), then dispatch `ObjectDefinitionRenamed`
/ `FieldDefinitionRenamed`; the Schema module renames the table, columns, indexes and constraints,
the Access module the permissions. See [Renaming and archiving](lifecycle.md).

## A record write

```
RecordInput (raw values: form, API)
  → denormalization by each field type (strings to dates, numbers, ids)
  → MetadataRecordValidator (Symfony constraints from the field types, required, unique, references)
  → conversion to the stored form by each field type (a password becomes its hash)
  → RecordStore (DBAL, every identifier quoted): the columns, then the links of the
    many-to-many fields, in one transaction
  → RecordOutput (typed values; a many-to-many field holds the linked ids)
```

Forms carry no validation of their own: they only convert input, so every rule lives once, in the
metadata.

## Styles

The application follows the CSS grammar of fedale/gridview-bundle, whose stylesheet comes first
(`assets/styles/grid.scss`):

| Prefix | What | Where |
|---|---|---|
| `gv-` | Generic components: form rows, labels, controls and errors (the bundle's form theme, `gv_form_theme.html.twig`, set for every form in `config/packages/twig.yaml`), the required marker and note, buttons (`gv-btn`), the switch, the segmented choice, the password meter and eye, repeatable rows (`gv-collection`) | fedale/gridview-bundle: `_forms.scss` and its Stimulus controllers, see its guide `19_form-components.md` |
| `md-` | Components of this application only: page, top bar, flash messages, admin, composite form sections, view layouts | `assets/styles/app.css` |

Both follow `block__element--modifier`, and every colour and size comes from the bundle's
`--gv-*` design tokens, defined on `<body data-gv-scope>`: the grid's theme, its dark mode and its
Bootstrap or Tailwind presets apply to the whole page. A component useful to any form belongs in
the bundle; one that exists because of the metadata stays here, with the `md-` prefix.

## The grid

`RecordGridFactory` builds one grid per object at runtime with `GridviewBuilderFactory`: one
column per field, with the filter and the rendering of its type, or the columns of a
[grid view](views.md#grid-views): labels, cell renderers (`CellRendererInterface`, registered by
name), virtual columns joining several fields. Rows come from
`DbalRecordGridSource`, a `GridDataSourceInterface`: the bundle parses filters, sort, search and
page from the request into a normalized `GridQuery`, and the source turns it into SQL, joining the
related tables to show the labels of relation fields.
