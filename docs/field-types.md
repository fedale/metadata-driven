# Field types

A field's type decides its column, how input is converted, which rules validate it, the form
control and the grid column. Settings are passed as JSON to `metadata:field:create --settings`;
missing keys take their default, unknown keys are refused.

| Type | Settings (default) | Column | Form control | Grid filter |
|---|---|---|---|---|
| `text` | `maxLength` (255), `multiline` (false), `pattern`, `patternMessage` | `varchar(maxLength)`, or `text` when multiline or longer than 255 | text input / textarea | text |
| `number` | `decimals` (0), `min`, `max` | `integer`, or `numeric(18, decimals)` | number input | number |
| `boolean` | `default` (false), `display` (`checkbox`) | `boolean` | checkbox or switch | yes / no |
| `date` | `includeTime` (false) | `date`, or `timestamp(0)` | date / date-time input | date range |
| `email` | none | `varchar(254)` | email input | text |
| `tel` | none | `varchar(30)` | phone input | text |
| `url` | none | `varchar(2048)` | URL input | text (shown as a link) |
| `select` | `options` (required), `default`, `display` (`dropdown`) | `varchar(100)` | select or buttons side by side | choice |
| `password` | `minLength` (8), `requireMixedCase`, `requireDigit`, `requireSymbol`, `confirm` (all false) | `varchar(255)`, a hash | password input, asked twice with `confirm` | none: never shown |
| `relation` | `target` (required), `onDelete` (`set_null`), `inverseName`, `inverseLabel` | `uuid` + foreign key | autocomplete | choice of records |

Settings added to a type after a field was created take their default on that field: a boolean
created before `display` existed is a checkbox.

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
- **Default values**: on create, a field that is not given takes its default value (`boolean` and
  `select` have a `default` setting), and the form starts with it. A field given empty stays empty.

## `text`

| Setting | Type | Rule |
|---|---|---|
| `maxLength` | int | 1 to 10000 |
| `multiline` | bool | |

- Input is trimmed, unless `multiline` (indentation and blank lines are kept).
- Values longer than `maxLength` are refused.
| `pattern` | string | a PCRE regular expression, at most 500 characters |
| `patternMessage` | string | the error shown when the value does not match |

- Searched by default (see `metadata:object:search`).
- `pattern` must match the **whole** value, as an HTML `pattern` attribute does: `\d{5}` accepts
  `00184`, not `001845`. Without `patternMessage` the error is "This value is not valid.". An
  invalid expression is refused when the field is created.

```json
{"maxLength": 80}
{"multiline": true}
{"maxLength": 5, "pattern": "\\d{5}", "patternMessage": "A postal code has five digits."}
{"maxLength": 2, "pattern": "[A-Z]{2}", "patternMessage": "Two capital letters, e.g. MI."}
```

A postal code is a text with a pattern, not a number: a number would drop the leading zeros.

## `number`

| Setting | Type | Rule |
|---|---|---|
| `decimals` | int | 0 to 10 |
| `min` | int or null | the smallest value allowed |
| `max` | int or null | the largest value allowed; not below `min` |

- `decimals: 0` stores an integer (range ±2147483647); more decimals store an exact decimal
  (precision 18), read back as a numeric string (`"12.50"`), never as a float.
- Input: an int, a float, or a string such as `"12.5"` (dot as separator). Too many decimals are
  refused, never rounded: "This value should have at most 2 decimal places."; `"3.5"` on an integer
  field: "This value should be a whole number.".
- `min` / `max` are checked on save ("This value should be between 1900 and 2100.") and set the
  bounds of the form input.
- Grid filter syntax: `10`, `>10`, `<=7`, `5..20`.

```json
{"min": 1900, "max": 2100}
{"decimals": 1, "min": 0}
```

## `boolean`

| Setting | Type | Rule |
|---|---|---|
| `default` | bool | true: new records start checked |
| `display` | string | `checkbox` or `switch` (an on/off toggle) |

- Input: `true`/`false`, `1`/`0`, `"on"`/`"off"`, `"yes"`/`"no"`, `"true"`/`"false"`.
- The form checkbox always sends a value: unchecked is `false`, not null.
- Without `default`, a record created without the field (from code) stores null, as before the
  setting existed.

```json
{"default": true, "display": "switch"}
```

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

## `tel`

- Trimmed; runs of spaces become one space (`"+39  333\t123"` is stored `"+39 333 123"`).
- Digits, an optional leading `+`, spaces, dots, dashes, slashes and brackets: `+39 333 123 4567`,
  `(02) 1234-567`. "This value is not a valid phone number." otherwise.
- 4 to 15 digits (the E.164 maximum), at most 30 characters.
- Searched by default.

## `url`

- Trimmed, at most 2048 characters.
- Only `http` and `https` addresses with a domain are accepted: `www.example.com` (no scheme) and
  `javascript:...` are refused with "This value is not a valid URL.".
- The grid shows it as a link. Searched by default.

## `select`

| Setting | Type | Rule |
|---|---|---|
| `options` | list of `{value, label}` | not empty; `value` non-empty, at most 100 characters, unique; `label` non-empty |
| `default` | string or null | the value of one of the options; new records start with it |
| `display` | string | `dropdown` (default) or `radio`: the options as buttons side by side, for a few short options |

```json
{"options": [{"value": "dog", "label": "Dog"}, {"value": "cat", "label": "Cat"}]}
{"options": [{"value": "m", "label": "Male"}, {"value": "f", "label": "Female"}], "display": "radio"}
{"options": [{"value": "mobile", "label": "Mobile"}, {"value": "home", "label": "Home"}], "default": "mobile"}
```

- The value is stored; the label is shown in forms, grid cells and exports.
- A value outside the options is refused: "The value you selected is not a valid choice.".
- Filters and sort work on the stored value.

## `password`

A secret, checked as typed and stored as a one-way hash (the `record_secret` hasher of
`security.yaml`: bcrypt or argon2).

| Setting | Type | Rule |
|---|---|---|
| `minLength` | int | 1 to 128 (default 8) |
| `requireMixedCase` | bool | an upper and a lower case letter |
| `requireDigit` | bool | a digit |
| `requireSymbol` | bool | a character that is neither a letter nor a digit |
| `confirm` | bool | the form asks twice and checks both match |

```json
{"minLength": 8, "requireMixedCase": true, "requireDigit": true, "requireSymbol": true, "confirm": true}
```

- **Never read back.** The hash stays in the column: records, forms, grids, exports, search and
  labels never see it. The edit form leaves the input empty ("Leave empty to keep the current
  password.").
- **Empty on update keeps it**, even when the field is required; given, it replaces it. On
  create, a required password must be given.
- **Spaces count**: a password is not trimmed; only a blank one is empty.
- **The confirmation is a form-only field.** With `confirm`, the form shows *Password* and
  *Repeat password* and refuses "The two passwords do not match."; only one value reaches the
  record, and nothing of the second input is stored. Code writing a record directly gives the
  password once.
- A password cannot be **unique**, **searched** (`metadata:object:search`) or the **label** of
  the records (`metadata:object:label`): the hash is salted, and a search or a sort on it would
  leak it. These are refused when configured, and the grid refuses to filter or sort on it.

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
