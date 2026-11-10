# Admin UI

The no-code builder at `/admin` does what the [console commands](console.md) do, from the browser.
Every change takes effect at once, in one transaction with the DDL it triggers, exactly as from the
console. It needs the `admin.metadata` permission (or `ROLE_SUPER_ADMIN`), see
[Users and permissions](access.md).

## Objects — `/admin`

The list of objects, with their machine name, table and number of fields, and a link to their
records. **New object** asks for the machine name and the two labels. The labels are written in
the default language (English); **Translations** gives them their text in the other languages.
Under them, the **Archived** objects, each with **Restore** (see [Renaming and archiving](lifecycle.md)).

## An object — `/admin/objects/<object>`

| Section | What it does | Console equivalent |
|---|---|---|
| Fields | Every field with its column, type, rules and settings; **Add a field**, **Edit** (label, settings, condition, machine name, archive); the archived fields, with **Restore** | `metadata:field:create`, `metadata:field:condition`, `metadata:list <object>` |
| Views | The layouts of the record form and the columns of the record grid; **Add a view**, **Edit**, **Delete** (see [Views](views.md)) | `metadata:view:save`, `metadata:view:delete` |
| Pointed at by | The relation and `many_to_many` fields of other objects pointing at this one, with their delete rule | |
| Labels | Rename the singular and plural labels | |
| Record label | The field that names the records in relations and lookups | `metadata:object:label` |
| Search | The search handler and the searched fields (a relation or a many-to-many field, ticked, is searched on the labels of its records); the search indexes follow | `metadata:object:search` |
| Unique together | The sets of fields no two records may share all the values of, each with **Remove**; **Make unique together** adds one from the fields ticked (at least two) | `metadata:object:unique` |
| Table | Whether the table matches the metadata; **Apply** adds what is missing | `schema:sync <object>` |
| Machine name | Renames the object, its table and everything named after it | `metadata:rename <object> <new-name>` |
| Archive | Takes the object out of the application, its records kept; refused while a relation or a composite form uses it | `metadata:archive <object>` |

The **Table** section never drops anything: differences that would lose data (a column the metadata
does not define, a column defined differently) are listed, and only
`schema:sync --allow-destructive` applies them.

## A new field

1. **Add a field** shows the field types with a one-line description.
2. The form of the chosen type asks for the machine name, the label, *required* and *unique*, then
   the type's own settings: maximum length for a text, decimals for a number, the options of a
   choice (one per line, `value | label`), the related object and the delete rule of a relation...

The settings part of the form is generated from the settings each field type declares
(`FieldTypeInterface::settingDefinitions()`): a new field type shows up in the admin with its
settings without any change to the admin.

Errors appear next to the input they concern: an invalid or taken name, a missing label, a
setting the field type refuses (duplicated option, decimals out of range, unknown related object).

## Views

**Add a view** asks for a machine name, what the view is used for (the record form or the record
grid), the definition, and whether it is the default view. A form view is an HTML layout with a
`{field}` token per field; a grid view is a YAML list of columns, with an example in the empty
input. The help lists the fields of the object. **Edit** changes the definition and the default
flag, links to a preview (a new record with the form view, the grid with the grid view) and has
the **Delete** button; a grid view's errors are all shown at once under its definition. See
[Views](views.md) for how to write a view and which view a page uses.

## Forms — `/admin/forms`

The composite forms: one form writing a record and the records related to it. **New form** starts
from an example definition (YAML) and lists every object with its fields and relation fields
next to the editor; saving reports every error of the definition at once, those of the
`layout`s included. See [Composite forms](forms.md) and its [Layouts](forms.md#layouts).

## Translations — `/admin/translations`

Two tabs, one per kind of text (see [Languages](i18n.md#the-admin-pages)):

- **Metadata** (`/admin/translations/<locale>`): every label of the metadata, by object and by
  form, with its original text, an **Edit** link to where that text is defined, and an input for
  one language (a switch picks it when there are several). Saving stores the inputs that
  changed; emptying one removes its translation.
- **Texts** (`/admin/texts`): your own texts, placed by `{t:name}` tokens, an input per language
  side by side and the views and forms placing them; **New text** creates one. Emptying the
  default-language text removes the text, unless a token still places it.

The language of the pages themselves is picked by each user in the top bar (**EN / IT**).

## Templates — `/admin/templates`

A model packaged to be installed in one go, and upgraded later (see [Templates](templates.md)).
The page has three parts:

- **Installed**: each template, its version, when it was installed and upgraded, and the index
  it came from.
- **Available**: the newest version of every template the indexes offer, with **Install…**, or
  **Upgrade from …** when an older version is installed.
- **Indexes**: where templates come from, signed or accepted unsigned, with **Remove** (what
  was installed from an index stays); **Add an index** takes its name, the address of its
  `index.json`, and either its public key or the box accepting it unsigned.

**Install…** first runs a trial install, rolled back: the page lists what installing creates
(objects, fields, views, forms, translations, records), or every reason it cannot. The button
installs it for real. A template requiring others this instance doesn't have comes with them:
the page lists each, requirements first, and the button installs them all together (see
[Templates requiring templates](templates.md#templates-requiring-templates)).

**Upgrade from …** lists the changes the new version brings, then each **conflict**, an item
both the new version and this instance changed: this instance's version and the new one side by
side, and a choice, item by item, of which stays (this instance's, by default). The button
applies the upgrade with those choices. A change the upgrade cannot make, or a version number
that does not fit its changes, is shown instead of the button.

Templates are only named on these pages (`vendor/name@version`), never given as a directory of
the server: installing from a directory, packing and exporting are console commands
([`metadata:template:*`](console.md)).

## A field — `/admin/objects/<object>/fields/<field>`

The field's type, column, rules and settings, and when it applies. **Label** renames it.
**Settings** changes them: the form of the field's type, with the current values; the settings that
shape the column are shown disabled (see [Changing settings](field-types.md#changing-settings)).
**Condition** makes it apply only while another field holds some values: choose the deciding
field (the object's choice and yes/no fields are offered, each with its values in the help), type
the values separated by commas, and save; **Always** removes the condition. See
[Conditions](field-types.md#conditions). **Machine name** renames the field, its column and what
names it (views and composite forms included); **Archive** takes it out of the forms and grids,
its values kept. See [Renaming and archiving](lifecycle.md).

## What cannot be changed

Once a field exists its type and rules are fixed, and so are the settings that shape its column;
its label, its other settings, its condition and its machine name can change. Objects and fields
are archived from the admin, and only deleted for good from the console (`metadata:purge`), see
[Renaming and archiving](lifecycle.md).
