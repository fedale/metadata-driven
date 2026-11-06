# Console commands

Every command runs through the Symfony CLI, which hands PHP the database URL of the Docker
container:

```bash
symfony console <command> [arguments] [options]
symfony console <command> --help        # the same reference, from the command itself
```

| Command | Purpose |
|---|---|
| [`metadata:object:create`](#metadataobjectcreate) | Create an object, and its table |
| [`metadata:field:create`](#metadatafieldcreate) | Add a field to an object, and its column |
| [`metadata:field:settings`](#metadatafieldsettings) | Change some settings of an existing field |
| [`metadata:field:condition`](#metadatafieldcondition) | Make a field apply only while another field holds some values |
| [`metadata:object:label`](#metadataobjectlabel) | Choose the field that names the records of an object |
| [`metadata:object:search`](#metadataobjectsearch) | Choose how the records of an object are searched |
| [`metadata:object:unique`](#metadataobjectunique) | Make a set of fields unique together, or no longer |
| [`metadata:view:save`](#metadataviewsave) | Create or replace a view: an HTML layout of the record form, or the YAML columns of the record grid |
| [`metadata:view:delete`](#metadataviewdelete) | Delete a view |
| [`metadata:form:save`](#metadataformsave) | Create or replace a composite form from its YAML definition |
| [`metadata:form:delete`](#metadataformdelete) | Delete a composite form (its records stay) |
| [`metadata:translation:set`](#metadatatranslationset) | Translate a text of the metadata into a language, or define a text of your own |
| [`metadata:translation:list`](#metadatatranslationlist) | List the translation keys, with their texts in a language |
| [`metadata:rename`](#metadatarename) | Give an object, or one of its fields, a new machine name |
| [`metadata:archive`](#metadataarchive) | Take an object or a field out of the application, its data kept; `--restore` brings it back |
| [`metadata:purge`](#metadatapurge) | Delete an archived object or field for good, its data with it |
| [`metadata:list`](#metadatalist) | List the objects, or the fields of one object, and what is archived |
| [`metadata:files:gc`](#metadatafilesgc) | Delete the stored files no record references any more |
| [`record:import`](#recordimport) | Import the rows of a CSV file into an object, all or nothing |
| [`metadata:template:export`](#metadatatemplateexport) | Write objects, their fields and views, composite forms and their translations as a template |
| [`metadata:template:validate`](#metadatatemplatevalidate) | Check that a template follows the format, without installing it |
| [`metadata:template:install`](#metadatatemplateinstall) | Install a template: its objects and their tables, fields, views, forms and translations, all or nothing |
| [`metadata:template:list`](#metadatatemplatelist) | List the templates installed, with their versions |
| [`metadata:template:keygen`](#metadatatemplatekeygen) | Make a key pair to sign the templates of an index with |
| [`metadata:template:pack`](#metadatatemplatepack) | Pack a template into an archive, and list it in an index |
| [`metadata:template:repo`](#metadatatemplaterepo) | List, add or remove the indexes templates are downloaded from |
| [`metadata:template:search`](#metadatatemplatesearch) | Find templates in the indexes, with the version installed here |
| [`metadata:template:yank`](#metadatatemplateyank) | Withdraw a version from an index, or list it again |
| [`metadata:template:upgrade`](#metadatatemplateupgrade) | Upgrade an installed template to a new version: only what it changed, what this instance changed kept |
| [`schema:sync`](#schemasync) | Align the record tables with the metadata |
| `user:create` | Create a user who can sign in — see [Users and permissions](access.md#usercreate) |
| `access:sync` | Create the permissions of every object, the admin permission and the super admin role — see [Users and permissions](access.md#permissions) |
| `rbac:*` | Roles, permissions and assignments (`fedale/rbac-bundle`) — see [Users and permissions](access.md#roles) |

## How the commands change the database

The `metadata:*` commands that write a definition run in **one PostgreSQL transaction**: they save
the definition in the `core` schema, then the Schema module applies the matching DDL to the record
table in the `data` schema. If the DDL fails, the definition is rolled back too (PostgreSQL DDL is
transactional), so metadata and tables never disagree. Every DDL statement applied is logged in
`core.schema_change_log`.

Names (objects, fields, inverse relations) become SQL identifiers, so they follow one rule:
2 to 50 characters, lowercase letters, digits and single underscores, starting with a letter
(`first_name`, `address2`; not `FirstName`, `_tmp`, `a__b`). They are quoted in SQL as well, so
reserved words such as `order` or `select` are fine. Names can be changed with
[`metadata:rename`](#metadatarename).

Errors are printed in red and the command exits with a non-zero code; nothing is saved.

---

## `metadata:object:create`

Create an object (a record type) and its table.

```
metadata:object:create [--icon=ICON] <name> <label-singular> <label-plural>
```

| Argument / option | Description |
|---|---|
| `name` | Machine name, e.g. `pet`. The table is `data.c_<name>`. |
| `label-singular` | Shown in the UI, e.g. `Pet` ("New pet", "Pet created.") |
| `label-plural` | Shown in the UI, e.g. `Pets` (grid title, home page) |
| `--icon` | Icon name, stored for the admin UI (not shown yet) |

```bash
symfony console metadata:object:create pet Pet Pets
symfony console metadata:object:create contact Contatto Contatti --icon=user
```

The table starts with the system columns: `id` (UUID, primary key), `created_at`, `updated_at`,
`created_by`, `deleted_at` (records are soft deleted) and `sort` (an integer, positive or
negative, default `0`, for the manual order of the records; the application does not use it yet).
Tables created before `sort` existed get it from [`schema:sync`](#schemasync).

Fails when the name is invalid or already used.

## `metadata:field:create`

Add a field to an object, and the matching column to its table.

```
metadata:field:create [--required] [--unique] [--settings=JSON] <object> <name> <type> <label>
```

| Argument / option | Description |
|---|---|
| `object` | The object's machine name |
| `name` | The field's machine name; also the column name. `id`, `created_at`, `updated_at`, `created_by`, `deleted_at`, `sort` are reserved. |
| `type` | `text`, `number`, `boolean`, `date`, `email`, `tel`, `url`, `select`, `password`, `file`, `relation` or `many_to_many`: see [field types](field-types.md) |
| `label` | Shown in forms, grid headers and errors |
| `--required` | A value is mandatory. Checked by validation; the column stays nullable, so a required field can be added to a table that already has records. |
| `--unique` | Values must be unique: a unique index, plus a readable error on save. Values of deleted records stay reserved. |
| `--settings` | The type's settings as a JSON object (default `{}`); missing keys take their default |

```bash
# Text, at most 80 characters, mandatory
symfony console metadata:field:create pet name text Name --required --settings='{"maxLength":80}'

# Long text
symfony console metadata:field:create pet notes text Notes --settings='{"multiline":true}'

# Decimal number with 2 decimals
symfony console metadata:field:create pet weight number Weight --settings='{"decimals":2}'

# Date and time
symfony console metadata:field:create visit starts_at date "Starts at" --settings='{"includeTime":true}'

# Single choice
symfony console metadata:field:create pet species select Species \
  --settings='{"options":[{"value":"dog","label":"Dog"},{"value":"cat","label":"Cat"}]}'

# Choice shown as buttons side by side
symfony console metadata:field:create account gender select Gender \
  --settings='{"options":[{"value":"m","label":"Male"},{"value":"f","label":"Female"}],"display":"radio"}'

# On/off switch, on for new records
symfony console metadata:field:create account active boolean Active --settings='{"default":true,"display":"switch"}'

# Password: hashed, with strength rules, asked twice in the form, with a strength meter and a show/hide eye
symfony console metadata:field:create account password password Password --required \
  --settings='{"minLength":8,"requireMixedCase":true,"requireDigit":true,"requireSymbol":true,"confirm":true,"strengthMeter":true,"revealToggle":true}'

# Text with a pattern
symfony console metadata:field:create account zipcode text "Postal code" \
  --settings='{"maxLength":5,"pattern":"\\d{5}","patternMessage":"A postal code has five digits."}'

# Year between bounds, link, phone
symfony console metadata:field:create education year number Year --settings='{"min":1950,"max":2100}'
symfony console metadata:field:create certification url url "Verification link"
symfony console metadata:field:create phone number tel Number

# Unique code
symfony console metadata:field:create pet chip_code text "Microchip" --unique --settings='{"maxLength":15}'

# Relation (many-to-one) to another object
symfony console metadata:field:create pet owner relation Owner \
  --settings='{"target":"contact","onDelete":"set_null","inverseName":"pets","inverseLabel":"Pets"}'

# Many-to-many link to another object
symfony console metadata:field:create contact tags many_to_many Tags \
  --settings='{"target":"tag","inverseName":"contacts"}'

# Many-to-many link through a junction object (see field-types.md)
symfony console metadata:field:create person tags many_to_many Tags \
  --settings='{"target":"tag","through":"person_tag","throughSource":"person","throughTarget":"tag"}'
```

What it changes in the table:

- a column of the type's SQL type (`varchar(n)`, `text`, `integer`, `numeric(18,d)`, `boolean`,
  `date`, `timestamp`, `uuid`);
- `--unique`: a unique index `uniq_<table>_<column>`;
- `relation`: an index `idx_<table>_<column>` and a foreign key `fk_<table>_<column>` to the target table;
- `many_to_many`: no column, but a link table `data.l_<object>__<field>` (`record_id`, `target_id`,
  `created_at`, `created_by`), with foreign keys to both tables; none with `through`: the links
  are the records of the junction object;
- a text field searched by the object (see `metadata:object:search`): a trigram index
  `trgm_<table>_<column>`.

Fails when the object does not exist, the name is invalid, reserved or already used, the type is
unknown, or the settings are invalid; every settings error is listed. A relation or a `many_to_many`
field also fails when the target object does not exist, or when the inverse name is already a field
or an inverse relation of the target; a `many_to_many` field also fails with `--unique`.

## `metadata:field:settings`

Change some settings of an existing field; the settings not given keep their value. `null`
clears a setting (back to its default).

```
metadata:field:settings <object> <field> <settings>
```

```bash
symfony console metadata:field:settings account password '{"strengthMeter":true,"revealToggle":true}'
symfony console metadata:field:settings phone prefix '{"options":[{"value":"+39","label":"+39 IT"},{"value":"+33","label":"+33 FR"}]}'
symfony console metadata:field:settings profile zipcode '{"pattern":null}'
```

Fails when a setting is invalid (every error is listed), when it shapes the column (see
[Changing settings](field-types.md#changing-settings)), or when an option to remove is still held
by records or named by a condition. No DDL.

## `metadata:field:condition`

Make a field apply only while another field of the object holds one of some values; without
`--when`, the field always applies again. See [Conditions](field-types.md#conditions).

```
metadata:field:condition [--when=FIELD] [--in=VALUES] <object> <field>
```

| Argument / option | Description |
|---|---|
| `object`, `field` | The field that gets the condition |
| `--when` | The deciding field: a `select` or a `boolean` of the same object |
| `--in` | Comma separated values of the deciding field: option values, or `true` / `false` |

```bash
symfony console metadata:field:condition qualification hours --when=kind --in=course
symfony console metadata:field:condition qualification credential --when=kind --in=certification,course
symfony console metadata:field:condition account reason --when=active --in=false
```

Fails when the deciding field cannot decide, holds none of the values, is the field itself, or
when the condition would chain. No DDL: the column stays.

## `metadata:object:label`

Choose the field that names the records of an object wherever they are referenced: relation
fields, the autocomplete, the grid cells of a relation, the "related records" lists.

```
metadata:object:label <object> [<field>]
```

| Argument | Description |
|---|---|
| `object` | The object's machine name |
| `field` | The field to use. Omit it to go back to the default: the first text (or email) field. |

```bash
symfony console metadata:object:label contact full_name
symfony console metadata:object:label contact            # back to the default
```

A record whose label field is empty, or an object with no text field, is shown as `#` plus the
first 8 characters of its id. A password or a `many_to_many` field is refused.

## `metadata:object:search`

Choose how the search box of an object's grid works.

```
metadata:object:search [--handler=HANDLER] [--fields=FIELDS] <object>
```

| Argument / option | Description |
|---|---|
| `object` | The object's machine name |
| `--handler` | `free_text_multi_field` (default) or `phrase` |
| `--fields` | Comma separated fields to search. Default: every text-like field (text, multiline text, email). A `relation` or `many_to_many` field is searched on the labels of the records it points at, and only when listed here: it is never a default. A password or a file field is refused. |

The handlers:

- **`free_text_multi_field`**: every word must occur in at least one of the fields, in any order,
  case-insensitively. `John London` finds first name John and city London, and so does `London John`.
  A word matches inside a value too (`john` finds `Johnson`). Quotes keep a phrase together:
  `"new york" john`. At most 8 words are used.
- **`phrase`**: the whole term, in the order typed, must occur in one field. For codes and
  identifiers.

```bash
symfony console metadata:object:search contact --fields=first_name,last_name,city
symfony console metadata:object:search pet --handler=phrase --fields=chip_code
symfony console metadata:object:search contact                 # back to the defaults
symfony console metadata:object:search course --fields=title,tags,owner  # "php" finds the courses tagged PHP
```

Running it also re-synchronizes the table's trigram indexes: one GIN index per searched text
column, so that the search uses an index instead of scanning the table. Number and date fields can
be searched but get no index (PostgreSQL refuses their text cast in an index). A `relation` or
`many_to_many` field gets none either: its search looks up the target's labels (an `EXISTS` on
the target's table), indexed only if the target searches its own label field.

Fails when a field does not exist or the handler is unknown.

## `metadata:object:unique`

Make a set of fields unique together: no two live records may hold the same values in all of
them (a person in a team, once). See [Unique together](field-types.md#rules-common-to-every-type).

```
metadata:object:unique [--remove] <object> <fields>
```

| Argument / option | Description |
|---|---|
| `object` | The object's machine name |
| `fields` | Comma separated fields, at least two, in the order of the index |
| `--remove` | Remove the set (its fields in any order) instead of adding it |

```bash
symfony console metadata:object:unique membership person,team
symfony console metadata:object:unique membership team,person --remove
```

Adding a set builds a partial unique index `uniqc_<table>__<column>__<column>` (`WHERE deleted_at
IS NULL`); removing it drops the index. The index holds no data: it follows the set both ways, and
is built again under its new name when a field of the set or the object is renamed.

Fails when a field does not exist, is a password, a file or a `many_to_many` field, when the set
has fewer than two different fields or exists already (in any order), and while live records
already share the values ("3 records share their values with another one; change or delete them
first"). `metadata:list <object>` lists the sets.

## `metadata:view:save`

Create a view, or replace an existing one. A form view is HTML with a `{field}` token where each
field goes; a grid view is a YAML list of columns (see [Views](views.md) and
[Grid views](views.md#grid-views) for how to write them and the rules checked).

```
metadata:view:save [--kind=KIND] [--default] <object> <name> <file>
```

| Argument / option | Description |
|---|---|
| `object` | The object's machine name |
| `name` | The view's machine name |
| `file` | The file holding the HTML (form) or the YAML (grid), `-` for the standard input |
| `--kind` | Where the view is used: `form` (default), the record form; `grid`, the record grid |
| `--default` | Make it the default view of its kind (the first view of a kind always is) |

```bash
symfony console metadata:view:save contact main layouts/contact.html
echo '<div class="row">{city}</div>' | symfony console metadata:view:save contact compact - --default
echo 'columns: [last_name, first_name, city]' | symfony console metadata:view:save contact list - --kind=grid
```

A form view fails when a token names no field, a field is placed twice, a token is inside a tag,
or the name is invalid. A grid view reports every error of its definition at once.

## `metadata:view:delete`

```
metadata:view:delete [--kind=KIND] <object> <name>
```

Deleting the default view leaves the object without one: its form goes back to the plain layout,
its grid to a column per field, until another view is marked as default.

## `metadata:form:save`

Create a composite form, or replace the definition of an existing one (see
[Composite forms](forms.md) for the definition).

```
metadata:form:save <name> <file>
```

| Argument | Description |
|---|---|
| `name` | The form's machine name; the form opens at `/f/<name>/new` |
| `file` | The file holding the YAML definition, `-` for the standard input |

Fails with the list of every error of the definition: an unknown object or field, a `via` that is
not a relation pointing at the parent's object (the candidates are suggested), an unknown key...

## `metadata:form:delete`

```
metadata:form:delete <name>
```

Deletes the definition and the translations of its texts: the records the form wrote stay.

## `metadata:translation:set`

Set the text of a key in a language; without a text, remove it (see [Languages](i18n.md) for
the keys and the rules).

```
metadata:translation:set <locale> <key> [<text>]
```

| Argument | Description |
|---|---|
| `locale` | One of the enabled languages (`en`, `it`) |
| `key` | The key: `object.<object>.plural`, `field.<object>.<field>`, `option.<object>.<field>.<value>`, `form.<form>`, `text.<name>`... |
| `text` | The text; omitted or empty: remove it |

```bash
symfony console metadata:translation:set it field.pet.name Nome
symfony console metadata:translation:set en text.sign_in "Sign in"      # create a text, for {t:sign_in}
symfony console metadata:translation:set it text.sign_in Accesso
symfony console metadata:translation:set it field.pet.name              # back to the English label
```

Fails for a key the metadata does not have, a key of the metadata in the default language (its
label is the text: rename the label instead), a language not enabled, a text translated before
it is defined, or a text still placed by a `{t:name}` token when it is removed.

## `metadata:translation:list`

```
metadata:translation:list [--missing] [<locale>]
```

Every key of the metadata, then the texts, with the default-language text and, for another
language, its translation. `--missing` keeps the keys not translated yet. Without a language: the
default one.

## `metadata:rename`

Give an object, or one of its fields, a new machine name. Its table or column is renamed (the
records stay), and so is everything named after it: relations, conditions, cascades, views,
composite forms, translations, permissions. See [Renaming](lifecycle.md#renaming).

```
metadata:rename [--field=FIELD] <object> <new-name>
```

```bash
symfony console metadata:rename profile person
symfony console metadata:rename person town --field=city
```

The definitions written by hand that were rewritten are listed after the success message: a YAML
one (grid view, composite form) loses its comments.

## `metadata:archive`

Take an object, or a field with `--field`, out of the application; its table or column and its
data stay. Refused, with every use listed, while the metadata still uses it. `--restore` brings it
back. See [Archiving](lifecycle.md#archiving).

```
metadata:archive [--field=FIELD] [--restore] <object>
```

```bash
symfony console metadata:archive pet --field=chip_code
symfony console metadata:archive pet --field=chip_code --restore
```

## `metadata:purge`

Delete an archived object, or an archived field with `--field`, for good: its table or column and
every record or value, its translations (and, for an object, its permissions). It cannot be undone,
so the command asks to type the name back (`pet`, or `pet.chip_code`); without a terminal it needs
`--force`. See [Purging](lifecycle.md#purging).

```
metadata:purge [--field=FIELD] [--force] <object>
```

## `metadata:list`

List the objects, or the fields of one object, as the application sees them (from the metadata
cache), followed by what is archived.

```
metadata:list [<object>]
```

```bash
symfony console metadata:list
symfony console metadata:list pet
```

```
 ------ --------- ------- --------
  Name   Label     Table   Fields
 ------ --------- ------- --------
  pet    Animali   c_pet   3
 ------ --------- ------- --------
```

With an object, every field with its type, label, required and unique flags and resolved settings
(defaults included), then the sets of fields unique together.

## `metadata:template:export`

Write objects, with their fields and views, composite forms and their translations as a
template: a directory of YAML documents (see [Templates](templates.md)).

```
metadata:template:export <name> <release> <directory> [--object=OBJECT]... [--form=FORM]... [--label=LABEL] [--description=DESCRIPTION] [--license=LICENSE]
```

| Argument or option | Meaning |
|---|---|
| `name` | The template's name, `vendor/name` (`acme/shop`) |
| `release` | Its version, `major.minor.patch` (`1.0.0`) |
| `directory` | A new or empty directory to write it into |
| `--object` | An object to export, with its fields and views (repeatable) |
| `--form` | A composite form to export; its objects must be exported too (repeatable) |
| `--label`, `--description` | The title (default: from the name) and a line about the template |
| `--license` | An SPDX identifier, such as `MIT` (default `proprietary`) |
| `--seed` | Export the records of an object as a seed: `object`, or `object:key` to name its key field (default: its first unique field). Repeatable |

```bash
symfony console metadata:template:export acme/shop 1.0.0 templates/shop --object=category --object=product --form=catalogue --license=MIT
```

Records in `core.template_item` the id each item received, so that the next export of the same
template gives it the same id, renamed or not. Fails when an exported item points at an object
that is not exported, when a form writes one, or when the name or the version is invalid.

## `metadata:template:validate`

Check a template's structure without a database: the schemas, the settings of every field type,
ids, names and references (see [Templates](templates.md#validating-a-template)).

```
metadata:template:validate <directory>
```

Lists every problem with its file and where in it, and fails when there is one. Changes nothing.

## `metadata:template:install`

Install a template, from a directory or downloaded from an index, in one transaction (see
[Templates](templates.md#installing-a-template)).

```
metadata:template:install <template> [--dry-run] [--repo=NAME]
```

| Argument or option | Meaning |
|---|---|
| `template` | The directory of the template, or its name in an index: `acme/shop` (its newest version) or `acme/shop@1.0.0` |
| `--dry-run` | Go through the whole install, then roll it back: nothing is changed |
| `--repo` | The index to download it from, when several offer it |

```bash
symfony console metadata:template:install templates/shop --dry-run
symfony console metadata:template:install templates/shop
```

Prints what it created (objects, fields, views, forms, translations), and the languages it left
out. Writes the objects, fields, views, forms and translations through the metadata API (tables,
columns, indexes and permissions included), then `core.template_installation` and
`core.template_item`. Fails, changing nothing, when the template is invalid, already installed,
requires another template, or names an object or a form this instance already has; or when the
app refuses one of its items.

## `metadata:template:upgrade`

Upgrade an installed template to a new version, from a directory or downloaded from an index
(see [Templates](templates.md#upgrading-a-template)).

```
metadata:template:upgrade <template> [--strategy=keep|overwrite] [--dry-run] [--repo=NAME]
```

| Argument or option | Meaning |
|---|---|
| `template` | The directory of the new version, or its name: `acme/shop` (the newest version, looked for first in the index it was installed from) or `acme/shop@2.0.0` |
| `--repo` | The index to download it from, instead |
| `--strategy` | For an item both the new version and this instance changed: `keep` this instance's version, or `overwrite` it with the new one. Without it, such conflicts stop the upgrade |
| `--dry-run` | Go through the whole upgrade, then roll it back: nothing is changed |

Lists the changes applied (`objects/product.yaml: change field acme.product.sku`,
`seeds/product.yaml: update record OSL-01`, `template.yaml: migration 2.0.0: copy_values ...`),
then the conflicts kept. Renames objects and fields, creates the new ones, changes labels, settings,
conditions, search, record labels and unique sets, saves views and forms, sets translations,
runs the migration steps, writes the records of new and `managed` seeds and deletes the ones
removed, deletes the views and forms the new version removed and archives its removed fields and
objects; then records the new version in `core.template_installation`. Fails, changing nothing,
when the template is not installed, the version not newer or its number does not fit the
changes, on a conflict without `--strategy`, on a change the app cannot make to an existing item (a field's type, required or
unique, an object's icon), or when the app refuses a step.

## `metadata:template:pack`

Check a template and pack it into a `.tar.gz` archive; with `--index`, list it in an index file
(see [Templates](templates.md#publishing-and-downloading)).

```
metadata:template:pack <directory> <archive> [--index=FILE] [--url=URL]
```

| Argument or option | Meaning |
|---|---|
| `directory` | The directory of the template |
| `archive` | The archive to write, e.g. `acme-shop-1.0.0.tar.gz` |
| `--index` | An `index.json` to add this version to (created if missing; the same version is replaced) |
| `--url` | Where the archive will be served; default: its file name, next to the index |
| `--sign` | A file holding the secret key (`metadata:template:keygen`) to sign the entry with; needs `--index` |

Prints the archive's sha256. Refuses an invalid template, with every problem.

## `metadata:template:keygen`

```
metadata:template:keygen <file>
```

Makes an Ed25519 key pair: writes the secret key to the file (readable by its owner only; an
existing file is never overwritten) and prints the public key, for `repo add --key`.

## `metadata:template:yank`

```
metadata:template:yank <index> <name> <version> [--sign=FILE] [--undo]
```

Marks a version of an index file yanked: new installs and upgrades skip it, naming it still
installs it. `--undo` lists it again. A signed entry needs the secret key (`--sign`): the
signature covers the flag.

## `metadata:template:repo`

```
metadata:template:repo [list]
metadata:template:repo add <name> <url> --key=<public key>
metadata:template:repo add <name> <url> --unsigned
metadata:template:repo remove <name>
```

Lists the indexes templates are downloaded from, and whether they are signed (the template
catalog first, when `TEMPLATE_CATALOG_URL` and `TEMPLATE_CATALOG_KEY` are set); adds one; or
removes one. Adding reads its `index.json` first, which must be valid and served over https;
with `--key`, every entry must be signed with it. Without a key, `--unsigned` is required: the
index's checksums are then all that is checked. Stored in `core.template_source`.

## `metadata:template:search`

```
metadata:template:search [term]
```

Lists the newest version of every template whose name holds the term, in every index, with its
index and the version installed here ("upgrade available" when it is older).

## `metadata:template:list`

List the installed templates: name, version, date, and where each was installed from.

```
metadata:template:list
```

## `record:import`

Import the rows of a CSV file into an object, all or nothing: every row goes through the same
rules as the form (required, unique, types, patterns, conditions); when one row is refused,
nothing is written, and every refused row is listed with its line.

```
record:import <object> <file> [--key=FIELD] [--match=FIELD:TARGET_FIELD]... [--dry-run]
```

| Argument or option | Meaning |
|---|---|
| `object` | The object the records belong to |
| `file` | The CSV file: a header of field names, then one record per line (comma-separated, quotes doubled inside quotes, UTF-8) |
| `--key` | A unique field: a row whose value a record already holds updates that record; the others create one. Without it, every row creates a record |
| `--match` | `field:targetField`: the cells of a relation or many-to-many field hold values of `targetField`, a unique field of the target, instead of record ids (repeatable) |
| `--dry-run` | Go through the whole import, then roll it back: nothing is changed |

```bash
symfony console record:import region regions.csv --key=code
symfony console record:import province provinces.csv --key=code --match=region:code
```

- An empty cell is no value: a new record takes the field's default, an existing one is
  cleared.
- Several values in one cell (a many-to-many field, a list of files) are separated by `|`.
- A file cell holds a path relative to the CSV file's directory; a path leaving it is refused.
  Files are checked and stored as uploads are; a dry run stores them before rolling back,
  leaving them to `metadata:files:gc`.
- Refused before anything is written: a column naming no field, a row without its key, two rows
  with the same key, a `--key` or `--match` naming no unique field.

## `metadata:files:gc`

Delete the stored files that nothing references any more, with their bytes and thumbnails. A
file is referenced while a [`file`](field-types.md#file) field of a record holds it, one file or
in a list: records in the bin and archived fields count, since both can come back. Replacing,
removing or unlinking a file, or purging a field or an object, leaves its file to this command;
so does an upload whose record could not be saved.

```
metadata:files:gc [--dry-run] [--older-than=DURATION]
```

| Option | Meaning |
|---|---|
| `--dry-run` | List what would be deleted, delete nothing |
| `--older-than` | Keep the files stored more recently than this, such as `30m`, `24h` (default) or `7d`. A file is stored before its record is saved: the grace period keeps the uploads in progress |

```bash
symfony console metadata:files:gc --dry-run
symfony console metadata:files:gc --older-than=7d
```

The deleted files are listed (id, name, size, date stored), then a summary: the files, the
stored contents freed with their size, and the thumbnails. The same bytes uploaded twice are
two files and one stored content: the bytes go with the last file using them. Bytes left
without a file (a write rolled back after its upload) are deleted as well; anything else in the
storage is left alone.

Run it on a schedule, for example every night from cron:

```
30 3 * * * cd /srv/app && php bin/console metadata:files:gc --no-interaction
```

## `schema:sync`

Align the record tables with the metadata. The `metadata:*` commands already do it for the table
they change; run it by hand after restoring a database, to check for drift, or to repair a table.

```
schema:sync [--dry-run] [--allow-destructive] [<objects>...]
```

| Argument / option | Description |
|---|---|
| `objects` | The objects to synchronize; all of them when omitted |
| `--dry-run` | Print the SQL without executing it |
| `--allow-destructive` | Also drop or alter what differs from the metadata |

```bash
symfony console schema:sync --dry-run          # what would change, for every object
symfony console schema:sync pet                # apply, for one object
symfony console schema:sync pet --allow-destructive
```

```
pet (data.c_pet)
----------------

 * ALTER TABLE data.c_pet ADD owner UUID DEFAULT NULL
 * ALTER TABLE data.c_pet ADD CONSTRAINT fk_c_pet_owner FOREIGN KEY (owner) REFERENCES data.c_contact (id) ON DELETE SET NULL
 * CREATE INDEX idx_c_pet_owner ON data.c_pet (owner)

 Executed.
```

**Destructive changes are never applied by default.** A column or an index the metadata does not
define is kept, a column whose definition differs is not altered, and each one is reported as a
warning:

```
 [WARNING] Column "legacy_code" is not defined in the metadata: not dropped. Use --allow-destructive to apply it.
```

With `--allow-destructive` they are applied: a dropped column loses its data. Trigram search
indexes and unique-together indexes hold no data and always follow the search configuration and
the sets unique together, both ways.

Every applied change is logged in `core.schema_change_log` (object, table, statements, skipped
changes, date).

---

## Make targets

| Target | What it does |
|---|---|
| `make up` / `make down` | Start / stop the PostgreSQL container |
| `make db` | Create the dev database and the `core` schema, then run the migrations |
| `make db-test` | The same for the test database |
| `make assets` | Compile the Sass stylesheets |
| `make cs` / `make cs-fix` | Check / fix the coding standard |
| `make phpstan` | Static analysis, level max |
| `make deptrac` | Check layer and module boundaries (two rulesets) |
| `make test` | The test suite (starts the database and builds the styles first) |
| `make qa` | All of the above checks |

The `core` schema (definitions, logs, settings) is managed by Doctrine migrations; the `data`
schema (record tables) only by the commands above. Doctrine's schema tools ignore `data`, so
`doctrine:migrations:diff` never proposes to drop a record table.
