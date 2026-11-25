# Plan: the `tags` field type

Status: **in progress**. Phase 1 done on 2026-11-24, with the form of phase 3 (gridview's tags
control, released in gridview 1.9.0) and the `tags` entry of the package format (released in 2.5.0, its kind
`strings` for `options`), which the app's catalog test requires. Phase 2 done the same day: the
cell is a badge per label (drawn by `RecordGridFactory`, as gridview's `badge` column renders one
value), with a hidden ", " between them for the export; the filter is a multiple relation filter
on static choices, a text filter past 200 labels in use; a tags field is searchable
(`FieldTypeInterface::isSearchable()`). Phase 3 done on 2026-11-25: `GET
/o/{object}/tags/{field}?query=` lists the labels in use containing the text (20 at most, the
object's read permission), given to the control as its `url` unless `restrict`. Next: the CSV
import and seeds of phase 4. Written and decided on 2026-11-23, after comparing the package format with
a Directus schema snapshot: Directus has a `json` field with the `tags` interface (free labels on
a record, `["urgent", "client-x"]`), and metadata-driven has nothing to map it to.

A field type holding a short list of free text labels on a record: a project's tags, a
contact's interests, a ticket's keywords. The labels belong to the record. There's no table of
tags to manage, and a new label is typed right in the form.

## Why not `many_to_many` to a tag object

That already works, and it stays the right choice for a **managed vocabulary**: tags with data of
their own (a colour, a description), renamed once for every record, with permissions on who adds
one. The `tags` type is for the lighter case:

| | `tags` | `many_to_many` to a `tag` object |
|---|---|---|
| A new label | Typed in the form | Needs a `tag` record first |
| Renaming a label everywhere | An update on every record holding it | One record |
| Storage | One `jsonb` column | A link table and a second object |
| Data on a label | None | Any field |
| Package / Directus import | A field | An object, its records (a seed), and a field |

A generic `json` type is **out of scope**: an arbitrary JSON document has no form control, no
filter, and no validation worth the name, and in a metadata-driven app it would be a textarea of
text nobody can query.

## Decisions

All four decided by the user on 2026-11-23, as recommended.

1. **Storage.** One `jsonb` column holding an array of strings. metadata-driven runs
   on PostgreSQL only, so the column is portable to every instance, and `jsonb` gives the
   containment operators and a GIN index. Rejected: a link table of
   `(record_id, position, value)` rows like a `multiple` file field, is heavier for no gain on
   PostgreSQL.
2. **Case.** Labels are **kept as typed** and compared case-insensitively: `Urgent`
   and `urgent` on the same record are one label (the first spelling wins), and a filter on
   `urgent` finds both. Rejected: lowercasing every label on save, which makes the filter a plain
   `@>` that uses the GIN index, but changes what the user typed.
3. **Vocabulary.** An optional `options` list that the input **suggests**, plus a
   `restrict` setting that makes it the only allowed values. With `restrict`, the field is close to
   a multiple `select`. That's intended: a multiple `select` would be this same type, so there's no
   separate one.
4. **Where the form control lives.** The controls of
   metadata-driven are to be exactly those of `fedale/gridview-bundle`. The tags control is
   therefore built **in gridview first**, as `ControlType::Tags` (enum case, `ControlTypeRegistry`
   entry, fluent builder, a `TagsType` form type, a `gridview-tags` Stimulus controller), and
   metadata-driven's `RecordFormFactory` uses it. Gridview has no such control today: its `badge`
   and `list` are grid column types, read-only. Aligning the other controls (today
   `RecordFormFactory` maps `WidgetKind` to Symfony form types on its own; `relation`,
   `many_to_many` and `file` differ most) is a separate plan:
   [controls-alignment-plan.md](controls-alignment-plan.md). The tags control builds on its
   phase 1 (`ControlBuilder`).

## The type

`TagsFieldType` in `FieldType/Domain/Type`, name `tags`, label "Tags".

| Setting | Kind | Default | Description |
|---|---|---|---|
| `options` | string list | `[]` | Labels the input suggests, in this order |
| `restrict` | boolean | `false` | Only the labels of `options` are allowed. Requires a non-empty `options` |
| `max` | integer | null (no limit) | The most labels a record holds, 1 to 100 |
| `maxLength` | integer | `50` | The longest label, 1 to 100 characters |

- `validateSettings()`: `options` is a list of non-empty strings, each at most `maxLength` long,
  with no case-insensitive duplicates. `restrict` without `options` is refused.
- `denormalize()`: accepts a list of strings, or a single string that it splits on commas (the
  form's fallback without JavaScript, and an API client sending `"a, b"`). It trims each label,
  collapses inner whitespace and drops empty labels. It removes case-insensitive duplicates and
  keeps the first spelling, and keeps the order. An empty list becomes null, following the
  "empty input is null" rule.
- `constraints()`: `All([Length(max: maxLength)])`, `Count(max: max)`, and with `restrict`
  `Choice(choices: options, multiple: true)` (case-insensitive: the value is mapped onto the
  spelling of `options` in `denormalize()` first).
- `columnSpec()`: a new `ColumnType::Json` case in `App\Module\FieldType\Contract\Dto\ColumnType`
  (the physical column type, DBAL names; not the grid column types of `fedale/gridview-bundle`,
  an unrelated enum with the same short name). The schema synchronizer creates it as `jsonb`
  (the `jsonb` platform option the ORM mappings of `ObjectDefinition` and others already use).
- `widget()`: a new `WidgetKind::Tags`, options `suggestions` (the `options`), `restrict`, `max`,
  `maxLength`.
- `defaultValue()`: null. A default list is out of scope.
- `isSortable()`: false (a set has no order of its own). `supportsUnique()`: false, so a tags
  field can't be in a `unique_together` set either.
- Not usable as `label_field`, like `many_to_many`.

`SettingKind` gets a `StringList` case for `options`. `OptionList` is `{value, label}` pairs and
doesn't fit. The admin's settings editor renders it as one label per line.

### Changing settings

Following [field-types.md, Changing settings](../field-types.md#changing-settings):
`options`, `restrict`, `max` and `maxLength` can all change on an existing field. They check the
values written from then on: a stricter setting doesn't rewrite or refuse the records already
stored, and a record that no longer fits is refused on its next save of that field, with the
usual per-field message. No setting is `fixed`.

## Records

- In the record arrays (callbacks, workflows, API), the value is a `list<string>` or null.
- Write: the `RecordManager` path is unchanged. The column takes the encoded array (DBAL `json`
  type).
- Conditions (`condition: {field, values}`) on a tags field: **out of scope** for the first
  version. A condition is refused when its `field` is a tags field, as for `many_to_many`.

## Grid

- **Column.** `RecordGridFactory` maps `WidgetKind::Tags` to a gridview `badge` column (one badge
  per label). The `list` column type is the fallback if `badge` doesn't render a list. Check this
  in phase 2.
- **Filter "has the tag"**, in `DbalRecordGridSource`, as an `applyTagsCriterion()` next to
  `applyLinkCriterion()`. Operators:
  - `Eq`: the record holds this label.
  - `In`: the record holds any of these labels.
  - `Eq` with `true` / `false`: holds at least one label / none (the `null` check, as for files).

  With the case-insensitive decision, the SQL is
  `EXISTS (SELECT 1 FROM jsonb_array_elements_text(col) t WHERE lower(t) IN (:labels))`.
  **Don't use the `?` / `?|` operators**: PDO reads `?` as a positional placeholder. If labels are
  lowercased on save (decision 2), `col @> CAST(:label AS jsonb)` instead.
- **Filter choices.** The `options` when there are some. Without them, the labels in use:
  `SELECT DISTINCT t FROM <table>, jsonb_array_elements_text(col) t ORDER BY 1`, capped
  (say 200) with a text input past the cap. Through the same access rules as the grid query
  (workspace, `deleted_at IS NULL`).
- **Search.** `WidgetKind::Tags` joins `SearchConfiguration::TEXT_WIDGETS`, so the free-text
  search matches a record holding a label that matches the term. `searchMatch()` needs an
  `EXISTS … ILIKE` branch for it, as a `jsonb` column can't go through `asText()` meaningfully.
- **Export** (CSV, XLSX): the labels joined with `", "`.

## Form

- Gridview's `TagsType` (see decision 4), backed by a hidden or text input plus its
  `gridview-tags` Stimulus controller, using `gv-*` classes and `gridview.cls()` keys only. Enter or a comma adds a chip, Backspace on an empty input removes the last chip,
  and each chip has a remove button. Suggestions come from `options`, then from the labels in use
  (the same query as the filter choices, through a small JSON endpoint). With `restrict`, only the
  suggestions are accepted.
- **Without JavaScript**, the input is a comma-separated text field. A data transformer turns it
  into a list and back, so the form works before the controller connects and in tests.
- The controller cleans up its listeners in `disconnect()` and survives Turbo frame renders,
  like the other record form controllers.
- Strings go through the `messages` / `validators` domains, in every locale file.

## Import and packages

- **CSV import** (`RecordImporter`) and **CSV seeds**: labels separated by `|`, the existing
  `LIST_SEPARATOR` of `many_to_many`. The CSV writer of the seed export joins them the same way.
- **Inline YAML seeds**: a YAML list, `tags: [urgent, client-x]`.
- **Package format** (`fedale/metadata-driven-package-format`): add `tags` and its four settings
  to `field-types.v1.json`, and document it as "Since 2.5" in the README. Release 2.5.0.
  `VersionCheck` needs nothing new: a new field is a minor change, a settings change is a minor
  one. An instance older than the release refuses a package with a tags field, on the unknown
  type. That's the expected behaviour of a minor addition, but the message should name the type.
  Check that it does.
- **Package export**: nothing specific, the settings go out when they differ from the defaults.

## Adopting existing tables

`ColumnProposer` (database tables, see [database-tables-plan.md](database-tables-plan.md))
proposes `tags` for a `json` / `jsonb` column whose sampled values are all arrays of strings,
and `text` for any other JSON column, which is then read-only. Last phase, and it can be skipped.

## Phases (one branch each, docs in the same branch)

1. **The type.** `TagsFieldType`, `ColumnType::Json` → `jsonb` in the schema synchronizer,
   `WidgetKind::Tags`, `SettingKind::StringList`, the admin's type list (`TYPE_ORDER`) and
   settings editor, the record write path, the refusals (`label_field`, conditions, unique).
   The form uses the no-JavaScript text input. Docs: `field-types.md` (the summary table and a
   `## tags` section).
2. **Grid.** The badge column, the filter and its choices, the search, the export. Docs: the
   grid and search guides.
3. **The form control.** In `fedale/gridview-bundle` first (`ControlType::Tags`, `TagsType`,
   the `gridview-tags` Stimulus controller, its docs in `08_crud.md` and `agents.md`), released;
   then `RecordFormFactory` maps `WidgetKind::Tags` to it, and the suggestions endpoint.
4. **Import and packages.** CSV import and seeds, `field-types.v1.json`, package-format 2.5.0,
   the `composer.json` constraint of metadata-driven raised to it. Docs: `packages.md`.
5. **Adoption** of existing JSON columns (optional).

### Tests

- Unit: `TagsFieldType` settings validation and `denormalize()` (trimming, case-insensitive
  duplicates, comma string, empty list → null, `restrict` mapping onto the spelling of
  `options`).
- Integration, against PostgreSQL: the column is `jsonb`. The filter for `Eq`, `In`, holds-any and
  holds-none, case-insensitive, and the soft-deleted records left out of the choices. The search
  matches a label.
- Functional: create and edit a record with tags through the form, with and without the
  controller. Install a package with a tags field and its seed, and upgrade it.

## Not in scope

- A generic `json` field type.
- Conditions on a tags field, and a default list of labels.
- Renaming a label across every record (a bulk action, later if asked for).
- A Directus snapshot importer. This type is a prerequisite of one: `interface: tags` maps to
  `tags`, a Directus m2m to `many_to_many`.
- `ControlType::Tags` in `fedale/gridview-bundle` (see decision 4).
