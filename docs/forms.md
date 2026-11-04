# Composite forms

A composite form writes a record **and the records related to it** in one page and one
transaction: an account, its profile, the phones of the profile, the qualifications of the
account. It is defined in YAML, stored with the metadata, and checked against the objects when it
is saved: no code, no deploy.

```yaml
label: User registration
description: Sign-in and personal data.  # under the title of the first card
object: account                        # the root: the record the form is about
fields: [username, email, firstname, lastname, password, active]
columns: 2                             # the root's fields on two columns
service_fields:
  - { name: terms, type: boolean, label: I accept the terms, required: true, ask: create }
sections:
  - name: profile
    label: Profile
    description: Where to find you.
    object: profile
    via: account                       # the relation field of profile pointing at account
    sections:
      - { name: phones, label: Phones, object: phone, via: profile, mode: many, min: 1, inline: true }
  - { name: qualifications, label: Qualifications, object: qualification, via: account, mode: many }
```

The form opens at `/f/<name>/new`; once saved, the same records are edited at
`/f/<name>/<root id>/edit`.

## The page

The page is a sheet:

- a **header** that stays in view: the plural of the root object (a link to its records), the
  title of the form, **Cancel** (back to the records) and **Save**, repeated at the foot of the
  form;
- numbered **cards**: first the fields of the root, titled with the singular of the root object
  (e.g. "Account"), then one card per section of the root, each with its `description` under
  the title if it has one. Deeper sections are framed groups inside their card;
- an **index** on the side, one link per card, with how far its filling has come: "2/6
  required" (or "1/7 filled" when the card requires nothing), "3 entries" for a section of rows.
  It follows the inputs as they change, and marks the card in view. On a narrow screen it is
  hidden and the cards fill the width;
- the rows of a section are numbered ("01 Qualifications"), with **Remove** on each and
  **+ <section>** below.

The texts of the page itself (Save, Cancel, Required field...) are in the user's language too
(see [Languages](i18n.md#the-texts-of-the-application)).

## The model behind it

A section is a set of records of another object that **point at the record of the section's
parent** through a relation field, its `via`. The example needs these relations:

| Object | Relation field | Points at | Section |
|---|---|---|---|
| `profile` | `account` (unique) | `account` | `profile`: one record (`mode: one`) |
| `phone` | `profile` | `profile` | `phones`, inside `profile`: rows (`mode: many`) |
| `qualification` | `account` | `account` | `qualifications`: rows |

Create the objects and their relation fields first ([field types](field-types.md#relation)). A
`cascade` delete rule on the relation is usually what a form wants: deleting the account
deletes its profile, phones and qualifications.

A [`many_to_many`](field-types.md#many_to_many) field (a contact's tags) is a field like the
others in `fields`: an autocomplete picking any number of records, saved with its record. It
can't be a section's `via`: a section's records point at their parent through a `relation`.
One going [through a junction object](field-types.md#through-a-junction-object) shows its rows
inline (the target and the junction's fields, "+" and "×"), saved with its record as well, as far
as the user's permissions on the junction go; a row's errors go on its inputs.

## Definition reference

### The root

| Key | Required | Description |
|---|---|---|
| `label` | yes | The title of the form, at most 100 characters |
| `object` | yes | The root object |
| `description` | no | A line under the title of the root's card, at most 300 characters |
| `fields` | no | The fields shown, in order. Default: every field of the object. It may not leave out a field the object requires |
| `required` | no | Fields required by this form although the object does not require them (see below) |
| `service_fields` | no | Fields that belong to no object (see below) |
| `columns` | no | 1 to 4 (default 1): the fields and service fields on this many columns, one on a narrow screen. Not with `layout` |
| `layout` | no | HTML placing the fields and service fields of the root (see [Layouts](#layouts)); its sections are cards of their own |
| `sections` | no | The sections, in order |

### A section

| Key | Required | Description |
|---|---|---|
| `name` | yes | Machine name, unique among its siblings; it names the inputs (`composite[sections][phones]...`) |
| `label` | yes | The title of the section |
| `description` | no | A line under the title, at most 300 characters |
| `object` | yes | The object of its records |
| `via` | yes | The relation field of `object` pointing at the parent's object |
| `mode` | no | `one` (default): one record, shown once. `many`: rows, added with "+" and removed one by one |
| `min` | no | `many` only: rows required (default 0). A new form starts with this many rows |
| `max` | no | `many` only: rows allowed (default: no limit) |
| `inline` | no | `true`: the fields on one line, e.g. `[prefix] [number] [type] ×`. The labels move into the inputs as placeholders ("Number *"). Suits a few short fields |
| `fields` | no | The fields shown. Default: every field but `via`, which the form sets itself. It may not leave out a field the object requires |
| `required` | no | As on the root |
| `service_fields` | no | As on the root |
| `columns` | no | As on the root. Not with `layout` or `inline` |
| `layout` | no | As on the root, for the fields of this section and its sections; in a section of rows, every row follows it. Not with `inline` |
| `sections` | no | Nested sections: a section of rows may have sections of its own, up to 4 levels |

### Required fields

Two rules, checked together:

- **What the object requires, the form requires.** A required field of an object is required in
  every form that writes it, and a definition whose `fields` leaves one out is refused
  (`(root): "email" is required by object "account": list it in "fields".`).
- **A form may require more.** `required: [city]` makes the city required in this form, while
  the profile object, and every other form, keeps it optional. It may only name fields the
  form shows. A required checkbox must be checked; a password left empty on edit is kept, as
  always. In a section left entirely empty (skipped), nothing is required.

Both rules follow the [conditions](field-types.md#conditions) of the fields: a field that does
not apply (a course's hours on a language) is never required.

Required fields are marked with a red asterisk after their label, explained by "* Required
field" under the form; the same holds in the record forms.

### Service fields

A service field is checked like a field of its type, then **dropped**: no column stores it.
"I accept the terms", "I have read the privacy notice".

| Key | Required | Description |
|---|---|---|
| `name` | yes | Machine name, unique within its node |
| `type` | yes | Any field type but `relation` and `many_to_many` |
| `label` | yes | The label shown |
| `required` | no | `true`: must be given. A required **boolean must be checked** ("This box must be checked.") |
| `ask` | no | `always` (default): asked on every save, a new record or an existing one. `create`: asked on a new record only |
| `settings` | no | The settings of the type, e.g. `{pattern: '[A-Z]{2}'}` for a text |

`ask` follows the record of the node the field belongs to. On the root, `ask: create` asks
"I accept the terms" at `/f/<name>/new` only: the edit page neither shows nor checks it. In a
`mode: many` section, a saved row is not asked it again, while a row added with "+" is new and
is asked it, on the edit page too. A `{name}` token of a field not asked places nothing.

A password confirmation is not a service field: the `confirm` setting of the `password` type
already asks twice ([field types](field-types.md#password)).

### Layouts

A `layout` lays out one node of the form, the root or a section, the way a [view](views.md) lays
out a record form: HTML with a `{name}` token where each piece goes. In the layout of a node a
token may name:

- one of its **fields**: the row of the field (label, input, help, errors);
- one of its **service fields**: its row, the same way;
- one of its **sections**: the whole section, its title, rows and "+" button included, laid out
  by its own `layout` if it has one. Not on the root: its sections are cards of their own, after
  the root's (see [The page](#the-page)), and a `{section}` token there is refused.

```yaml
label: User registration
object: account
fields: [username, email, firstname, lastname, password, active]
service_fields:
  - { name: terms, type: boolean, label: I accept the terms, required: true }
layout: |
  <div class="md-columns">
    <fieldset><legend>Sign-in</legend>{username} {email} {password}</fieldset>
    <fieldset><legend>Name</legend>{firstname} {lastname}</fieldset>
  </div>
  {terms}
sections:
  - name: profile
    label: Profile
    object: profile
    via: account
    layout: '<div class="md-columns">{city} {zipcode}</div> {phones}'
    sections:
      - { name: phones, label: Phones, object: phone, via: profile, mode: many, inline: true }
  - name: qualifications
    label: Qualifications
    object: qualification
    via: account
    mode: many
    layout: '<div class="md-columns">{title} {year}</div>'   # every row
```

The rules are those of the views:

- Each token at most **once**, in the text of the HTML, never inside a tag.
- What the layout does not place **follows it**, in the usual order: above, the account's
  `active` after the columns, the profile's gender after its city and postal code.
- The HTML is **sanitized** when the form is saved, with the same rules as the views (no
  scripts, styles, event attributes or buttons). The page uses the sanitized layout; **Edit**
  still shows the definition as written.
- The classes are those of the views: `<fieldset>`/`<legend>` for a framed group,
  `md-columns` for columns ([Styling](views.md#styling)).
- A token names one thing: when a layout is given, a section named like a field or a service
  field of its node is refused. The `via` field is set by the form, so no token places it.
- `inline: true` already lays a row's fields out on one line: a section has one or the other.
- A `{t:name}` token shows a text in the user's language, e.g. `<legend>{t:sign_in}</legend>`
  instead of `<legend>Sign-in</legend>`; the text must be defined before the form is saved (see
  [Languages](i18n.md#texts-in-layouts-tname)). The labels of the form, of its sections and of
  its service fields, and the descriptions, are translated by their own keys (`form.<form>`,
  `section.<form>.<path>`, `service.<form>.<path>.<field>`, `description.<form>[.<path>]`).

The inputs keep their names, so a layout changes the look of a form, never what it saves.

### Errors

Saving a definition checks it against the objects, and reports **every** error at once, each with
the path of its section:

```
(root): object "account" has no field "nickname".
profile.phones: "account" is not a relation field of "phone" pointing at "profile" (candidates: profile).
qualifications: "object" must name an existing object, "qualificaton" does not exist.
```

Unknown keys are refused too, so a typo does not go unnoticed.

## Filling in a form

- **All or nothing.** The whole input is checked first: every record with the rules of its
  object (required, unique, types, patterns), the service fields, the number of rows. Every error
  is shown on its input at once, and nothing is written until all is valid. Then the records are
  written top-down in one transaction.
- **Empty rows are skipped.** A row nobody filled in (every field empty or left at its default
  value) is not saved and not refused: a qualification row added by mistake does no harm. An
  empty one-to-one section (a profile left blank on a new form) creates no record.
- **Editing** updates the records that are there, creates the rows added, and **deletes the rows
  removed**, with the delete rules of their relations. A deletion refused by a `restrict` rule
  is reported on the section.
- **Passwords** left empty on edit are kept, as in the record form.
- **Rows are identified by their record id**, posted in a hidden input. A row id that does not
  belong to the record is refused ("This row does not belong to the record.").

## Permissions

| Page | Needs, on every object of the form |
|---|---|
| Open a new form, save it | `create` |
| Open an existing one | `read` |
| Save an existing one | `create`, `update` and `delete` (rows are added and removed) |

Without the save permissions an existing form opens read-only. The home page lists the forms
the user may fill in.

## Admin UI and console

From the admin: **Admin → Forms** lists the forms; **New form** offers an example to start from,
and lists the objects with their fields and relation fields next to the editor. **Edit** shows
the definition as it was written, comments included; **Delete** removes the form, not its
records.

```bash
symfony console metadata:form:save registration forms/registration.yaml
cat forms/registration.yaml | symfony console metadata:form:save registration -
symfony console metadata:form:delete registration      # the records stay
```

## Renamed and archived objects and fields

Renaming an object or a field rewrites the composite forms naming it (`object`, `fields`,
`required`, the `{field}` tokens of a `layout`, `via`) and compiles them again: the YAML is written
again, so its comments are lost; a form not naming it is left as written. An object with a node in
a form, or a field the form names, cannot be archived until the form no longer uses it. See
[Renaming and archiving](lifecycle.md).

## Under the hood

| Piece | Where |
|---|---|
| Storage | `core.metadata_form`: the YAML as written (`source`) and its compiled form (`definition`, JSONB) |
| Checks | `FormDefinitionCompiler` (Metadata): YAML → compiled definition, against the objects |
| Read side | `MetadataRegistryInterface::getForm()`, `FormDefinitionDto` / `FormNodeDto`, cached with the metadata |
| Writes | `CompositeRecordManagerInterface` (Record): plans and checks the whole tree with `RecordManagerInterface::check()`, then writes it with the record pipeline; a service field is checked when `ServiceFieldDto::isAskedFor()` the record |
| Page | `CompositeFormController` at `/f/{form}`, `CompositeNodeType` (a node: `values`, `service`, `sections`, a row's `id`; drops the service fields an existing record is not asked), `templates/record/composite.html.twig` (the sheet and its cards), `_composite.html.twig` (a node, a section, a row), `_sheet_header` / `_sheet_footer` (shared with the record form) |
| Index | The `form-index` Stimulus controller (`assets/controllers/form_index_controller.js`): counts the inputs and rows of each card, marks the one in view |
| Layouts | Checked and sanitized by `FormDefinitionCompiler` (the `view_layout` sanitizer), `FormNodeDto::$layout`; rendered by the Twig function `composite_form_layout()` (`RecordFormLayout`) |
| Rows | The `gridview-collection` Stimulus controller and the `gv-collection` classes of fedale/gridview-bundle: adds a row from the section's prototype under a fresh key, removes one, keeps `min`/`max` |

## Not yet

- **Service fields on edit**: a required one is asked again at every save.
