# Plan: many-to-many relations

Status: reviewed by the user (type name, read-only inverse, delete rules decided). Phases 1–3
merged into main. Phase 4 confirmed by the user and split: 4a (fields unique together) merged
into main; 4b split again by the user's choice: 4b-1 (`through`: settings, reading, grid,
lifecycle) merged into main; 4b-2 (writing the rows from the record form) done on
`feature/many_to_many_through_rows`, awaiting review: see [Phase 4b-2, as
delivered](#phase-4b-2-as-delivered). Last updated: 2026-11-04.

## Where we are

- `relation` is many-to-one: one `guid` column on the source table, a foreign key to the target,
  `onDelete` applied by the application (soft delete), a read-only inverse list (one-to-many) on
  the target's page.
- One-to-one is a `relation` marked unique, edited from a composite form section `mode: one`.
- Many-to-many is not available. Today the only way is a **junction object** with two `relation`
  fields (e.g. `membership` → `project`, `membership` → `person`), edited as a `mode: many`
  section. Phases 1–3 cover the plain case (a contact has many tags, a tag has many contacts);
  phase 4 lets a `many_to_many` go through such a junction object when the link carries data of
  its own (see [Data on the link](#data-on-the-link-phase-4)).

## The core problem: a field without a column

Every module assumes one field = one column of `data.c_<object>`: `TableDefinitionFactory`,
`RecordTable::selectList()` / `hydrate()`, `DbalRecordStore::insert()` / `update()`,
`TrigramIndexPlanner`, `DbalRecordGridSource`, unique checks, search, labels. A many-to-many
value is a set of ids and lives in its own table. The first step is to make "where a field is
stored" an explicit property of the field type, checked in one place, instead of special-casing
the new type in every module.

## Design

### Metadata

- A new field type, **`many_to_many`** (name chosen by the user), instead of a `multiple` flag on
  `relation`: storage, value shape, delete rules and filters all differ, and `relation()` callers
  would otherwise all have to check the flag.
- Settings (`ManyToManySettings`, Metadata Contract, alongside `RelationSettings`):

  | Setting | Rule |
  |---|---|
  | `target` | an existing object, fixed after creation (the same object is allowed: "related contacts") |
  | `onDelete` | `unlink` (default) or `restrict`; `cascade` is refused (deleting a tag must not delete the contacts) |
  | `inverseName`, `inverseLabel` | as on `relation`; unique among the target's fields and inverse names of both types |
  | `max` | optional, at least 1: how many records may be linked |

- `FieldDefinitionDto` gets `storesColumn(): bool`, `manyToMany(): ?ManyToManySettings` and
  `target(): ?string` (the target of a relation or a many-to-many). Modules filter on
  `storesColumn()`, never on the type name. *Done differently from the first draft:* no
  `FieldStorage` enum on `FieldTypeInterface`, because the Metadata module needs the distinction
  too (its refusals) and cannot see the FieldType module; the DTO is the single place. The domain
  `FieldDefinition` has the matching `isManyToMany()` and `getTarget()`.
- `columnName` is still assigned (no migration of `core.metadata_field`): it names the link table.
- Refused for a `many_to_many`: unique, label field (`metadata:object:label`), search field
  (`metadata:object:search`), deciding field of a condition, `via` of a composite form section,
  `dependsOn` / `dependsVia` of a cascade. It may itself be conditional (emptied when the
  condition is not met).
- *Done differently:* `incomingRelations()` is unchanged; a separate
  `MetadataRegistryInterface::incomingManyToMany()` returns `ManyToManyReference`s (the delete rule
  enums differ, so one reference type with a `kind` would need casts everywhere).
- *Not needed:* the snapshot cache key stays: the DTO gained methods, not properties, and the
  settings fingerprint already covers the new type's defaults.

### Schema

- One link table per field, `data.l_<object>__<field column>` (through `identifier()`, which
  keeps it under 63 characters). The double underscore never occurs in a machine name, so object
  `a_b` + field `c` and object `a` + field `b_c` cannot collide:

  | Column | |
  |---|---|
  | `record_id` | guid, not null, FK → source `id`, `ON DELETE CASCADE` |
  | `target_id` | guid, not null, FK → target `id`, `ON DELETE CASCADE` (`RESTRICT` for `restrict`) |
  | `created_at` | timestamp, not null: when the link was made |
  | `created_by` | guid, null: who made it, as on the object tables |

  Primary key `(record_id, target_id)`, index on `target_id` (inverse lists, delete rules, filters),
  and an index on `record_id`: redundant with the primary key, but DBAL adds one on the columns of
  each foreign key alone anyway, named after a hash of the table name, which a rename would then
  drop and rebuild; naming it ourselves keeps renames clean.
- `TableDefinitionFactory` builds the object table plus its link tables; `DbalSchemaSynchronizer`
  plans and syncs them with the object (create, rename with the object or the field, drop on
  purge of the field, of its object, or of its target). `objectNames()` covers their constraints
  so a rename pairs them up. `TrigramIndexPlanner` skips them.
- Archive keeps the link table, as it keeps a column.

### Records

- Value shape: `list<string>` of ids, unordered, read back sorted by the target's label. In
  `RecordInput` an absent field is untouched, `[]` or `null` clears it, a list replaces the set.
- Write pipeline: the type denormalizes a list (each id a UUID, lowercased, duplicates dropped);
  validation checks every id exists and is not deleted (one query, `ReferenceCheckerInterface::
  missing(target, ids)`), required = at least one, `max`; then `RecordStore::replaceLinks()` diffs
  and writes in the same transaction as the record.
- Read: `find()` loads the ids of each link field with one `array_agg` query per field; `latest()`
  and the grid use a correlated subquery. Deleted targets are filtered out by a join on
  `deleted_at IS NULL`, so a soft-deleted tag disappears from its contacts without touching them.
- Delete rules, when a **target** is deleted: `unlink` deletes its link rows (and touches the
  sources' `updated_at`, as `clearReferences()` does); `restrict` refuses while live sources link
  to it ("This record cannot be deleted: 3 Contacts (Tags) still refer to it."). When a
  **source** is deleted nothing happens: its links are hidden with it and removed by the FK on
  purge.
- Inverse side: the target's page lists the sources through the link table (`referencing()`,
  `countReferencing()`, `referencingIds()` branch on the reference kind). Read-only, as for
  one-to-many.

### UI

- Form: `ChoiceType` with `multiple: true` and UX Autocomplete on `record_lookup` (already
  supports multiple); choices prefilled with the labels of the linked records. Works in composite
  forms as a field of the root or of a section.
- Record page: the labels as links to the linked records.
- Grid: the cell lists the labels (`string_agg(... ORDER BY label)`); the filter is the bundle's
  relation filter with `multiple: true`, applied as `EXISTS (link where target_id IN …)`; no sort;
  export writes the labels joined by ", ". Grid views (`GridViewCompiler`) accept it as a column,
  not in a virtual column nor as a sort.
- Admin: the type appears in the builder through `settingDefinitions()`; the label-field and
  search-field pickers leave it out.

## Phases (one branch each, docs in the same branch)

**Phase 1, as delivered.** Besides the storage: until phase 2 the Record and Validation modules
skip a field that does not store a column (`RecordTable`, `RecordInputDenormalizer`,
`RecordManager::applyConditions()`, `MetadataRecordValidator`, `RecordFormFactory`,
`RecordGridFactory`), so an object with a `many_to_many` field keeps working, links left alone (a
required one is not enforced yet). `GridViewCompiler` refuses it as a column until phase 3. The
admin lists it under "Pointed at by". Phases 2 and 3 replace these skips with the real handling.

1. **Storage** (`feature/many_to_many_storage`): `FieldStorage`, the field type and its
   settings, metadata validation and refusals, link tables in the schema sync, rename / archive /
   purge, cache key. Docs: `field-types.md`, `lifecycle.md`, `architecture.md` (a field without
   a column).
2. **Records** (`feature/many_to_many_records`): write and read pipeline, validation, delete
   rules, inverse lists, form widget, record page, composite forms. Docs: `field-types.md`,
   `forms.md`.
3. **Grid** (`feature/many_to_many_grid`): cell, filter, export, grid views. A gridview-bundle
   change only if the relation filter or the cell need one (none expected). Docs: `views.md`,
   and drop "Many-to-many relations are not available yet." from `field-types.md`.

4. **Data on the link** (`feature/many_to_many_through`): the `through` setting, below.

Each phase: unit tests for the type and the compilers, integration tests on PostgreSQL for the
schema and the store, a functional test through the form or the grid; `make qa` green.

## Data on the link (phase 4)

A link often carries fields of its own: `user_id`, `tag_id`, `created_at`, `type`. The plain link
table already records `created_at` and `created_by`; anything else needs metadata, and metadata
is what objects are for. So instead of giving link tables fields of their own (a second, smaller
copy of the object machinery), a `many_to_many` can go **through a junction object**:

```json
{"target": "tag", "through": "user_tag", "throughSource": "user", "throughTarget": "tag"}
```

- `user_tag` is an ordinary object: `user` and `tag` are `relation` fields (typically `cascade`),
  `type` is a `select`, and any other field type works. It gets its own grid, views,
  permissions, translations and lifecycle with no new code.
- The `many_to_many` field on `user` creates no link table: its links are the live records of
  `user_tag`. `onDelete` is not a setting of the field then: the `relation` fields of the
  junction object carry the rules.
- Values: a list of rows, `[{"tag": "<id>", "type": "owner"}, …]`; the form edits them as inline
  rows (the target picker plus the junction's fields), reusing the composite form's `mode: many`
  `inline` rows. A row removed is a junction record deleted, a row added is one created, all in
  the record's transaction.
- The grid cell lists the target labels; the filter becomes `EXISTS` on the junction object's
  live records.
- **Prerequisite: a unique constraint on several fields** (phase 4a, below): the junction object
  needs `(user, tag)` unique (or `(user, tag, type)`, if the same tag may be linked twice with
  different types). Decided by the user: it counts live records only (a partial index `WHERE
  deleted_at IS NULL`), so a junction record removed (soft deleted) and added back is not
  refused; single-field uniques keep counting deleted records.
- `through`, `throughSource` and `throughTarget` are fixed after creation. Converting a plain
  `many_to_many` into one with `through` (copying its links into the junction object) is not
  planned.

What phases 1–3 must do to keep phase 4 cheap: the record store reads and writes links through
one "link source" abstraction (table, source column, target column, soft-delete column or none),
so that the plain link table and a junction object's table are two implementations of it, not
two code paths in the store, the grid source and the validator.

Without phase 4, the same model works today with a junction object and a `mode: many` composite
form section; what phase 4 adds is the field on `user` (grid column, filter, compact editing) and
the composite unique.

**Phase 2, as delivered.** The skips of phase 1 are gone from the Record and Validation
modules, except in the grid (phase 3). Differences from the design above:

- Read order: oldest link first, then by target id, not by label. `created_at` has a precision
  of one second, so links made together come back by id: the order carries no meaning, and the
  tests compare sets.
- The store has one new method, `replaceLinks()`; `referencingIds()`, `countReferencing()` and
  `clearReferences()` accept a many-to-many field too (for the last one, it removes the links),
  so the inverse lists and the delete rules read both kinds the same way. Links are read through
  `LinkSource` (`RecordTable::links()`), the shape phase 4 will implement a second time.
- `ReferenceCheckerInterface::missing()` checks every linked id in one query.
- The form uses the ids as choices, with a `choice_label`: two linked records sharing a label must
  both stay chosen, which a label-keyed choice list would lose.
- `created_by` of a link stays null, as it does on records: nothing sets it yet.
- The link table naming moved to `App\Shared\Database\DataSchema::linkTable()`, shared by the
  Schema and Record modules.

**Phase 3, as delivered.** The last skips of phase 1 are gone: `RecordGridFactory` builds a
column for the field, `GridViewCompiler::readable()` accepts it. Differences from the design:

- The source selects two correlated subqueries per field, both on `LinkSource`: the labels
  (`STRING_AGG(label, ', ' ORDER BY label, id)`) under `<field>__label`, and the ids in the same
  order, hydrated as the field's value (a renderer gets them). A target with an empty label is
  named `#<first 8 characters of its id>` in SQL, as `RecordLabels::fallback()` does in PHP.
  The labels are joined in SQL, so a label holding ", " reads like two.
- The filter accepts `eq` and `in` only (what the relation filter sends); a value that is not a
  UUID is dropped before the query, and a filter left with none matches nothing. Other
  operators, a sort, a search or a virtual column on the field are refused by the source with an
  `InvalidArgumentException`, as the grid view compiler refuses them on save
  (`sortable: true` on the column, a `sort` on it, a `{token}` of a virtual column).
- The bundle's columns are sortable unless told otherwise: the default grid now calls
  `sortable(false)` for a many-to-many column, and for a file column too (it was sortable on its
  id, against the comment saying it was not).
- No gridview-bundle change.

**Phase 4a, as delivered** (`feature/composite-unique`, its own branch as the user chose):

- `ObjectDefinition::$uniqueTogether` (`core.metadata_object.unique_together`, JSONB, null for
  none): a list of field-name lists, in the order of the index, rewritten by a field rename.
  `ObjectDefinitionDto::$uniqueTogether`; snapshot cache key `v11`.
- `metadata:object:unique <object> <fields> [--remove]`, `MetadataManagerInterface::
  addUniqueTogether()` / `removeUniqueTogether()`, the admin section "Unique together". Refused:
  fewer than two different fields, a set already there (any order), a password, a file, a
  `many_to_many` field; and while live records break the rule, counted through
  `DuplicateRecordsInterface` (Metadata Contract, implemented by Record), so the error is readable
  instead of a failed `CREATE UNIQUE INDEX`.
- The indexes are planned by `UniqueTogetherIndexPlanner` (Schema), like the trigram indexes:
  named `uniqc_<table>__<column>__<column>`, hidden from the DBAL comparison (which does not model
  the predicate and would never drop them), dropped and built again when a set changes or a field
  or the object is renamed.
- Records: `UniqueValueCheckerInterface::isTakenTogether()`; a field of the set missing from an
  update is compared with the stored value through a subquery. The error goes on the first field
  of the set the write carries. A concurrent write is refused by the index, and `RecordManager`
  turns the conflict into the same error, as for single uniques.
- A field in a set cannot be archived (`MetadataUsages`).

**Decisions for 4b (user, 2026-11-04):** two branches (4b-1 reading, 4b-2 writing the rows);
a row of the value is identified by the id of its junction record (with an id: update; without:
create; missing: delete), as composite `mode: many` rows are; a `through` field requires the
junction to keep `throughSource` and `throughTarget` unique together, in a set of their own or
with other fields.

**Phase 4b-1, as delivered** (`feature/many_to_many_through`):

- `ManyToManySettings` gained `through`, `throughSource`, `throughTarget` (all three or none,
  fixed) and `goesThrough()`; with them, `onDelete` must stay at its default: the junction's
  relation fields carry the delete rules, and `RecordManager` skips the field's own rules.
  `CreateFieldHandler` checks the junction (an object of its own, active, two relation fields
  pointing at the object and the target, a unique set holding both).
- No link table (`TableDefinitionFactory`, `objectNames()`, `dropColumn()`).
- `LinkSource` gained the soft-delete column of the links (`andLive()`) and `isJunction`;
  `RecordTable::links()` builds it on the junction's table. The store's reads (`linksOf()`,
  `pointsAt()`) and the grid (`linkedFrom()`) keep the live links only; `replaceLinks()` and
  `clearReferences()` refuse a junction.
- Value as for a plain field: the list of the linked ids (not rows yet). The denormalizer refuses
  the field in a write ("These links are records of ...") and never defaults it; the record form
  and composite forms show it disabled, and their submitted data leaves it out. It cannot be
  required (until 4b-2).
- Lifecycle: `MetadataUsages::throughFields()`; the junction object, its two fields and the unique
  set holding them are in use (archive, purge, unique removal refused); renames follow
  (`retargetRelations()`, `followTargetFieldRename()`); restore checks the junction.

Not covered by a test in 4b-1: a `through` field inside a composite form; covered in 4b-2
(`ManyToManyThroughControllerTest::testACompositeFormEditsTheRowsToo()`).

### Phase 4b-2, as delivered

On `feature/many_to_many_through_rows`, a branch from main. Decided by the user before the code
(2026-11-04): the value keeps the ids, the rows come apart; the permissions on the junction
decide what is shown, as below.

- **Value shape.** `RecordOutput::$values` keeps the linked ids for every many-to-many field, so
  the grid, the renderers and the conditions read them unchanged. `RecordOutput::$rows` (and
  `rows()`, `withRows()`) holds, for each `through` field, the junction's live records, oldest
  first; only the records read one by one carry them (`find()`, `findReferencing()`, the record a
  write returns), not `latest()` nor the grid. In `RecordInput` a `through` field takes rows:
  maps of the junction's fields with an optional `id`, keys kept to name the rows in the errors.
- **Write pipeline.** `RecordInputDenormalizer` checks the shape only (a list of maps, a UUID
  `id`; `ROWS_MESSAGE`, `ROW_ID_MESSAGE`); no rows is null, so `required` and `max` (the type's
  `Count`) count the rows as a plain field counts its ids, and a create without the field gets
  null. `RecordManager::checkRows()` checks each row through `check()` as a junction record (the
  field pointing at the record is left out, as composite forms leave out `via`), plus an unknown
  row (`UNKNOWN_ROW_MESSAGE`), the record's field given (`SET_BY_RECORD_MESSAGE`) and a target
  twice (`LINKED_TWICE_MESSAGE`); errors are keyed `tags.<row key>.<field>`. `writeRows()` runs
  in the record's transaction, after the record: deletions first (the junction's delete rules
  apply; a restricted one becomes an error on the field), then updates and creates through
  `update()` / `create()`, their errors re-keyed by row. `CreateFieldHandler` accepts a required
  `through` field.
- **Form.** `JunctionRowsType` (a `CollectionType`, block prefix `junction_rows`) of
  `JunctionRowType` (hidden `id` plus `values`, a `RecordFieldsType` of the junction's fields,
  the target first; its data is the flat row, through a model transformer), drawn by the
  `junction_rows` / `junction_row` blocks of `templates/form/record_fields.html.twig` as the
  bundle's `gridview-collection` inline rows. A blank new row is dropped (`delete_empty`).
  `RecordFormFactory::data()` puts the rows in the form data, `errorTarget()` maps `tags.0.role`
  to its input (skipping the row's `values` level), and composite forms use both.
- **Permissions** (`JunctionAccess`): read, create, update and delete on the junction: the rows,
  editable; read only: the rows, disabled, left out of what the record form saves
  (`RecordController::submitted()` skips disabled controls) and of a composite form's input
  (`RecordFormFactory::editsRows()`); no read: the labels, disabled, as in 4b-1. The record
  manager itself checks no permission, as for any write.
- Not done: a swap of targets between two existing rows in one write may hit the junction's
  unique index on the first update and come back as an error; the check cannot see it. Rows are
  not ordered beyond "oldest first".

### Working on it

- Worktree used for phase 4: `../metadata-driven-m2m` (see "Sessions working in parallel" in
  `status.md`): its `.env.test.local` sets `TEST_TOKEN=_m2m`, database `app_test_m2m`.
- Tests of the phase: `tests/Integration/Module/Record/ManyToManyThroughTest.php`,
  `tests/Functional/Module/Record/ManyToManyThroughControllerTest.php`,
  `tests/Integration/Module/Metadata/UniqueTogetherTest.php`.

## Out of scope

- Editing the links from the target's side: the inverse stays read-only (user's decision).
- Ordered links, symmetric self-relations (A related to B implies B related to A).
- Cascading selects on a many-to-many.

## Decisions

- Type name: `many_to_many`.
- The inverse side is read-only.
- `onDelete`: `unlink` (default) and `restrict`.
- Search on the linked labels: undecided, not in phases 1–3; it can be added later with an
  `EXISTS` on the target's label.
- Data on the link: phase 4, through a junction object (proposal, to confirm when phase 3 is done).
