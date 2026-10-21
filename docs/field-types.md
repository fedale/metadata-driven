# Field types

A field's type decides its column, how input is converted, which rules validate it, the form
control and the grid column. Settings are passed as JSON to `metadata:field:create --settings`;
missing keys take their default, unknown keys are refused.

| Type | Settings (default) | Column | Form control | Grid filter |
|---|---|---|---|---|
| `text` | `maxLength` (255), `multiline` (false) | `varchar(maxLength)`, or `text` when multiline or longer than 255 | text input / textarea | text |
| `number` | `decimals` (0) | `integer`, or `numeric(18, decimals)` | number input | number |
| `boolean` | none | `boolean` | checkbox | yes / no |
| `date` | `includeTime` (false) | `date`, or `timestamp(0)` | date / date-time input | date range |
| `email` | none | `varchar(254)` | email input | text |
| `select` | `options` (required) | `varchar(100)` | select | choice |
| `relation` | `target` (required), `onDelete` (`set_null`), `inverseName`, `inverseLabel` | `uuid` + foreign key | autocomplete | choice of records |

Every field column is nullable: "required" is a validation rule (see below).

## Rules common to every type

- **Empty input is null.** An empty or blank string becomes null before validation.
- **Required** (`--required`): null is refused with "This value is required.". On update, only the
  fields sent are checked: a required field left out keeps its value; set to null, it is refused.
- **Unique** (`--unique`): a value used by another record is refused with "This value is already
  used.". A unique index backs the rule against concurrent saves. Values of deleted records stay
  reserved (the index still holds them).
- **Errors are reported all at once**, per field, in the form and in `RecordValidationFailed` for code.
- **Unknown fields** in the input are refused ("This field does not exist.").

## `text`

| Setting | Type | Rule |
|---|---|---|
| `maxLength` | int | 1 to 10000 |
| `multiline` | bool | |

- Input is trimmed, unless `multiline` (indentation and blank lines are kept).
- Values longer than `maxLength` are refused.
- Searched by default (see `metadata:object:search`).

```json
{"maxLength": 80}
{"multiline": true}
```

## `number`

| Setting | Type | Rule |
|---|---|---|
| `decimals` | int | 0 to 10 |

- `decimals: 0` stores an integer (range ±2147483647); more decimals store an exact decimal
  (precision 18), read back as a numeric string (`"12.50"`), never as a float.
- Input: an int, a float, or a string such as `"12.5"` (dot as separator). Too many decimals are
  refused, never rounded: "This value should have at most 2 decimal places."; `"3.5"` on an integer
  field: "This value should be a whole number.".
- Grid filter syntax: `10`, `>10`, `<=7`, `5..20`.

## `boolean`

- Input: `true`/`false`, `1`/`0`, `"on"`/`"off"`, `"yes"`/`"no"`, `"true"`/`"false"`.
- The form checkbox always sends a value: unchecked is `false`, not null.

## `date`

| Setting | Type | Rule |
|---|---|---|
| `includeTime` | bool | |

- Without time: `"2024-02-29"`; the time of a date-time input is dropped.
- With time: `"2024-02-29T13:45"` (HTML input), `"2024-02-29 13:45[:00]"` or ISO 8601 with offset.
- Impossible dates are refused ("2026-02-31" is not rolled over to March).
- Grid filter: a date range picker (flatpickr); the upper bound includes the whole day.

## `email`

- Trimmed, at most 254 characters, validated with Symfony's `Email` constraint.
- Searched by default.

## `select`

| Setting | Type | Rule |
|---|---|---|
| `options` | list of `{value, label}` | not empty; `value` non-empty, at most 100 characters, unique; `label` non-empty |

```json
{"options": [{"value": "dog", "label": "Dog"}, {"value": "cat", "label": "Cat"}]}
```

- The value is stored; the label is shown in forms, grid cells and exports.
- A value outside the options is refused: "The value you selected is not a valid choice.".
- Filters and sort work on the stored value.

## `relation`

A many-to-one link: the field stores the id of one record of the target object. The target sees
the other side (one-to-many) read-only: its edit page lists the records pointing at it.

| Setting | Type | Rule |
|---|---|---|
| `target` | string | an existing object (the same object for a hierarchy, e.g. a manager) |
| `onDelete` | string | `set_null` (default), `restrict` or `cascade` |
| `inverseName` | string or null | machine name of the inverse side; must not be a field or another inverse name of the target |
| `inverseLabel` | string or null | its title on the target's page; default "<Plural label> (<Field label>)" |

```json
{"target": "contact", "onDelete": "restrict", "inverseName": "pets", "inverseLabel": "Pets"}
```

What happens when the **target record is deleted**, per `onDelete`:

| Rule | Effect |
|---|---|
| `set_null` | The field is cleared on the records pointing at it. |
| `restrict` | The deletion is refused while live records point at it: "This record cannot be deleted: 2 Invoices (Customer) still refer to it." Nothing changes. |
| `cascade` | The records pointing at it are deleted too, applying their own rules in turn. Cycles (two records pointing at each other) terminate. |

Every rule of a deletion runs in one transaction: all or nothing. Records are soft deleted, so the
rules are applied by the application; the foreign key carries the same rule for the day rows are
purged physically.

Input and display:

- Input is a record id (UUID, any case). An id that is not a UUID is refused ("This value is not a
  valid record reference."), and so is the id of a missing or deleted record ("The selected record
  does not exist.").
- Records are shown by their label: the object's label field, chosen with `metadata:object:label`,
  or its first text field.
- The form offers an autocomplete over the target's records; the grid shows the label, sorts on it,
  and filters on one or several related records.
- Many-to-many relations are not available yet.
