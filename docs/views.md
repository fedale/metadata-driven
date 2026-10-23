# Views

A view is a layout for an object's page, stored with the metadata. Today a view lays out the
**record form**: it decides where each field goes, in groups and columns, instead of one field
after another. A view is HTML with a `{field}` token where each field goes:

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
- The fields the layout does not place are shown **after** it, in their usual order. A layout
  can name only the fields that matter, and the rest still shows up.
- A token goes in the text of the HTML, never inside a tag: `<div class="{title}">` is refused.

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

An object can have several views. Each view has a **kind**, which says where it is used (today
only `form`), and a name that is unique within its kind.

- `/o/<object>/new` and `/o/<object>/<id>/edit` use the object's **default** form view.
- `?view=<name>` uses another one, e.g. `/o/book/new?view=quick`. When no view has that name, the
  page answers 404. The query string stays on the form's URL, so the form is submitted with the
  same view and shows its errors there.
- When the object has no default view, the form shows the fields one after another, as before
  views existed.

The default is set as follows:

- The first view of a kind becomes its default.
- Marking another view as default (the **Default view** checkbox, or `--default` from the
  console) takes the flag off the previous one. Unticking the box on the default view does not
  remove the flag: mark another view instead.
- Deleting the default view leaves the object without one, until another view is marked as
  default.

A user who may read the records but not update them sees the same layout, with every input
disabled.

## Rules checked on save

| Rule | Error |
|---|---|
| The name is a machine name: 2 to 50 lowercase letters, digits and single underscores, starting with a letter | `Invalid name "Main view"...` |
| The layout is not empty, at most 20,000 characters | `This value should not be blank.` |
| Every token names a field of the object | `View "main": object "book" has no field "nickname".` |
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
```

See [`metadata:view:save`](console.md#metadataviewsave) and
[`metadata:view:delete`](console.md#metadataviewdelete) for every option.

## Under the hood

| Piece | Where |
|---|---|
| Storage | `core.metadata_view`: `object_id` (cascade on delete), `kind`, `name`, `template`, `is_default`; unique on `(object_id, kind, name)` |
| Model | `ViewDefinition`, part of the `ObjectDefinition` aggregate like the fields; `ObjectDefinition::saveView()` checks the tokens and keeps one default per kind |
| Sanitizing | `SaveViewHandler`, with the `view_layout` sanitizer of `config/packages/html_sanitizer.yaml` |
| Read side | `ObjectDefinitionDto::$views` and `view(ViewKind $kind, ?string $name)`, served from the cached metadata snapshot; a change drops the cache like any metadata change |
| Rendering | The Twig function `record_form_layout(form, template)` (`RecordFormLayout` in the Record module), called before `form_rest()` in `templates/record/form.html.twig` |
| API | `MetadataManagerInterface::saveView()` / `deleteView()`, the same calls the admin UI uses |

Layouts are not Twig. Only the `{field}` tokens are replaced: `{{ }}`, `{% %}`, filters and
functions are kept as plain text. A layout written in the admin UI therefore cannot run code on
the server.

## Not yet

- Views of other pages: the record detail, the grid (visible columns, virtual columns), cards.
  `ViewKind` is the place where such kinds will be added.
- Renaming or deleting a field: neither exists yet. When it arrives, the views that place the
  field will have to follow.
