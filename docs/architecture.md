# Architecture

Symfony 8.1, PostgreSQL 17, server-rendered (Twig, Symfony UX Turbo and Stimulus). The grid comes
from `fedale/gridview-bundle`, the runtime settings from `fedale/setting-bundle`.

## Schemas

| Schema | Holds | Managed by |
|---|---|---|
| `core` | The workspace registry (`core.workspace`) and who may sign in to each workspace (`core.workspace_membership`); object, field, view and form definitions, the schema change log, users and profiles, the metadata of stored files (`core.file`), the packages installed (`core.package_installation`) and the ids of items in packages (`core.package_item`), the workflows, their versions and their runs (`core.workflow*`), the settings of `fedale/setting-bundle`, `doctrine_migration_versions` | Doctrine ORM and migrations |
| a workspace's data schema (`data` for the first workspace) | One table per object, `c_<object>`, holding the records, and one link table per many-to-many field, `l_<object>__<field>` | The Schema module, from the workspace's metadata |
| a workspace's RBAC schema (`rbac` for the first workspace) | The tables of `fedale/rbac-bundle` (`auth_*`): the workspace's roles, permissions and assignments | Created with the workspace |
| `no_workspace_rbac` | The same tables, empty: the RBAC tables of a session with no workspace (the system's, the console's), where the bundle finds tables and no permission | Migrations |

Every session's `search_path` starts with `core`, so the tables whose mapping names no schema
(Doctrine's migration table, the settings) are found there; then comes the current workspace's
RBAC schema, where `fedale/rbac-bundle` finds its tables unqualified; then `public`, which holds
no table, only the `pg_trgm` extension. The application sets the path itself (see "Workspaces")
because `DATABASE_URL` can't: `pdo_pgsql` ignores the libpq `options` parameter, and the Symfony
CLI replaces the URL with the one from the Docker container.

Records are read and written with DBAL, never as ORM entities: their tables do not exist when the
code is written.

### Workspaces

The installation serves **workspaces**, each with its own model, records, roles and files, created
and managed with the [`workspace:*` commands](console.md#workspaces); the [Workspaces](workspaces.md)
guide says how to use and deploy them. Under the hood (see also
[the plan](internal/multi-tenancy-plan.md)):

- `core.workspace` registers them: a slug, a name, a `number` (for `fedale/setting-bundle`, whose
  tenant ids are integers), its data schema and its RBAC schema, and a status (`active` or
  `suspended`). The installation's first workspace is `default`, with `data` and `rbac`.
- The core tables whose rows belong to a workspace (the definitions, the schema change log, the
  files, the packages: `WorkspaceScope::TABLES`) carry a `workspace_id`. Unique names are unique per
  workspace: two workspaces may both have an `account` object.
- **The current workspace is a setting of the PostgreSQL session**, `app.workspace_id`, with the
  `search_path` that goes with it (`WorkspaceSessionSql`). `WorkspaceSwitcher` sets both, and forgets
  what the previous workspace left in memory: the ORM's entities and, through `WorkspaceSwitched`,
  the metadata snapshot, the translations, the revision. `CurrentWorkspace` reads the session, so
  PHP and SQL can't disagree.
- **A request** is for the workspace its host names, `<slug>.<base host>` (`HostWorkspaceListener`,
  above the firewall): `acme.example.com`, or `acme.localhost` in development, which browsers
  resolve to 127.0.0.1 without any DNS. The base host itself, and any other host, is the
  default workspace's. An unknown or suspended workspace is a 404.
- **The system console** answers on `console.<base host>` (`app.workspace.console_label`),
  in the system session, for the system admins only; its paths (`/console…`) answer nowhere
  else. See [Workspaces](workspaces.md#the-system-console).
- **A command** of the application or of a `fedale/*` bundle is for the workspace of its global
  `--workspace=<slug>` option, the default workspace without it (`ConsoleWorkspaceListener`). The other
  commands (Doctrine's, the migrations, `cache:clear`) and the `workspace:*` commands run in the
  system session, unless `--workspace` names a workspace.
- **Without a workspace** (the system session), the ORM reads no workspace's rows, and an insert
  fails on `NOT NULL`. A trigger fills in `workspace_id` on insert from the session (no model maps
  the column), the ORM's `workspace` filter adds it to every read, and the DBAL queries on these
  tables add it themselves.
- Doctrine's filters apply to SELECTs only. A DQL `UPDATE` or `DELETE` on these tables would
  reach every workspace's rows: write it in SQL with `WorkspaceScope::CURRENT`, as
  `DoctrineTranslationRepository` does.
- **Row-level security** is the safety net under those filters: a policy on each of these tables
  lets a session with a workspace see and write that workspace's rows only,
  a session without a workspace none, and the system session every workspace's. The system's is
  an explicit mark, `app.system = 'on'`, set by `WorkspaceSwitcher::system()` for the
  migrations and the workspace commands, and remembered for a connection opened later
  (`WorkspaceSessionState`): a session that lost its workspace sees nothing, not everything. The policies apply
  to the owner of the tables too (`FORCE`), but **not to a superuser or a role with
  `BYPASSRLS`**: connect the application as an ordinary role in production. The Docker database
  of development connects as a superuser, so there the filters work alone; the tests check the
  policies under a role of their own.
- **Rows offered to every workspace**: `package_source` (`WorkspaceScope::SHARED`) may have no
  workspace, an index the system admin offers to all (`system:package:repo`, as the system). The
  `workspace` filter and the policies let every workspace read such a row, and none write it.
- `CurrentWorkspace` gives the workspace's id and data schema to the code that names record tables
  (the Schema and Record modules).
- **Users are global**, with their profile; a membership (`core.workspace_membership`, the
  system's, without row-level security) lets them sign in to a workspace. `UserProvider` finds
  only the members of the request's workspace, at sign-in and on every request; the
  `SecurityUser` it loads holds that workspace, so the session and the remember-me cookie are bound
  to it (see [Users and workspaces](access.md#users-and-workspaces)).
- `fedale/rbac-bundle` (1.2) is told the current workspace by `RbacWorkspaceContext`
  (`tenancy.context` in `fedale_rbac.yaml`): its cache keeps each workspace's roles and permissions
  under a key of their own (`fedale_rbac.items.<workspace id>`, in the `rbac.cache` pool), and so
  do its graphs in memory. It needs nothing else, since each workspace has its own tables: every
  assignment stays in the bundle's `global` tenant (`auth_assignment.tenant_id`, its default),
  and `scoped_assignments`, for tables shared by every workspace, stays off. A session with no
  workspace is `global` to the bundle, and reads the empty tables of `no_workspace_rbac`.
- `DeclareWorkspaceSchemaListener` brings the schema tools in line (the unmapped column, the RBAC
  tables, the workspaces' schemas), so `migrations:diff` stays empty.

The settings: `WORKSPACE_BASE_HOST` (default `localhost`) and `WORKSPACE_DEFAULT` (default `default`;
empty: every request must name its workspace, and the commands run without one unless they name it).
`app.workspace.fallback` is the workspace every new connection starts in, before a request or a command
selects one: empty, except in the tests, which have neither.

The bytes of stored files are in neither schema: they are in the `record_files` storage of
`league/flysystem-bundle` (`config/packages/flysystem.yaml`), on the local disk, and in memory in
the tests. The directory is the `RECORD_FILES_DIR` environment variable, `var/storage/files` of
the project when it's not set.

Each workspace has a directory of its own there, named after its id (`StorageKeys`):
`<workspace id>/<aa>/<SHA-256>` for the contents, `<workspace id>/thumbnails/<size>/<SHA-256>.<format>`
for the thumbnails. The same bytes are stored once per workspace, never shared between workspaces, so
no workspace learns what another stores, the GC of one workspace looks at its own directory and rows
only, and `workspace:delete` deletes the directory with the workspace. Each row of `core.file` keeps the
key it was stored under: the files stored before workspaces had directories, at the root of the
storage (`<aa>/<SHA-256>`), stay readable until
[`workspace:files:move`](console.md#workspacefilesmove) moves them, once.

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

The package format is a library of its own, `fedale/metadata-driven-package-format`
(`Fedale\PackageFormat`, a repository of the same name, required from Packagist; until 1.2
`fedale/metadata-driven-package-format`): documents, JSON
Schemas, the field type catalog, the structural checks, packages as directories and as
`.tar.gz` archives, signed indexes. The `Package` module is its only user in the app; the
package catalog uses it too (see [Packages](packages.md)). It depends on `symfony/yaml` and
`opis/json-schema` only, and has its own tests (`composer qa` in its repository).

| Module | Responsibility | Depends on (contracts) |
|---|---|---|
| Metadata | Object, field, view and form definitions, their translations, the cached registry and its localized variant, the `metadata:*` commands | — |
| FieldType | The field types: settings, columns, conversion, constraints, widgets | Metadata |
| Schema | Turns definitions into DDL: tables, indexes, foreign keys, trigram and unique-together indexes; `schema:sync` | Metadata, FieldType, Search |
| Validation | One validator for every record write, built from the metadata | Metadata, FieldType |
| Search | Search handlers and the search configuration of objects | Metadata, FieldType |
| Record | Record storage, the write pipeline, delete rules, lookups, the grid source, record forms (with their view layout), forms and their pages, the download of the files of the records (`/files/{id}`), CSV imports (`record:import`) | Metadata, FieldType, Validation, Search, Access, File |
| Admin | The no-code builder at `/admin`, on `MetadataManagerInterface` | Metadata, FieldType, Schema |
| User | Users and their profiles (with their language), sign-in (Symfony Security), `user:create` | — |
| Access | The permissions of objects and admin, checked through `fedale/rbac-bundle`; `access:sync` | Metadata |
| Package | Packages: the export of objects, views, forms and translations (`metadata:package:export`), their validation (`metadata:package:validate`), their install and their upgrades through `MetadataManagerInterface` in one transaction (`metadata:package:install`, `metadata:package:upgrade`, `metadata:package:list`), the installations (`core.package_installation`), the ids items keep in a package (`core.package_item`), the indexes looked into (`core.package_source`), downloads over https (`symfony/http-client`) checked against their checksum and signature; the admin pages at `/admin/packages`; the records of seeds, through the record pipeline; built on `Fedale\PackageFormat` | Metadata, Record, FieldType, File, Access |
| File | Stored files: their bytes in the `record_files` Flysystem storage, keyed by their SHA-256 (the same bytes are stored once), their metadata in `core.file`, the type detected from the content; their thumbnails (`imagine/imagine`, GD); the GC, `metadata:files:gc`, which finds the references in the foreign keys to `core.file` (no other module needed); `FileStorageInterface`, `ThumbnailerInterface` | — |
| Workflow | Record workflows: their definitions and versions (`core.workflow*`), the engine listening to the Record module's events, the runs, `/admin/workflows`, `workflow:runs:gc` (see [Workflows](workflows.md)) | Metadata, Record, Access |
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

The cache keys of the metadata (the snapshot of the objects, the forms, the
translations) carry the **metadata revision** and the **instance's id**, the current workspace's
row of `core.metadata_state`, read once per request (`MetadataRevision`). Triggers on the
metadata tables bump the revision of the workspace whose rows changed, in the transaction of every
write, whatever makes it (the admin, the console, a package, a migration), so:

- a change made on one server reaches every server at its next request, whatever cache each one
  keeps (the filesystem by default): their keys have changed;
- two databases never share cached metadata, even behind one cache (a shared Redis, a checkout
  pointed at another database). A copy of a database (`pg_dump` and restore) keeps the instance's
  id: give the copy a cache of its own, or a new id (`UPDATE core.metadata_state SET instance =
  gen_random_uuid()`);
- two workspaces never share cached metadata either: each has an instance id of its own, and a
  change to one workspace's metadata leaves the other workspaces' keys alone.

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
rewrite the views and forms naming the old name (compiled again against the renamed
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

Every write raises two events (`App\Module\Record\Contract\Event`): `RecordWriting`, before it
and in its transaction, whose listeners may change the raw values (validated afterwards) or refuse
the write; `RecordWritten`, once the outermost transaction committed
(`TransactionRunner::whenDone()`), one per record, never for what was rolled back. The Workflow
module listens; `RecordEventScopeInterface` silences them (a package's seeds) or stamps them with
the workflow run they come from.

## Styles

The application follows the CSS grammar of fedale/gridview-bundle, whose stylesheet comes first
(`assets/styles/grid.scss`):

| Prefix | What | Where |
|---|---|---|
| `gv-` | Generic components: form rows, labels, controls and errors (the bundle's form theme, `gv_form_theme.html.twig`, set for every form in `config/packages/twig.yaml`), the required marker and note, buttons (`gv-btn`), the switch, the segmented choice, the password meter and eye, repeatable rows (`gv-collection`) | fedale/gridview-bundle: `_forms.scss` and its Stimulus controllers, see its guide `19_form-components.md` |
| `md-` | Components of this application only: page, top bar, flash messages, admin, form sections, view layouts | `assets/styles/app.css` |

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
