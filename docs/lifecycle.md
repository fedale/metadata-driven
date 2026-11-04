# Renaming and archiving

Objects and fields can change their machine name, leave the application for a while, and be
deleted for good. Each change runs in one transaction with its DDL, like every metadata change.

| Change | What happens to the data | Where |
|---|---|---|
| **Rename** | Nothing: the table or column is renamed, the records stay in it | Admin (object and field pages), `metadata:rename` |
| **Archive** | Nothing: the object or field leaves the application, its table or column and the records stay | Admin, `metadata:archive` |
| **Restore** | It comes back as it was, with its records and values | Admin (list of objects, object page), `metadata:archive --restore` |
| **Purge** | Deleted for good: the table or column and every record or value | `metadata:purge` only, on what is archived |

The labels are not the machine name: they change on their own, from the **Labels** section of the
object or the **Label** of a field, and nothing else follows them.

## Renaming

The machine name follows the [naming rule](console.md#how-the-commands-change-the-database) and
must be free: an archived object or field keeps its name until it is purged.

Renaming an **object** renames everything named after it:

- its table `data.c_<name>`, and the indexes and constraints named after the table;
- the link tables of its `many_to_many` fields, `data.l_<name>__<field>`, with their indexes and
  constraints;
- the relation and `many_to_many` fields pointing at it, in every object (`target`), and the
  `many_to_many` fields going through it (`through`);
- the composite forms naming it (`object:`);
- its translations (`object.<name>.*`, `field.<name>.*`, `option.<name>.*`, ...);
- its permissions `record.<name>.*`: the roles and users holding them keep them.

Renaming a **field** renames everything naming it:

- its column, and its unique index, relation index and foreign key; for a `many_to_many` field,
  its link table `data.l_<object>__<name>`, with its indexes and constraints;
- the record label, the searched fields and the sets unique together of its object;
- the conditions it decides, and the cascades depending on it (`dependsOn` in its object,
  `dependsVia` in the relations of other objects pointing at its object);
- the `many_to_many` fields going through its object (`throughSource`, `throughTarget`);
- the views of its object: the `{field}` tokens of a form view, the columns, `{field}` tokens and
  sort of a grid view;
- the composite forms: `fields`, `required`, the `{field}` tokens of a `layout`, and `via`;
- its translations (`field.<object>.<field>`, its options, its inverse title, its pattern message).

The search indexes and the unique-together indexes are built again under the new names. The table then matches the metadata: the
**Table** section of the object reports nothing to apply.

### The definitions written by hand

Views and composite forms are written by hand, so the rename rewrites them, and says which ones it
rewrote ("Rewritten to name the new one: grid view "list", composite form "registration"").

- A **form view** (HTML) keeps its text: only its `{field}` tokens change.
- A **grid view** or a **composite form** (YAML) is read, changed and written again, then compiled
  like when it is saved. Its content is the same, but **its comments and formatting are lost**.
  A definition that does not name the old name is not touched.

If a rewritten definition no longer compiles (a virtual column of a grid view named like the new
field name, for instance), the rename is refused with the error and nothing changes.

### What does not follow

- Bookmarks and links to the pages of the old name (`/o/<old>`, `/admin/objects/<old>`) stop
  working.
- The grid preferences saved per user in the browser (saved searches, visible columns) of a
  renamed object start over.
- The description of the permissions still reads the old name; it is only a description.

## Archiving

An archived object is out of the application: its pages, its grid, the home page, the lookups,
the permissions checks (its records cannot be reached). An archived field is out of the record
forms, the grids, the exports, the search and the validation: new records are written without it.

Nothing physical changes. The table or column stays with its records, values, indexes and foreign
keys; translations and permissions stay too, ready for a restore. An archived field no longer names
the records (the record label falls back to the default) and is no longer searched (its search
index is dropped: it holds no data).

**Archiving is refused while something uses it**, and the message lists every use, to be removed
first. Archiving an object is refused while:

- a relation or `many_to_many` field of another object points at it, or goes through it;
- a composite form has a node on it.

Archiving a field is refused while:

- another field is shown depending on it (a [condition](field-types.md#conditions));
- a relation depends on it, or filters through it (a cascade);
- a view of its object places it: a `{field}` token, a grid column, a sort;
- a composite form names it in `fields`, `required`, a `layout` or `via`. A node without
  `fields` shows every field: the archived one simply leaves it;
- a `many_to_many` field goes through it (`throughSource`, `throughTarget`);
- it is part of a set unique together: archiving it would silently drop a rule on the data
  (`metadata:object:unique <object> <fields> --remove` first).

> Refused: Field "account.active" cannot be archived, it is still used: field "lastname" is shown
> depending on it; composite form "registration", (root): fields. Remove these uses first.

An object of the system cannot be archived nor renamed.

## Restoring

Restoring brings the object or field back as it was. It is refused while what it relies on is
archived:

- an object whose relation or `many_to_many` fields point at an archived object, or go through
  one: restore that one first;
- a field whose relation or `many_to_many` link points at an archived object, whose cascade goes through an archived
  field, which goes through an archived junction object or field, or which is shown depending
  on a field that is archived or purged.

## Purging

Purging deletes for good what is archived, with its data: there is no way back.

- A **field**: its column and every value (for a `many_to_many` field, its link table and every
  link; with `through`, nothing: the junction object keeps its records), its translations; the
  name is free again.
- An **object**: its table and every record, the link tables of its `many_to_many` fields, its
  fields, views and translations, its four permissions (and their grants); the name is free again.
  Refused while a relation or `many_to_many` field of another object points at it, archived or
  not: its foreign key needs the table; and while a `many_to_many` field goes through it.

A purged `file` field, or an object with file fields, leaves its files in the storage: the next
[`metadata:files:gc`](console.md#metadatafilesgc) deletes them. Until the purge, an archived
field keeps its files, as it keeps its other values.

`metadata:purge` asks to type the name back (`pet`, or `pet.age` for a field); without a terminal
it needs `--force`. The admin has no purge button.

## Under the hood

| Piece | Where |
|---|---|
| Rename | `RenameObjectHandler`, `RenameFieldHandler` (Metadata); the rewriting of the hand-written definitions in `DefinitionRewriter` and `FormRewrites` |
| Archive, restore | `ArchiveHandler`, with the uses found by `MetadataUsages`; `FieldDefinition::isArchived`, `ObjectDefinition::isActive` |
| Purge | `PurgeHandler` |
| Events (in the transaction) | `ObjectDefinitionRenamed`, `FieldDefinitionRenamed` → `SchemaSynchronizerInterface::rename()` and the permissions (`RbacManagerInterface::renameItem()`); `FieldArchiveChanged` → schema sync; `ObjectDefinitionPurged`, `FieldDefinitionPurged` → `dropTable()`, `dropColumn()` and the permissions |
| Registry | the archived objects are left out of `allObjects()`, listed by `archivedObjects()`; the archived fields are left out of `fields`, listed in `archivedFields` (the schema keeps their columns) |
