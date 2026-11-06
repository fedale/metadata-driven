# Templates

A template packages a model: objects with their fields, their form and grid views, composite
forms, their translations, and records to start with (seeds). You write one from the objects you built, keep it in version
control, share it, validate it without a database, and install it on another instance.

**Today** a template can be exported, validated, installed (records included) and upgraded to a
new version. Upgrading seeds, templates requiring other templates and the template catalog come
next (see [Not yet](#not-yet)).

## Exporting a model

```bash
symfony console metadata:template:export acme/shop 1.0.0 templates/shop \
  --object=category --object=tag --object=product --form=catalogue \
  --label="Shop" --description="Products, their categories and their tags." --license=MIT
```

This writes the template into `templates/shop`, a directory that must not exist yet or be empty:

```
templates/shop/
  template.yaml                      # the manifest: name, version, license, what it requires
  objects/category.yaml
  objects/product.yaml
  objects/tag.yaml
  views/product.form.main.yaml
  views/product.grid.main.yaml
  forms/catalogue.yaml
  translations/en.yaml               # the {t:name} texts of the layouts
  translations/it.yaml               # the labels in Italian, and the texts
```

| Argument or option | Meaning |
|---|---|
| `name` | The template's name, `vendor/name`: lowercase letters, digits and hyphens (`acme/geo-italy`) |
| `release` | Its version, `major.minor.patch` (`1.0.0`) |
| `directory` | Where to write it |
| `--object` | An object to export, with its fields and views. Repeat it for each object |
| `--form` | A composite form to export. The objects it writes must be exported too |
| `--label`, `--description` | The title of the template (default: from its name) and a line about it |
| `--license` | An SPDX identifier such as `MIT` (default `proprietary`) |
| `--seed` | Export the records of an object as a seed: `category`, or `category:title` to name its key (default: its first unique field). Repeat it for each object |

With `--seed`, the records of the object go into `seeds/<object>.csv`, each relation written as
the key of its record and each file copied into `files/<object>/<file id>/<name>`. Every object
its records point at needs its own `--seed`: `The records of "product" point at records of "tag"
(tags): export its records too.` Every record needs its key, and passwords are left out (their
hash is never read back).

An export is refused when an exported item points outside it: a relation or a many-to-many field
whose target is not exported (`Object "product" points at "category" (category.target), which is
not exported: add it to the objects.`), or a form writing an object that is not exported.
Archived fields are left out.

## The format

Every file is a YAML document with the same four keys:

```yaml
format: metadata-driven/v1        # the version of the format
kind: Object                      # Template, Object, Form, View or Translations
id: acme.product                  # the item's identity in the template
spec:                             # the item, as its kind describes it
  name: product
  label: Product
  plural: Products
  label_field: acme.product.name
  fields:
    - id: acme.product.name
      name: name
      type: text
      label: Name
      required: true
    - id: acme.product.category
      name: category
      type: relation
      label: Category
      settings:
        target: acme.category
        onDelete: restrict
```

- **Ids, not names.** Inside the app, items point at each other by name; in a template, by id:
  a relation's `target`, a condition's `field`, the `label_field`, the search fields, the
  `object` and `via` of a composite form. An id is made of the template's vendor and the item's
  names (`acme.product.name`) the first time an item is exported, and the app remembers it:
  rename the field to `title` and the next export still calls it `acme.product.name`, with
  `name: title`. That is how an upgrade will tell a renamed field from a new one.
- **Only what differs from the defaults.** A field's `required` and `unique` are written when
  true, its settings when they differ from their default: the file stays short enough to read
  and edit.
- **Views and forms as the app stores them.** A form view's `template` is its HTML layout, a grid
  view's is its YAML, both written as literal blocks; inside them, fields are named by name,
  within their object. A composite form's `definition` is the YAML it was saved with, `object`
  and `via` turned into ids (its comments are lost).
- **Translations, per language.** The labels in every language but the default one (in the
  default language the metadata itself is the text), and the `{t:name}` texts the exported
  layouts use, in every language.

The JSON Schema of each kind is in `src/TemplateFormat/Resources/schema/v1/`: point your editor
at it for autocompletion. The settings of each field type are described in
`src/TemplateFormat/Resources/field-types.v1.json`.

## Seeds

A seed holds records of one object, written when the template is installed: reference data
(countries, categories) or examples. `metadata:template:export --seed` writes them from the
records of the instance. To load a CSV file into an object outside templates, use
[`record:import`](console.md#recordimport). One `Seed` document per object, in `seeds/`:

```yaml
format: metadata-driven/v1
kind: Seed
id: acme.seed.category
spec:
  object: acme.category          # the object, by id
  key: title                     # a unique field: it names each record
  records:                       # inline...
    - { title: Chairs }
    - { title: Tables }
```

```yaml
format: metadata-driven/v1
kind: Seed
id: acme.seed.product
spec:
  object: acme.product
  key: sku
  source: seeds/products.csv     # ...or in a CSV file of the package
```

```csv
sku,name,kind,seats,category,tags,photo
OSL-01,Oslo chair,chair,4,Chairs,new|wood,files/products/oslo.png
BRG-02,Bergen table,table,,Tables,wood,
```

- **Fields by name.** Inline, each record maps field names to values; a CSV file has a header
  of field names, then one record per line (quotes as spreadsheets write them; UTF-8, a byte
  order mark is fine). An empty cell, or a field left out, is no value: the field's default
  applies.
- **References by key.** A relation holds the key of a record of the target's seed (`Chairs`);
  a many-to-many field a list of keys, separated by `|` in a CSV cell. The target object must
  have a seed in the template. A record may point at a record of its own seed written after it
  (a category's parent): it is set once the whole seed is written.
- **Files from the package.** A file field holds a path inside the package
  (`files/products/oslo.png`); a field of several files, paths separated by `|`. Each file is
  checked and stored as an upload is: its content decides its type.
- **`mode`**: `once` (the default) or `managed`. Both are written at install. Once upgrades
  exist, a `managed` seed will be kept in line with new versions of the template, `once` records
  will belong to the user.

`metadata:template:validate` checks the seeds too: the key is a unique field, every record has
one and only one record has it, every column names a field, every reference names a record of
the target's seed, every file is in the package. Whether a value fits its field (a number, a
pattern) is checked at install, record by record, with the rules of the object; a refused
record names its line (`seeds/product.yaml: seeds/products.csv:3: sku: ...`) and rolls back the
whole install.

## Validating a template

```bash
symfony console metadata:template:validate templates/shop
```

```
 [OK] Template acme/shop 1.0.0 follows the format (8 documents).
```

It checks the structure only, without a database:

- each file's envelope and its spec against the schema of its kind;
- every field's type, and its settings against that type: unknown settings, values out of
  range, choices outside their values;
- ids and names: unique ids per kind, field ids unique in the template, one object or form per
  name, one view per object, kind and name;
- every reference: an object id must name an object of the template, a field id a field of the
  right object (a cascade's `dependsVia` a field of the target, a junction's `throughSource` a
  field of the junction object).

Each problem names its file and where in it:

```
objects/product.yaml: spec.fields.5.settings.target: No object of the template has the id "acme.colour".
objects/product.yaml: spec.fields.6.settings.maxSize: Must be between 1 and 100.
```

Whether a composite form fits its objects, or a grid view names fields it can show, is checked
by the app when the template is installed.

## Installing a template

```bash
symfony console metadata:template:install templates/shop --dry-run   # check, change nothing
symfony console metadata:template:install templates/shop
symfony console metadata:template:list                                # what is installed
```

The install goes through the same rules as the admin: every object gets its table, every field
its column, every form and view is compiled against the objects. It runs in one transaction:
when the app refuses one step, nothing of the template is left behind. `--dry-run` goes through
the whole install, then rolls it back, so it finds everything a real install would.

It is refused, before anything is written, when:

- the template does not follow the format (the same checks as `metadata:template:validate`);
- it is already installed (`acme/shop is already installed, in version 1.0.0.`): upgrading
  comes later;
- this instance already has an object or a form with one of its names, archived ones included:
  an install never touches items it did not create;
- a translation names something the template does not bring: the label of a local object
  (`Names no object or form of the template.`), or a `{t:name}` text this instance already has
  (`This instance already has the text "identity".`);
- it requires another template (`spec.requires`): not supported yet.

A step the app refuses is reported on its document, e.g.
`views/product.form.main.yaml: spec: …`, with the app's own message.

The records of the seeds come last, once every table and field exists. A dry run writes them
too before rolling back: the files they upload are left in the storage, without a record, for
[`metadata:files:gc`](console.md#metadatafilesgc).

Translations in a language this instance does not have are left out, with a warning; the rest
is installed. The permissions of the new objects (`record.<object>.read`, …) are created with
them; give them to the users who need them (see [Users and permissions](access.md)).

The instance records what it installed: the version, where it came from, the documents as they
were (the base of a future upgrade), and the id each object, field, view and form has in the
template. Exporting the same objects again under the same name gives the template back, ids
included.

## Upgrading a template

```bash
symfony console metadata:template:upgrade templates/shop-1.1.0 --dry-run
symfony console metadata:template:upgrade templates/shop-1.1.0
```

An upgrade applies only what the new version changed, and keeps what was changed on this
instance. It compares, item by item (an object's labels and settings, each field, each view,
each form, each translation), three states: the version installed, the new one, and what the
instance holds now.

| The new version... | This instance... | The upgrade |
|---|---|---|
| changed the item | did not | applies the new version |
| did not | changed it | keeps this instance's |
| changed it | changed it too, differently | stops: a **conflict** |
| added an item | — | creates it |
| removed an item | did not change it | removes it: a view or form is deleted, a field or object archived (its data kept) |

- **Renames keep the data.** Items are matched by their id, not their name: a field renamed in
  the new version is renamed here, its column, values and every reference following.
- **Conflicts** stop the upgrade, listed, unless you choose: `--strategy=keep` keeps this
  instance's version of each, `--strategy=overwrite` takes the new one. A conflict resolved
  once is not asked again: the next upgrade compares against the version installed now.
- **What the template does not own is never touched**: a field you added to one of its objects,
  your own objects, views and forms.
- **All or nothing**, as an install: one transaction, a step the app refuses rolls it all back,
  and `--dry-run` goes all the way then rolls back.
- **Refused before anything changes**: a version that is not newer than the installed one; a
  new object or form named like one of this instance's; and the changes the app cannot make to
  an existing item yet: a field's type, whether it is required or unique, an object's icon, a
  field moved to another object (`objects/product.yaml: field acme.product.seats: its type
  changed from number to text, which an upgrade cannot do yet: change it by hand first.`).
  The app's own rules apply too: a setting fixed once a field exists (a text's `maxLength`)
  cannot change.

## Not yet

- **Upgrading seeds**: keeping `managed` records in line with a new version; the `migrations`
  steps of the manifest (moving values from a field to another); checking that the version
  number fits the changes (semver).
- **Templates requiring other templates**: a template pointing at the objects of another
  installed one.
- **The template catalog**: publishing and downloading templates, signed.

See the plan in [internal/template-plan.md](internal/template-plan.md).
