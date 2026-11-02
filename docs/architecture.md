# Architecture

Symfony 8.1, PostgreSQL 17, server-rendered (Twig, Symfony UX Turbo and Stimulus). The grid comes
from `fedale/gridview-bundle`, the runtime settings from `fedale/setting-bundle`.

## Two schemas

| Schema | Holds | Managed by |
|---|---|---|
| `core` | Object, field, view and composite form definitions, the schema change log, users and profiles | Doctrine ORM and migrations |
| `data` | One table per object, `c_<object>`, holding the records | The Schema module, from the metadata |
| `public` | Settings of `fedale/setting-bundle`, RBAC tables (`auth_*`) of `fedale/rbac-bundle` | Doctrine migrations |

Records are read and written with DBAL, never as ORM entities: their tables do not exist when the
code is written.

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

| Module | Responsibility | Depends on (contracts) |
|---|---|---|
| Metadata | Object, field, view and composite form definitions, their translations, the cached registry and its localized variant, the `metadata:*` commands | — |
| FieldType | The field types: settings, columns, conversion, constraints, widgets | Metadata |
| Schema | Turns definitions into DDL: tables, indexes, foreign keys, trigram indexes; `schema:sync` | Metadata, FieldType, Search |
| Validation | One validator for every record write, built from the metadata | Metadata, FieldType |
| Search | Search handlers and the search configuration of objects | Metadata, FieldType |
| Record | Record storage, the write pipeline, delete rules, lookups, the grid source, forms (with their view layout), composite forms and pages | Metadata, FieldType, Validation, Search |
| Admin | The no-code builder at `/admin`, on `MetadataManagerInterface` | Metadata, FieldType, Schema |
| User | Users and their profiles (with their language), sign-in (Symfony Security), `user:create` | — |
| Access | The permissions of objects and admin, checked through `fedale/rbac-bundle`; `access:sync` | Metadata |
| Settings | Reserved for the next phases | |

Modules that must react to another one without depending on it go through **ports**: the
consumer defines an interface in its own `Contract`, the provider implements it. For example
Metadata validates field settings through `FieldTypeCatalogInterface`, implemented by FieldType;
Validation checks unique values through `UniqueValueCheckerInterface`, implemented by Record.

`MetadataRegistryInterface` has two implementations. The default one serves the metadata as
written; the pages and the validation of the records get the one in the user's language with
`#[Target('localizedMetadata')]` (see [Languages](i18n.md#under-the-hood)).

## From a definition to a table

```
metadata:field:create pet owner relation …
  └─ CreateFieldHandler (Metadata)                 ┐
       ├─ settings validated by the field type     │
       ├─ definition saved in core.metadata_field  │ one PostgreSQL
       └─ FieldDefinitionCreated (event)           │ transaction
            └─ SchemaSynchronizer (Schema)         │
                 └─ ALTER TABLE data.c_pet …       ┘
  └─ MetadataChanged (after commit) → the metadata cache is dropped
```

Inside a transaction the registry reads the definitions from the database, so the schema sync sees
the uncommitted change; the cache is only refreshed once the change is committed.

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
  → RecordStore (DBAL, every identifier quoted)
  → RecordOutput (typed values)
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
