# Templates

A template packages a model: objects with their fields, their form and grid views, composite
forms, their translations, and records to start with (seeds). You write one from the objects you built, keep it in version
control, share it, validate it without a database, and install it on another instance.

**Today** a template can be exported, validated, packed, published in a signed index, and
installed and upgraded from a directory or by its name, its records included, from your own
indexes or from the [template catalog](#the-template-catalog). A template can require other
templates, installed and upgraded with them; exporting such a template, and the catalog's check
of one, come next (see [Not yet](#not-yet)).

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

The JSON Schema of each kind is in `vendor/fedale/metadata-driven-template-format/src/Resources/schema/v1/`:
point your editor at it for autocompletion. The settings of each field type are described in
`vendor/fedale/metadata-driven-template-format/src/Resources/field-types.v1.json`.

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
- **`mode`**: `once` (the default) or `managed`. Both are written at install. A `managed` seed
  is kept in line with new versions of the template; the records of a `once` seed belong to the
  user from then on (see [Upgrading the records](#upgrading-the-records)).

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

It checks the structure only, without touching the data: the only thing it reads from this
instance is the templates the one checked requires, as installed (see
[Templates requiring templates](#templates-requiring-templates)).

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
- a template it requires is not installed, or is installed in a version its constraint doesn't
  allow (see below).

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

### Templates requiring templates

A template may build on another one: a `registration` template pointing its profiles at the
regions of `fedale/geo-italy`. It names the template it requires in its manifest, with a
version constraint:

```yaml
spec:
  requires:
    format: metadata-driven/v1
    fedale/geo-italy: ^1.0
```

The constraints are `^1.2` (from 1.2.0 up to, not including, 2.0.0; `^0.3` stays below 0.4.0),
`~1.2` (from 1.2.0 up to, not including, 1.3.0) and an exact `1.2.3`.

It then names the required template's objects and fields by their ids, where it would name its
own: a relation's or a many-to-many field's `target`, a cascade's `dependsVia`, a composite
form's `object` and `via`. Its seeds point at the records of the required seeds by their keys,
as they do within a template:

```yaml
# objects/profile.yaml
    - id: fedale.profile.region
      name: region
      type: relation
      label: Region
      settings:
        target: fedale.region              # an object of fedale/geo-italy

# seeds/profile.yaml
  records:
    - { email: ada@example.com, region: '01' }   # the ISTAT code of a region, its seed's key
```

It only references them: it adds no field to a required object, doesn't relabel or translate
it (`Names no object or form of the template.`), and its own ids must differ from those of the
templates it requires.

Named from an index, a template comes with the templates it requires that this instance
doesn't have yet:

```bash
symfony console metadata:template:install fedale/registration --dry-run
```

```
 ! [NOTE] fedale/registration requires templates this instance does not have: they are
 !        installed first, fedale/geo-italy 1.0.0 from
 !        https://fedale.github.io/metadata-driven-templates/index.json.
 …
 [OK] Templates fedale/geo-italy 1.0.0, fedale/registration 1.0.0 can be installed. Dry run: nothing was changed.
```

Each requirement is the newest version its constraint allows, yanked versions left out, found
first in the index the requiring template comes from, then in the others (then only one of
them may offer it). The note says which index each one comes from: a requirement can come
from another index than the template you named, so check you trust it before you go ahead. Its
own requirements follow, as deep as they go, up to 20 templates for one install; templates
requiring each other are refused. They're all installed in one transaction, requirements
first: when one is refused, none is installed, and the reason names it (`fedale/geo-italy
1.0.0: objects/…`). **Admin → Templates** previews the whole chain the same way, with the index
of each.

A requirement installed already is never upgraded along the way: when its version doesn't fit
the constraint, the install is refused, and upgrading it is up to you. A template installed
from a directory comes alone, there being no index to take its requirements from: install them
first. Either way the install checks the requiring template against its requirements as
installed:

```
template.yaml: spec.requires.fedale/geo-italy: fedale/geo-italy is required: it must be installed, or given to check against.
template.yaml: spec.requires.fedale/geo-italy: fedale/geo-italy 2.0.0 does not fit ^1.0.
```

The references are resolved through the ids recorded when the required template was installed,
never by name: a region object renamed `area` since keeps every reference to it. A seed
pointing at a record the user has deleted since is refused, with the key it names.
`metadata:template:list` shows what each template requires.

Upgrades keep to the same rules, in both directions:

- **Upgrading a template that requires others**: its new version is checked against its
  requirements as installed, never upgraded along the way. A new version requiring
  `fedale/geo-italy: ^1.3` while 1.2.0 is installed is refused (`fedale/geo-italy 1.2.0 does
  not fit ^1.3.`): upgrade `fedale/geo-italy` first.
- **Upgrading a template that others require**: the new version is checked against each
  installed template requiring it, as this instance holds that template now. What it would
  break refuses the upgrade, named after the template it breaks: a version past their
  constraint, an object or field they reference removed.

  ```
  fedale/registration 1.0.0, which requires fedale/geo-italy: template.yaml: spec.requires.fedale/geo-italy: fedale/geo-italy 2.0.0 does not fit ^1.0.
  ```

  Removing or renaming an item takes a major version anyway, so a requiring template with a
  `^` constraint waits for its own new version, the one allowing the new major. A rename keeps
  every reference (they're resolved by id), so only removals are named beyond the constraint.

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
  changed from number to text, which an upgrade cannot do yet: change it by hand first.`); a
  requirement as installed that the new version doesn't allow, and a new version that would
  break a template requiring it (see [Templates requiring
  templates](#templates-requiring-templates)).
  The app's own rules apply too: a setting fixed once a field exists (a text's `maxLength`)
  cannot change.

### Upgrading the records

Records are matched by their key.

- A **seed new in this version** is written, as at install: a record whose key this instance
  already has is a conflict.
- A **`managed` seed**: a record the new version added is created, one it changed is updated
  (an empty value then clears its field), one it removed is deleted. A record the user changed
  since it was written (in a field of the template; a field of their own does not count), or
  deleted, is a conflict, resolved by `--strategy` as the other items are.
- A **`once` seed**: nothing happens; its records are the user's.

The instance keeps a fingerprint of every record a seed wrote, as written: that is how it tells
whether the user changed it.

### Migration steps

A change the comparison cannot infer, such as moving values from a field the new version
removes into the field replacing it, is a step of the manifest:

```yaml
spec:
  version: 2.0.0
  migrations:
    '2.0.0':
      - { op: copy_values, from: acme.product.phone, to: acme.profile.phone }   # one object only
```

Steps run once, for every version after the one installed up to the new one, in version order:
after the new fields exist, before the removed ones are archived. `copy_values` copies the values
of a field into another field of the same object, record by record, through the object's rules;
files are not copied. It is the only operation so far.

### Version numbers

The new version's number must fit what it changed since the version installed:

| Version | May |
|---|---|
| Major (`2.0.0` after `1.4.2`) | change anything: remove, rename, carry migration steps |
| Minor (`1.5.0`) | add items, change settings, conditions, search, unique sets |
| Patch (`1.4.3`) | change labels, layouts, forms and translations only |

`objects/tag.yaml: field acme.tag.colour: an addition needs a new minor version, 1.0.1 is not
one.` Who installs a minor version expects to lose nothing.

## Publishing and downloading

A template travels as a `.tar.gz` archive, listed in an **index**: a JSON file any web server can
host, which says, for every version, where its archive is and its checksum.

```bash
# The author, once: a key pair; the secret key stays with the author, out of any repository
symfony console metadata:template:keygen ~/.acme-templates.key
# Every version: pack it, list it in the index (created if missing), signed
symfony console metadata:template:pack templates/shop acme-shop-1.0.0.tar.gz --index=index.json --sign=~/.acme-templates.key
# then put index.json and the archive on a web server, side by side, and publish the public key
```

```json
{
  "format": "metadata-driven/index/v1",
  "templates": {
    "acme/shop": {
      "1.0.0": { "url": "acme-shop-1.0.0.tar.gz", "sha256": "…", "requires": { "format": "metadata-driven/v1" } }
    }
  }
}
```

```bash
# Who installs: add the index once, with the author's public key, then name templates
symfony console metadata:template:repo add acme https://templates.acme.example/index.json --key=<public key>
symfony console metadata:template:search shop
symfony console metadata:template:install acme/shop            # the newest version
symfony console metadata:template:install acme/shop@1.0.0      # a given one
symfony console metadata:template:upgrade acme/shop            # from the index it came from
```

- **Signatures.** An index added with `--key` vouches for every version with an Ed25519
  signature of its name, version and checksum: a version without a valid one is refused, at
  `repo add` (every entry is checked) and again at each download, so an index replaced on its
  server by someone without the secret key installs nothing. An index without signatures is
  added only with `--unsigned`: its checksums then prove an archive is the one it lists, not who
  wrote the index.
- **Not the private network.** Indexes and archives on private, loopback or link-local
  addresses are refused, redirects included: an index cannot make the server request its own
  network. An index on an intranet needs `app.template.allow_private_networks: true` in
  `config/services.yaml`.
- **https only**, redirects included; an index over 5 MB, an archive over 50 MB or files over
  200 MB once unpacked are refused (`app.template.max_download_mb`,
  `app.template.max_unpacked_mb` in `config/services.yaml`).
- **The checksum first.** An archive whose sha256 differs from the one its index lists is refused
  before anything is unpacked. An archive holding anything but files and directories (a link, a
  device), or a path leaving the template (`..`, an absolute path), is refused too; and so is an
  archive whose template is not the name and version its index says.
- **Versions.** Without `@version`, the newest version is taken, skipping the ones the index
  marks `"yanked": true` (withdrawn: still installable by naming them) and those of another
  format.
- **One index per template.** When two indexes offer the same name, say which with
  `--repo=<name>`. An installation records the address of its index, and an upgrade looks there
  first.
- **The template catalog** is an index too, looked into before the others, as `catalog` (see
  [below](#the-template-catalog)).
- `metadata:template:pack` writes reproducible archives: the same template gives the same
  archive and checksum, whatever the machine or the dates of its files.
- **Withdrawing a version**: `metadata:template:yank index.json acme/shop 1.1.0 --sign=<key>`
  marks it yanked (`--undo` lists it again). The signature covers the flag: without the key,
  nobody can list a yanked version again. It does not cover the index as a whole: an old copy
  of the index, from before the version was yanked, still verifies, until the catalog signs its
  indexes with an expiry.
- **In the admin**: `/admin/templates` lists the installed templates and those the indexes
  offer, previews an install or an upgrade, and resolves an upgrade's conflicts item by item
  (see [Admin UI](admin.md#templates--admintemplates)).

## The template catalog

The central catalog is the GitHub repository
[fedale/metadata-driven-templates](https://github.com/fedale/metadata-driven-templates): it
holds the sources of every published version, and publishes them as a signed index on GitHub
Pages. Every instance uses it out of the box: `.env` sets

```bash
TEMPLATE_CATALOG_URL=https://fedale.github.io/metadata-driven-templates/index.json
TEMPLATE_CATALOG_KEY=ta4QYEX/ZmCA9xA0yPP4sRZLeqYqBvmCXazwXiGadEc=
```

The key is the catalog's public key (its `catalog.pub`), not a secret: the catalog is always
signed, and an entry whose signature does not match it is refused. To use another catalog, set
both in `.env.local`; to use none, set both empty (the tests do, in `.env.test`). Then name its
templates as any other:

```bash
symfony console metadata:template:search geo
symfony console metadata:template:install fedale/geo-italy
```

### Publishing to the catalog

Publishing is a pull request to the catalog adding the template's source directory: every pull
request is checked automatically, then reviewed by a maintainer before anything is listed. Your
vendor (`acme` in `acme/shop`) must be registered there first, in a pull request of its own (see
the catalog's README). Then, with a checkout of the catalog up to date next to this repository:

```bash
git clone git@github.com:fedale/metadata-driven-templates.git ../metadata-driven-templates
symfony console metadata:template:export acme/shop 1.1.0 /tmp/shop --object=… --license=MIT
symfony console metadata:template:publish /tmp/shop --dry-run   # what the catalog would refuse
symfony console metadata:template:publish /tmp/shop             # copied into the checkout
```

`metadata:template:publish` runs, against the checkout, what the catalog's check would refuse:
the format, a registered vendor, a template not removed, a version higher than the published
ones whose number fits what it changed (see [Version numbers](#version-numbers): the catalog
applies the same rules), 20 MB at most, no links. Then it copies the directory to
`templates/<vendor>/<name>/<version>/` in the checkout and prints the git commands to propose
it: a branch, a commit, a push, a pull request against `main`. It never runs git itself. Who
owns the vendor is checked by the catalog, which knows the pull request's author.

A `README.md` in the template's directory is copied too: the catalog shows it on the
template's page.

## Not yet

- **Templates requiring templates**, the rest of it: exporting a model whose objects point at
  an installed template's (still refused, as a relation outside the export); the catalog's
  check of such templates. An upgrade never brings in a missing requirement: install it first.

See the plan in [internal/template-plan.md](internal/template-plan.md).
