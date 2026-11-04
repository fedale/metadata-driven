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
| `password` | `minLength` (8), `requireMixedCase`, `requireDigit`, `requireSymbol`, `confirm`, `strengthMeter`, `revealToggle` (all false) | `varchar(255)`, a hash | password input, asked twice with `confirm` | none: never shown |
| `file` | `accept` (any type), `maxSize` (10 MB), `image` (false) | `uuid` + foreign key to `core.file` | the current file, an upload input, "Remove the file" | yes / no: holds a file or none |
| `relation` | `target` (required), `onDelete` (`set_null`), `inverseName`, `inverseLabel` | `uuid` + foreign key | autocomplete | choice of records |
| `many_to_many` | `target` (required), `onDelete` (`unlink`), `inverseName`, `inverseLabel`, `max` | none: a link table of its own | autocomplete, several records | choice of records (any of them) |

Settings added to a type after a field was created take their default on that field: a boolean
created before `display` existed is a checkbox.

Every field column is nullable: "required" is a validation rule (see below). A `many_to_many`
field has no column: its links live in a table of their own (see [`many_to_many`](#many_to_many)).

## Rules common to every type

- **Empty input is null.** An empty or blank string becomes null before validation.
- **Required** (`--required`): null is refused with "This value is required.". Forms mark the
  label with a red asterisk. A field with a condition is required only while it applies. On
  update, only the
  fields sent are checked: a required field left out keeps its value; set to null, it is refused.
- **Unique** (`--unique`): a value used by another record is refused with "This value is already
  used.". A unique index backs the rule against concurrent saves. Values of deleted records stay
  reserved (the index still holds them).
- **Unique together** (`metadata:object:unique`): a set of fields of an object no two records may
  share all the values of, e.g. a person and a team on a membership. A record holding the same
  values as another one is refused with "Another record already has the same person and team.",
  on the first field of the set the write carries; an update carrying one field of the set is
  checked with the stored value of the others. Unlike a single unique field, **deleted records
  do not count**: their values are free again, so a membership removed can be added back. A
  record with one of the fields empty never clashes. A partial unique index (`WHERE deleted_at IS
  NULL`) backs the rule. The fields may be of any type a single field could be unique on: not a
  password, a file nor a `many_to_many` field.
- **Errors are reported all at once**, per field, in the form and in `RecordValidationFailed` for code.
- **Unknown fields** in the input are refused ("This field does not exist.").
- **Default values**: on create, a field that is not given takes its default value (`boolean` and
  `select` have a `default` setting), and the form starts with it. A field given empty stays empty.
- **Conditions**: a field may apply only while another field of the object holds some values
  (see below).

## Changing settings

The settings of an existing field can change, from its admin page (section **Settings**) or with
`metadata:field:settings`, except those that shape the column or the foreign key: they are
**fixed** once the field exists, shown disabled in the admin and refused otherwise.

| Type | Fixed | Free to change |
|---|---|---|
| `text` | `maxLength`, `multiline` | `pattern`, `patternMessage` |
| `number` | `decimals` | `min`, `max` |
| `date` | `includeTime` | — |
| `boolean` | — | `default`, `display` |
| `select` | — | `options`, `default`, `display` |
| `password` | — | every setting |
| `file` | — | every setting (they check the files uploaded from then on) |
| `relation` | `target`, `onDelete`, `inverseName` | `inverseLabel`, `dependsOn`, `dependsVia` |

- **Options** of a `select` can be added, relabelled and reordered at will. One can be removed
  only when no record holds its value and no [condition](#conditions) names it; otherwise the
  change is refused with the reason ("1 record(s) hold it").
- A new rule (a pattern, a minimum) applies to the values saved from then on: the records
  already stored are not checked again.
- A cascade (`dependsOn` / `dependsVia`) is checked as when the field is created.

## Conditions

A field can apply only while another field of the same object holds one of some values: the
hours of a qualification while its kind is "course", the level while it is "language".

```bash
symfony console metadata:field:condition qualification hours --when=kind --in=course
symfony console metadata:field:condition qualification level --when=kind --in=language
symfony console metadata:field:condition qualification hours            # always again
```

| While the condition is… | The field is… |
|---|---|
| met | shown, and required if it is a required field |
| not met | hidden in every form (it reappears as the deciding field changes, row by row in a composite form), not required, and **emptied** when the record is saved |

- The deciding field is a `select` (its option values) or a `boolean` (`true` or `false`) of the
  same object. Any other type is refused, and so is a value the field never holds.
- Conditions do not chain: the deciding field cannot have a condition itself, and a field that
  decides for others cannot get one.
- Emptied means emptied: a course that becomes a language loses its hours when it is saved, so
  hidden values never linger in the table. On an update that leaves the deciding field out, its
  stored value decides.
- A form's own `required` list ([composite forms](forms.md#required-fields)) follows the
  conditions too.
- The condition can be set and changed at any time, from the admin (the field's page, section
  **Condition**) or the console. It changes no column.

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
| `strengthMeter` | bool | a meter under the input shows how strong the password is while it is typed |
| `revealToggle` | bool | an eye in the input shows the password in clear, and hides it again |

```json
{"minLength": 8, "requireMixedCase": true, "requireDigit": true, "requireSymbol": true, "confirm": true, "strengthMeter": true, "revealToggle": true}
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
- **The strength meter is a hint.** Four bars and a word (*Too short*, *Weak*, *Fair*, *Good*,
  *Strong*), computed in the browser: below `minLength` it is *Too short*; then each kind of
  character beyond the first (lower case, upper case, digit, symbol) and every 4 characters beyond
  `minLength` add a level. It decides nothing: the rules above are what the server checks. It
  watches the password typed first, never its confirmation.
- **The eye** switches the input between hidden and clear text (*Show password* / *Hide
  password* for screen readers). Like the meter, it is on the password typed first; leaving the
  page always hides it again.
- A password cannot be **unique**, **searched** (`metadata:object:search`) or the **label** of
  the records (`metadata:object:label`): the hash is salted, and a search or a sort on it would
  leak it. These are refused when configured, and the grid refuses to filter or sort on it.

## `file`

A document or an image uploaded with the record: a product's photo, its manual, a signed
contract.

| Setting | Type | Rule |
|---|---|---|
| `accept` | string | MIME types separated by commas, wildcards allowed: `application/pdf, image/*`. Empty (the default): any type |
| `maxSize` | int | the largest file, in MB, from 1 to 100 (default 10). 1 MB is 1,000,000 bytes |
| `image` | bool | images only, checked to be real images (JPEG, PNG, GIF, WebP...); `accept` may then name image types only |

```json
{"accept": "application/pdf", "maxSize": 5}
```

- **The content decides the type**, not the name: a text file renamed `manual.pdf` is refused
  with "The mime type of the file is invalid ("text/plain"). Allowed mime types are
  "application/pdf".". `accept` also goes to the browser, so its file picker offers the right
  files first; that is a hint, the server checks every file.
- **The server's limits apply first.** PHP refuses an upload over `upload_max_filesize` or
  `post_max_size` before the field sees it; keep those above the largest `maxSize`.
- **The form** shows the current file (its name, a link to it, and its size; a preview for an
  image), an input to upload another one
  ("Choose a file to replace the current one."), and, when the field is not required, *Remove
  the file*. Saved without a new file, the record keeps the one it has. A required file can be
  replaced, never removed.
- **Stored files never change.** A new upload is a new file; the one it replaces stays in the
  storage (see [Architecture](architecture.md#two-schemas)). The column holds the id of a row of
  `core.file`, with a foreign key that refuses to delete a file a record still points at.
- **From code**, a new file is a `FileUpload` (the path of a readable local file and the name to
  show); the id of a stored file is accepted as the value the record already holds, and an id
  of no stored file is refused ("This file does not exist.").
- **Downloading** goes through `/files/<id>`, and only for a user who may read a record holding
  the file (`record.<object>.read`); anybody else, and a file held only by records in the bin,
  gets a 404, as for a file that does not exist. JPEG, PNG, GIF and WebP images open in the
  browser; every other file, an SVG included, is downloaded under its original name. The
  responses forbid scripts (`Content-Security-Policy: sandbox`, `X-Content-Type-Options:
  nosniff`), and browsers keep them for an hour at most, in their private cache.
- **Thumbnails** of an image, at `/files/<id>/thumbnail/small` (64 px) and `medium` (240 px), fit
  the image in a square of that side without enlarging it. They are made on the first request
  and kept with the file. An image over 40 megapixels, or one that cannot be decoded, has none
  (404); the file itself is still served.
- A file field cannot be **unique**: every upload is a new file. It is not searched.
- **In the grid** a cell shows the original name of the file as a link to it, after the small
  thumbnail of an image or a document icon. An image opens in a new tab, any other file is
  downloaded. The filter is a yes/no on whether the record holds a file; the column never sorts,
  and it cannot be joined in a [virtual column](views.md#virtual-columns). The export writes the
  original name.
- **Not yet**: a file field in a composite form section, and removing the files no record uses
  any more.

## `relation`

A many-to-one link: the field stores the id of one record of the target object. The target sees
the other side (one-to-many) read-only: its edit page lists the records pointing at it.

| Setting | Type | Rule |
|---|---|---|
| `target` | string | an existing object (the same object for a hierarchy, e.g. a manager) |
| `onDelete` | string | `set_null` (default), `restrict` or `cascade` |
| `inverseName` | string or null | machine name of the inverse side; must not be a field or another inverse name of the target |
| `inverseLabel` | string or null | its title on the target's page; default "<Plural label> (<Field label>)" |
| `dependsOn` | string or null | for a cascade: the relation field of this object to choose first (see below) |
| `dependsVia` | string or null | with `dependsOn`: the relation field of the target pointing at the same object as `dependsOn` |

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
- For a link to any number of records (a contact's tags), see [`many_to_many`](#many_to_many).

### Cascades (region, province, municipality)

A relation can depend on another relation field of the same object: only the records of the
target that point at the record chosen there can be picked. With `region`, `province` (pointing
at a region) and `municipality` (pointing at a province) as objects, a profile or a venue gets
three relation fields:

```bash
symfony console metadata:field:create venue region relation Region --settings='{"target":"region"}'
symfony console metadata:field:create venue province relation Province \
  --settings='{"target":"province","dependsOn":"region","dependsVia":"region"}'
symfony console metadata:field:create venue municipality relation Municipality \
  --settings='{"target":"municipality","dependsOn":"province","dependsVia":"province"}'
```

`dependsOn: region` names the venue's field to choose first; `dependsVia: region` the
province's field pointing at regions. They are checked when the field is created: `dependsOn`
must be a relation field of the object, created before, and `dependsVia` a relation field of the
target pointing at the same object.

- **The form** lists, in each field, the records of the one chosen before: the lookup is
  filtered (`/o/municipality/lookup?filter[province]=<id>`) and opens with the whole short list,
  without typing. Changing a field empties the ones depending on it, in turn (a new region empties
  the province and the municipality). Nothing is offered until the field before is chosen.
- **The server** refuses a record that does not belong to the one chosen before ("The selected
  record does not belong to the chosen region.") and a record chosen without it ("Choose the
  province first."), whatever the browser sent. On an update that writes one side only, the other
  is read from the record.
- Any depth and any data: Category → Subcategory → Product, Customer → Site → Contact.

The Italian regions, provinces and municipalities are public data (the ISTAT list of
municipalities, a CSV): there is no import command yet, so they are loaded with SQL.

## `many_to_many`

A link to any number of records of the target object: a contact has many tags, a tag has many
contacts. The target sees the other side read-only, as for a [`relation`](#relation).

| Setting | Type | Rule |
|---|---|---|
| `target` | string | an existing object (the same object too: "related tags"); fixed once the field exists |
| `onDelete` | string | `unlink` (default) or `restrict`; fixed once the field exists |
| `inverseName` | string or null | machine name of the inverse side; must not be a field of the target, nor the inverse name of another relation or many-to-many field pointing at it |
| `inverseLabel` | string or null | its title on the target's page; default "<Plural label> (<Field label>)" |
| `max` | integer or null | how many records may be linked, at least 1; null (default) for no limit |

```bash
symfony console metadata:field:create contact tags many_to_many Tags \
  --settings='{"target":"tag","inverseName":"contacts","inverseLabel":"Contacts"}'
```

What happens when a **linked record is deleted**, per `onDelete`:

| Rule | Effect |
|---|---|
| `unlink` | The links to it are removed; the records holding them stay. |
| `restrict` | The deletion is refused while live records are linked to it. |

There is no `cascade`: deleting a tag never deletes the contacts it was linked to.

**Storage.** The field has no column in its object's table. Its links live in a table of their
own, `data.l_<object>__<field>` (`data.l_contact__tags`), one row per link:

| Column | Holds |
|---|---|
| `record_id` | the record holding the link (`contact`), foreign key, deleted with it |
| `target_id` | the linked record (`tag`), foreign key, `RESTRICT` with `onDelete: restrict` |
| `created_at`, `created_by` | when, and by whom, the link was made |

The pair `(record_id, target_id)` is the primary key: a record is linked to another one at most
once. The table follows the field: it's renamed with the field or its object, kept while the field
is archived, dropped when the field or its object is purged (see [Renaming and
archiving](lifecycle.md)).

What a `many_to_many` field cannot be: unique, the label of the records, searched, the field
deciding a [condition](#conditions), part of a cascade (`dependsOn`, `dependsVia`), the `via` of a
composite form section, a service field. It can have a condition of its own.

Input and display:

- The value is the list of the linked record ids (UUIDs, any case); a single id counts as a list
  of one, duplicates count once. The order isn't kept: the links come back oldest first.
- Writing the field replaces its links: the ones missing are added, the others removed; a link
  kept keeps its date. An empty list (or null) removes them all; a write that leaves the field
  out keeps them. Changing the links alone marks the record as updated.
- Every id must name a live record of the target: "This value is not a valid record reference."
  for an id that isn't a UUID, "One of the selected records does not exist." for a missing or
  deleted one. **Required** means at least one record; `max` refuses more ("Choose at most 3
  records.").
- The form offers an autocomplete over the target's records, picking any number of them; it
  works in [composite forms](forms.md) too. The record page shows the linked records by their
  label, and the target's page lists the records linking to it, as for a `relation`.
- A record whose condition stops applying loses its links, as any field loses its value.
- The grid shows the labels of the linked records, in alphabetical order, joined by ", "
  ("Press, VIP"); a record whose label is empty is shown by the first 8 characters of its id. The
  column filters on one or several records of the target, picked with an autocomplete, and keeps
  the records linked to **any** of them. It does not sort, and the search box does not look into
  it. The export writes the same labels, joined by ", ". In a [grid view](views.md#grid-views) the
  field can be a column of its own, never part of a virtual column nor sorted.

**Not yet.** When the link carries data of its own (a role, a description), use a junction object
with two `relation` fields instead.
