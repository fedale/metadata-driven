# Views

A view is a layout for an object's page, stored with the metadata. There are two kinds:

- a **form view** lays out the record form: HTML with a `{field}` token where each field goes;
- a **grid view** configures the record grid: which columns, in which order, how they are drawn,
  virtual columns joining several fields, the initial sort and the page size. See
  [Grid views](#grid-views).

A form view decides where each field goes, in groups and columns, instead of one field after
another:

```html
<div class="md-columns">
    <fieldset>
        <legend>Book</legend>
        {title} {isbn} {summary}
    </fieldset>
    <fieldset>
        <legend>Sale</legend>
        {price} {published_on}
    </fieldset>
</div>
```

Views are defined at runtime, from the admin UI or the console, like objects and fields. No
deploy is needed.

## A first view

Take a `book` object with the fields `title`, `isbn`, `summary`, `price` and `published_on`.

**From the admin UI:** open `/admin/objects/book`, then **Views → Add a view**. Give it a machine
name (`main`), keep *Used for: the record form*, paste the layout and save. The help under the
layout lists the tokens of the object. After saving, **Edit** on the view has a preview link: a
new book form that uses this view.

**From the console:** save the layout in a file, then:

```bash
symfony console metadata:view:save book main layouts/book.html
```

Open `/o/book/new`: the form follows the layout.

## Writing a layout

### Tokens

- A token is the machine name of a field between braces: `{title}`. Spaces inside the braces are
  allowed: `{ title }`.
- A token is replaced by the whole **row** of the field: its label, its input, its help and its
  errors. The row is the same as in the plain form, so the field types work in a layout the same
  way: the autocomplete of a relation, the date picker, the choices of a select.
- Each field may appear **once**.
- To read the machine names off a form, open it as an administrator and press **Machine
  names** in its header: each label shows its name next to it.
- The fields the layout does not place are shown **after** it, in their usual order. A layout
  can name only the fields that matter, and the rest still shows up.
- A token goes in the text of the HTML, never inside a tag: `<div class="{title}">` is refused.
- A `{t:name}` token is not a field: it shows the text `text.<name>` in the user's language
  (`<legend>{t:sale}</legend>`). Define the text before saving the view; see
  [Languages](i18n.md#texts-in-layouts-tname).

### HTML

The layout is sanitized when it is saved, and what is stored is printed as it is. Most
structural and text elements are kept, as these were checked on the `view_layout` sanitizer:

| Kept | Removed |
|---|---|
| `div`, `section`, `fieldset`/`legend`, `details`/`summary`, headings, `p`, `ul`/`ol`/`li`, `table`, `strong`, `em`, `span`, `hr`, `br`, `a` (http/https links), `img` | `script`, `style`, `iframe`, `form`, `input`, `button` (it would submit the form) |
| the `class`, `id`, `title` and `alt` attributes | `style`, `on*` event attributes (`onclick`...), `data-*` attributes, `javascript:` links |

**Edit** on a view shows the layout as it was stored, so you can see what the sanitizer removed.

### Styling

The layout is placed in a `<div class="md-view-layout">` inside the form. The stylesheet gives a
layout these tools:

- `<fieldset>` with a `<legend>`: a framed group of fields;
- `class="md-columns"`: responsive columns, as many as fit side by side (at least 18rem
  each), one below the other on a narrow screen.

The fields themselves are rendered by the form theme of fedale/gridview-bundle, with its
`gv-form-*` classes, and coloured by its `--gv-*` design tokens: see
[Styles](architecture.md#styles). Any other class only has an effect if a stylesheet defines
it: add the rules to `assets/styles/app.css`, under `.md-view-layout`.

## Which view a page uses

An object can have several views. Each view has a **kind**, which says where it is used (`form`
or `grid`), and a name that is unique within its kind.

- `/o/<object>/new` and `/o/<object>/<id>/edit` use the object's **default** form view.
- `?view=<name>` uses another one, e.g. `/o/book/new?view=quick`. When no view has that name, the
  page answers 404. The query string stays on the form's URL, so the form is submitted with the
  same view and shows its errors there. **Save and new** on a new record reopens an empty form
  in the same view.
- When the object has no default view, the form shows the fields one after another, as before
  views existed.
- The grid works the same way, with a path instead of `?view=` (see [Grid views](#grid-views)):
  `/o/<object>` uses the default grid view, `/o/<object>/grid/<name>` another one.

The default is set as follows:

- The first view of a kind becomes its default.
- Marking another view as default (the **Default view** checkbox, or `--default` from the
  console) takes the flag off the previous one. Unticking the box on the default view does not
  remove the flag: mark another view instead.
- Deleting the default view leaves the object without one, until another view is marked as
  default.

A user who may read the records but not update them sees the same layout, with every input
disabled.

## Grid views

A grid view is written in YAML. It lists the columns of the grid, in order:

```yaml
columns:
  - title                                                 # a field: its label, filter and sort
  - { field: price, label: Price (€) }
  - { field: summary, renderer: truncate, options: { length: 60 } }
  - { field: status, renderer: badge, options: { tones: { published: success, draft: warning } } }
  - { field: published_on, renderer: date, options: { format: d/m/Y } }
  - { field: isbn, hidden: true }                          # off until the user turns it on
  - { name: author, label: Author, template: '{first_name} {last_name}' }   # a virtual column
sort: { published_on: desc }                               # optional: the initial order
page_size: 50                                              # optional (default 25)
```

**From the admin UI:** **Views → Add a view**, *Used for: the record grid*, paste the definition.
**From the console:** `metadata:view:save book main grid.yaml --kind=grid`. The admin shows the
definition as it was written, comments included.

### Columns

A column is a field name, or a map:

| Key | Description |
|---|---|
| `field` | The field shown. Its type gives the column its filter, its sort and its rendering |
| `name` + `template` | A **virtual column**: a name of its own (not a field's) and a text with a `{field}` token per field, e.g. `'{last_name}, {first_name}'` |
| `label` | The heading. Default: the label of the field, in the user's language (the name, for a virtual column). It may hold `{t:name}` tokens: `label: '{t:author}'` |
| `renderer` + `options` | How the cells are drawn, instead of the field type's rendering (see below) |
| `hidden` | `true`: the column is in the grid but off; the user turns it on from the **Columns** menu |
| `sortable` | `false`: the heading does not sort. Default `true` |
| `filter` | `false`: no filter for the column. Default `true` |

The fields a view leaves out are not in the grid: unlike a form view, a grid view is the whole
list. A write-only field (a password) cannot be listed. A
[`many_to_many`](field-types.md#many_to_many) field can: it shows the labels of the linked
records and filters on them, but it never sorts (`sortable: true` is refused, and so is a `sort`
on it); its renderer, if any, gets the labels as its text. A [`file`](field-types.md#file) field
follows the same rules: it shows a link to the file (with a thumbnail for an image), filters on
whether there is one, never sorts, and its renderer gets the original name as its text. With
`multiple`, the cell shows each file in turn, and a renderer gets the names joined by ", ".

### Virtual columns

A virtual column shows several fields as one: `'{first_name} {last_name}'` reads "Ada Lovelace".

- It **sorts** on its fields, in the order of the template: last name, then first name for
  `'{last_name} {first_name}'`.
- Its **filter** is a text filter on its fields joined by spaces, empty ones skipped: "lovel"
  and "ada lov" both find Ada Lovelace. A select is matched on its stored value, not its label.
- Its fields cannot be relations, `many_to_many` or `file` fields: show them in a column of
  their own, where they filter on the related records, or on whether there is a file.

### Renderers

A renderer is PHP code registered under a name; a view only picks one and passes its options,
so a view never carries code.

| Renderer | Options | Draws |
|---|---|---|
| `truncate` | `length` (1–1000, default 80) | The text cut to `length` characters with "…"; the whole text in the tooltip |
| `badge` | `tones`: value → `neutral`, `info`, `success`, `warning` or `danger` | A badge (`gv-badge` of fedale/gridview-bundle) with the text shown (a select's label); the tone chosen by the stored value. Dark mode included |
| `date` | `format` (PHP date format, default `d/m/Y`) | A date, or a date and time, formatted |

A developer adds a renderer with a class implementing
`App\Module\Record\UI\Grid\Renderer\CellRendererInterface` (`name()`, `optionErrors()`,
`render()`): it is registered by itself, and accepted by the views from then on. `render()`
returns HTML, so it escapes what it prints.

### One grid per view

- `/o/<object>` shows the **default** grid view; `/o/<object>/grid/<name>` any one. The grid's
  sort, page and filter links stay on that page. (`?view=` is not used for grids: the grid
  itself uses it to switch between table and cards.)
- When an object has several grid views, the page shows them side by side above the grid, the
  default one first.
- Each view other than the default has its own saved searches and column preferences: they
  name columns the other views may not have.
- The export follows the view: its columns, its renderers (as text: "owner…" for a truncated
  e-mail), its virtual columns.
- Without a grid view, the grid shows a column per field, as before views existed.

### Rules checked on save

Every error is reported at once, under the definition:

```
Column #1: object "book" has no field "nickname".
Column "author": "author" is a field of "book": a virtual column needs a name of its own.
Column #3: "renderer" must be one of badge, date, truncate.
"sort": "isbn" is no sortable column of the grid (nor created_at, updated_at).
```

Also refused: a field listed twice, a write-only field, a template without tokens or with a
relation, a `many_to_many` or a `file` field, a `many_to_many` or `file` column marked
`sortable: true`, unknown keys
and renderer options, a sort direction other than `asc`/`desc`, a
`page_size` outside 1–500.

## Rules checked on save (form views)

| Rule | Error |
|---|---|
| The name is a machine name: 2 to 50 lowercase letters, digits and single underscores, starting with a letter | `Invalid name "Main view"...` |
| The layout is not empty, at most 20,000 characters | `This value should not be blank.` |
| Every token names a field of the object | `View "main": object "book" has no field "nickname".` |
| Every `{t:name}` token names a defined text | `View "main": {t:sale} is no text: define "text.sale" in en first (Admin > Translations > Texts).` |
| A field is placed once | `View "main": the field "title" is placed more than once.` |
| Tokens are in the text, not inside a tag | `View "main": {field} tokens go in the text, not inside a tag or an attribute.` |

Saving a view with the name of an existing one (same kind) replaces its layout. A view's name
and kind cannot be changed: create a new view and delete the old one.

## Console

```bash
# Create or replace; the layout comes from a file, or from the standard input with "-"
symfony console metadata:view:save book main layouts/book.html
echo '<fieldset><legend>Quick</legend>{title} {price}</fieldset>' \
    | symfony console metadata:view:save book quick - --default

symfony console metadata:view:delete book quick

# A grid view: --kind=grid, the definition in YAML
symfony console metadata:view:save book main grids/book.yaml --kind=grid
symfony console metadata:view:delete book main --kind=grid
```

See [`metadata:view:save`](console.md#metadataviewsave) and
[`metadata:view:delete`](console.md#metadataviewdelete) for every option.

## Renamed fields

Renaming a field rewrites the views of its object: the `{field}` tokens of a form view (the rest of
the HTML stays as written), the columns, `{field}` tokens and sort of a grid view (its YAML is
written again: its comments are lost). A field placed by a view cannot be archived until the view
no longer places it. See [Renaming and archiving](lifecycle.md).

## Under the hood

| Piece | Where |
|---|---|
| Storage | `core.metadata_view`: `object_id` (cascade on delete), `kind`, `name`, `template` (form: the sanitized HTML; grid: the YAML as written), `definition` (grid: the compiled definition, JSONB), `is_default`; unique on `(object_id, kind, name)` |
| Model | `ViewDefinition`, part of the `ObjectDefinition` aggregate like the fields; `ObjectDefinition::saveView()` checks the tokens and keeps one default per kind |
| Sanitizing | `SaveViewHandler`, with the `view_layout` sanitizer of `config/packages/html_sanitizer.yaml` |
| Grid definitions | `GridViewCompiler` (Metadata) checks and compiles them; renderer names and options are checked through `GridRendererCatalogInterface`, implemented by `GridRenderers` (Record) |
| Read side | `ObjectDefinitionDto::$views` and `view(ViewKind $kind, ?string $name)`, served from the cached metadata snapshot; a change drops the cache like any metadata change. A grid view carries `ViewDefinitionDto::$grid` (`GridViewDto`, `GridColumnDto`) |
| Rendering | Form: the Twig function `record_form_layout(form, template)` (`RecordFormLayout` in the Record module), called before `form_rest()` in `templates/record/form.html.twig`. Grid: `RecordGridFactory::create($object, $view)` builds the columns; `DbalRecordGridSource` filters a virtual column on its fields (passed in the grid's model, checked against the object) |
| API | `MetadataManagerInterface::saveView()` / `deleteView()`, the same calls the admin UI uses |

The record pages read the views through the localized registry, with the `{t:name}` tokens
already replaced in the user's language: see [Languages](i18n.md#under-the-hood).

Layouts are not Twig. Only the `{field}` and `{t:name}` tokens are replaced: `{{ }}`, `{% %}`, filters and
functions are kept as plain text. A layout written in the admin UI therefore cannot run code on
the server.

A composite form is laid out the same way, with a `layout` in its definition: see
[Composite forms](forms.md#layouts).

## Not yet

- Views of other pages: the record detail, cards. `ViewKind` is the place where such kinds
  will be added.
- Grid views: computed columns (counts, sums of related records) and virtual columns over
  relations or many-to-many fields.
- Renaming or deleting a field: neither exists yet. When it arrives, the views that place the
  field will have to follow.
