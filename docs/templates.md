# Templates

A template packages a model: objects with their fields, their form and grid views, composite
forms, and their translations. You write one from the objects you built, keep it in version
control, share it, validate it without a database, and install it on another instance.

**Today** a template can be exported, validated and installed. Upgrading it from one version to
the next, seed data, templates requiring other templates and the template catalog come next (see
[Not yet](#not-yet)).

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

Translations in a language this instance does not have are left out, with a warning; the rest
is installed. The permissions of the new objects (`record.<object>.read`, …) are created with
them; give them to the users who need them (see [Users and permissions](access.md)).

The instance records what it installed: the version, where it came from, the documents as they
were (the base of a future upgrade), and the id each object, field, view and form has in the
template. Exporting the same objects again under the same name gives the template back, ids
included.

## Not yet

- **Upgrading** an installed template to a new version: only what changed is applied, local
  changes are kept, conflicts are reported item by item.
- **Seeds**: records shipped with a template (reference data such as countries, sample records),
  files included.
- **Templates requiring other templates**: a template pointing at the objects of another
  installed one.
- **The template catalog**: publishing and downloading templates, signed.

See the plan in [internal/template-plan.md](internal/template-plan.md).
