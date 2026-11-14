# Glossary

The words used for the parts of the application, one word per concept. Use these names in the
code, the commands, the admin UI and the documentation.

## Modelling

| Term | Meaning |
|---|---|
| **Entity** | A kind of data defined at runtime, such as `book` or `customer`, with its own table `data.c_<entity>` in the tenant's data schema. Defined from `/admin` or the console |
| **Field** | One piece of data of an entity, with a type, a label and settings. It is a column of the entity's table, or a link table for a many-to-many field |
| **Field type** | What a field holds and how it behaves: `text`, `number`, `boolean`, `date`, `email`, `tel`, `url`, `select`, `password`, `file`, `relation`, `many_to_many` (see [Field types](field-types.md)) |
| **Relation** | A many-to-one field pointing at a record of another entity (its **target**) |
| **Many-to-many field** | A field linking a record to many records of another entity, through a link table `data.l_<entity>__<field>` or through a **junction entity** |
| **Junction entity** | An entity with its own fields that a many-to-many field goes through (`through`) |
| **Option** | One of the choices of a `select` field |
| **Machine name** | The technical name of an entity or a field (`published_on`), used for tables, columns, tokens and keys. It is not the **label**, which is the text users see and can be translated |
| **Model** | All the entities, fields, layouts and forms of a tenant together |
| **Definition** | The stored description of an entity, field, layout or form: the metadata, as opposed to the records |

## Data

| Term | Meaning |
|---|---|
| **Record** | One instance of an entity: a row of its table. Records are read and written with DBAL, never as Doctrine entities. "Row" means only the physical row of a table or the line of a grid, never the concept |
| **Record label** | The field (or fields) naming a record in relations, lists and searches |
| **File** | A document or an image uploaded into a `file` field of a record; its metadata are kept in `core.file` |
| **Seed** | The records a package brings with it, written as CSV and installed with it |

## Presentation

| Term | Meaning |
|---|---|
| **Form layout** | How the form of a record of an entity is laid out: HTML with a `{field}` token where each field goes (see [Views](views.md)) |
| **Grid layout** | How the grid of the records of an entity looks: its columns, their order, renderers, virtual columns, the initial sort and the page size, in YAML |
| **Grid** | The list of the records of an entity, with filters, search, sort and export (`fedale/gridview-bundle`) |
| **Virtual column** | A grid column joining several fields, with no column of its own in the table |
| **Renderer** | How a grid cell is drawn (a link, a badge, a thumbnail...) |
| **Token** | A placeholder in a layout: `{field}` for a field, `{section}` for a section of a form, `{t:name}` for a translated text |
| **Form** | One page writing a record **and the records related to it** in one transaction, defined in YAML (see [Composite forms](forms.md)). Opened at `/f/<form>` |
| **Root** | The record a form is about, and the part of the form writing it |
| **Section** | A part of a form writing the records of another entity related to the root or to an outer section: one record, or a repeatable list (`mode: many`) |
| **Service field** | A field of a form that is checked and then dropped: no column stores it (a "I accept the terms" box) |
| **Translation** | The text of a label, option, title or `{t:name}` text in one language, stored under a **key** such as `field.<entity>.<field>` (see [Languages](i18n.md)) |

## Packages

| Term | Meaning |
|---|---|
| **Package** | A model packaged to be installed in a tenant and upgraded later: entities, fields, layouts, forms, translations and seeds, as a directory of YAML documents (see [Templates](templates.md)). It extends what a tenant can do; it has nothing to do with graphic templates or Twig |
| **Manifest** | The package's own description: its name `vendor/name`, version, license, the packages it requires |
| **Package item** | One element a package installs (an entity, a layout, a form...), with a stable id made of the package's vendor and the item's name |
| **Installation** | A package installed in a tenant, at a given version |
| **Upgrade** | Installing a newer version over an installation; each **conflict** (an item changed both here and in the package) is settled by choosing which side stays |
| **Requirement** | Another package a package needs, installed and upgraded with it |
| **Index** | A signed JSON file listing packages and their versions, served by any web server; a **package source** is an index this instance is set to look into (a company's private one, a vendor's) |
| **Catalog** | The public index of packages (`fedale/metadata-driven-templates`) |
| **Yank** | Withdrawing a published version from an index: it is no longer picked as the newest, but can still be installed by naming it |

## Tenants, people and access

| Term | Meaning |
|---|---|
| **Instance** | One installation of the application, serving many tenants |
| **Tenant** | One customer of an instance, with its own model, records, roles and files, reached on its own host (`acme.<base host>`) (see [Tenants](multi-tenancy.md)) |
| **System** | The context with no tenant, working across all of them: the console commands run without `--tenant`, and the system console on `console.<base host>` |
| **System admin** | A user allowed into the system console. A system admin has no power inside a tenant unless they are a member there, with a role |
| **User** | One account (email, password, **profile**) shared by every tenant it belongs to |
| **Membership** | What lets a user sign in to a tenant; the user is then a **member** of it |
| **Invitation** | A link that makes someone a member of a tenant |
| **Permission** | The right to do one thing, such as `record.<entity>.update` (see [Users and permissions](access.md)) |
| **Role** | A named set of permissions given to members of a tenant (`fedale/rbac-bundle`); `ROLE_SUPER_ADMIN` holds them all |

## Changing the model

| Term | Meaning |
|---|---|
| **Rename** | A new machine name for an entity or a field; its table or column and every reference follow |
| **Archive** | An entity or field leaves the application, its data kept |
| **Restore** | An archived entity or field comes back as it was |
| **Purge** | An archived entity or field is deleted for good, with its data |
| **Schema change log** | The history of the changes made to the tables of a tenant |

## Not yet

These names were chosen on 2026-11-14 and the rest of the application does not use them yet. The
code, the commands, the admin UI and the other guides still say:

| Here | Still in use |
|---|---|
| entity | object (`ObjectDefinition`, `metadata:object:create`, `/o/<object>`) |
| record | record and row |
| form layout, grid layout | form view, grid view (`ViewDefinition`) |
| form | composite form |
| package, package item, package source | template, template item, template source (`metadata:template:*`, `core.template_*`, the repositories `metadata-driven-templates` and `metadata-driven-template-format`) |
| system, system admin | landlord, operator (`tenant:operator`) |
