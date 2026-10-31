# Admin UI

The no-code builder at `/admin` does what the [console commands](console.md) do, from the browser.
Every change takes effect at once, in one transaction with the DDL it triggers, exactly as from the
console. It needs the `admin.metadata` permission (or `ROLE_SUPER_ADMIN`), see
[Users and permissions](access.md).

## Objects — `/admin`

The list of objects, with their machine name, table and number of fields, and a link to their
records. **New object** asks for the machine name and the two labels. The labels are written in
the default language (English); **Translations** gives them their text in the other languages.

## An object — `/admin/objects/<object>`

| Section | What it does | Console equivalent |
|---|---|---|
| Fields | Every field with its column, type, rules and settings; **Add a field**, **Edit** (label and condition) | `metadata:field:create`, `metadata:field:condition`, `metadata:list <object>` |
| Views | The layouts of the record form and the columns of the record grid; **Add a view**, **Edit**, **Delete** (see [Views](views.md)) | `metadata:view:save`, `metadata:view:delete` |
| Pointed at by | The relation fields of other objects pointing at this one, with their delete rule | |
| Labels | Rename the singular and plural labels | |
| Record label | The field that names the records in relations and lookups | `metadata:object:label` |
| Search | The search handler and the searched fields; the search indexes follow | `metadata:object:search` |
| Table | Whether the table matches the metadata; **Apply** adds what is missing | `schema:sync <object>` |

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

## A field — `/admin/objects/<object>/fields/<field>`

The field's type, column, rules and settings, and when it applies. **Label** renames it.
**Settings** changes them: the form of the field's type, with the current values; the settings that
shape the column are shown disabled (see [Changing settings](field-types.md#changing-settings)).
**Condition** makes it apply only while another field holds some values: choose the deciding
field (the object's choice and yes/no fields are offered, each with its values in the help), type
the values separated by commas, and save; **Always** removes the condition. See
[Conditions](field-types.md#conditions).

## What cannot be changed

Once a field exists its name, type, rules and settings are fixed (the label and the condition can
change), and objects and fields cannot be deleted yet.
