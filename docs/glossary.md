# Glossary

The words used for the parts of the application, one word per concept. Use these names in the
code, the commands, the admin UI and the documentation.

## Modelling

| Term | Meaning |
|---|---|
| **Object** | A kind of data defined at runtime, such as `book` or `customer`, with its own table `data.c_<object>` in the workspace's data schema. Defined from `/admin` or the console |
| **Field** | One piece of data of an object, with a type, a label and settings. It is a column of the object's table, or a link table for a many-to-many field |
| **Field type** | What a field holds and how it behaves: `text`, `number`, `boolean`, `date`, `email`, `tel`, `url`, `select`, `password`, `file`, `relation`, `many_to_many` (see [Field types](field-types.md)) |
| **Relation** | A many-to-one field pointing at a record of another object (its **target**) |
| **Many-to-many field** | A field linking a record to many records of another object, through a link table `data.l_<object>__<field>` or through a **junction object** |
| **Junction object** | An object with its own fields that a many-to-many field goes through (`through`) |
| **Option** | One of the choices of a `select` field |
| **Machine name** | The technical name of an object or a field (`published_on`), used for tables, columns, tokens and keys. It is not the **label**, which is the text users see and can be translated |
| **Model** | All the objects, fields, views and forms of a workspace together |
| **Definition** | The stored description of an object, field, view or form: the metadata, as opposed to the records |

## Data

| Term | Meaning |
|---|---|
| **Record** | One instance of an object: a row of its table. Records are read and written with DBAL, never as Doctrine entities. "Row" is never the concept: it is a physical row of a table, a line of a CSV file, or a line on a page (of a grid, of a repeatable section, of the links of a many-to-many field, the row of a field in a layout) |
| **Record label** | The field (or fields) naming a record in relations, lists and searches |
| **File** | A document or an image uploaded into a `file` field of a record; its metadata are kept in `core.file` |
| **Seed** | The records a package brings with it, written as CSV and installed with it |

## Presentation

| Term | Meaning |
|---|---|
| **Form view** | The layout of the form of a record of an object: HTML with a `{field}` token where each field goes (see [Views](views.md)) |
| **Grid view** | How the grid of the records of an object looks: its columns, their order, renderers, virtual columns, the initial sort and the page size, in YAML |
| **Grid** | The list of the records of an object, with filters, search, sort and export (`fedale/gridview-bundle`) |
| **Virtual column** | A grid column joining several fields, with no column of its own in the table. Its text, with a `{field}` token per field, is its `template` (`template: '{first_name} {last_name}'`): the one place where "template" stays, by choice |
| **Renderer** | How a grid cell is drawn (a link, a badge, a thumbnail...) |
| **Token** | A placeholder in a layout: `{field}` for a field, `{section}` for a section of a form, `{t:name}` for a translated text |
| **Form** | One page writing a record **and the records related to it** in one transaction, defined in YAML (see [Forms](forms.md)). Opened at `/f/<form>` |
| **Root** | The record a form is about, and the part of the form writing it |
| **Section** | A part of a form writing the records of another object related to the root or to an outer section: one record, or a repeatable list (`mode: many`) |
| **Service field** | A field of a form that is checked and then dropped: no column stores it (a "I accept the terms" box) |
| **Translation** | The text of a label, option, title or `{t:name}` text in one language, stored under a **key** such as `field.<object>.<field>` (see [Languages](i18n.md)) |

## Workflows

| Term | Meaning |
|---|---|
| **Workflow** | A series of steps run when something happens to a record of an object (see [Workflows](workflows.md)) |
| **Trigger** | What starts a workflow: a record created, updated or deleted, **before** the write (it may change or refuse it) or **after** it; or someone, from a record's page or a command (a **manual** workflow, its **form** asked first) |
| **Step** | One thing a workflow does: a condition (`if`, `filter`), a write (`create_record`…), `set_values`, `refuse`, a search (`search_records`), an `iterator` over a list; its output is read by its **key** |
| **Version** | One definition of a workflow: a **draft** being written, the **active** one that runs, the deactivated and archived ones before |
| **Run** | One execution of a workflow's active version: its steps and the data it read and wrote; completed, failed or refused |

## Packages

| Term | Meaning |
|---|---|
| **Package** | A model packaged to be installed in a workspace and upgraded later: objects, fields, views, forms, translations and seeds, as a directory of YAML documents (see [Packages](packages.md)). It extends what a workspace can do; it has nothing to do with graphic templates or Twig |
| **Manifest** | The package's own description: its name `vendor/name`, version, license, the packages it requires |
| **Package item** | One element a package installs (an object, a view, a form...), with a stable id made of the package's vendor and the item's name |
| **Installation** | A package installed in a workspace, at a given version |
| **Upgrade** | Installing a newer version over an installation; each **conflict** (an item changed both here and in the package) is settled by choosing which side stays |
| **Requirement** | Another package a package needs, installed and upgraded with it |
| **Index** | A signed JSON file listing packages and their versions, served by any web server; a **package source** is an index this instance is set to look into (a company's private one, a vendor's) |
| **Catalog** | The public index of packages (`fedale/metadata-driven-packages`) |
| **Yank** | Withdrawing a published version from an index: it is no longer picked as the newest, but can still be installed by naming it |

## Workspaces, people and access

| Term | Meaning |
|---|---|
| **Instance** | One installation of the application, serving many workspaces |
| **Workspace** | One customer of an instance, with its own model, records, roles and files, reached on its own host (`acme.<base host>`) (see [Workspaces](workspaces.md)) |
| **System** | The context with no workspace, working across all of them: the console commands run without `--workspace`, and the system console on `console.<base host>` |
| **System admin** | A user allowed into the system console. A system admin has no power inside a workspace unless they are a member there, with a role |
| **User** | One account (email, password, **profile**) shared by every workspace it belongs to |
| **Membership** | What lets a user sign in to a workspace; the user is then a **member** of it |
| **Invitation** | A link that makes someone a member of a workspace |
| **Permission** | The right to do one thing, such as `record.<object>.update` (see [Users and permissions](access.md)) |
| **Role** | A named set of permissions given to members of a workspace (`fedale/rbac-bundle`); `ROLE_SUPER_ADMIN` holds them all |

## Changing the model

| Term | Meaning |
|---|---|
| **Rename** | A new machine name for an object or a field; its table or column and every reference follow |
| **Archive** | An object or field leaves the application, its data kept |
| **Restore** | An archived object or field comes back as it was |
| **Purge** | An archived object or field is deleted for good, with its data |
| **Schema change log** | The history of the changes made to the tables of a workspace |
