# Plan: the record form controls are gridview's controls

Status: **phases 0 to 2 done and merged** (2026-11-24); gridview released as v1.4.0 (phase 1)
and v1.5.0 (phase 2). **Phase 3 done on branches** (`remote_relation` in both repositories),
waiting for the gridview release.
Written on 2026-11-23, decisions taken the same day.

- Phase 0: `GoldenRecordFormsTest`, stored copies in `tests/Fixtures/golden-forms/`.
- Phase 1: gridview v1.4.0 (`ControlBuilder`, the control settings, `textarea`, `Length` and
  `Range` when the form validates); metadata-driven requires gridview `^1.4` and builds the simple
  record controls in `RecordFormFactory::gridviewControl()`. The golden forms are unchanged.
- Phase 2: gridview's `password` takes `confirm`, `strengthMeter`, `revealToggle` and `minLength`
  (a `Length` constraint when the form validates); `RecordFormFactory::password()` is gone. The
  golden forms are unchanged. metadata-driven requires gridview `^1.5`.
- Phase 3: gridview's `relation` takes a `url` and a `labels` callable (a `ChoiceType` with
  `RemoteChoiceLoader`, UX Autocomplete a `suggest`), and a cascade with `dependsOn`,
  `dependsParam` and `dependsValue`. `relation` and plain `many_to_many` go through it; the
  `PRE_SUBMIT` listener, `linkChoices()`, `lookupUrl()` and `rowAttr()`'s cascade part are gone.
  The golden forms are unchanged. metadata-driven still requires `^1.5`: raise it once released.
- Not as planned: the date controls keep Symfony's default `widget` in gridview (changing it would
  change every host on Symfony 6.4); metadata-driven passes `single_text` itself. The control
  settings sit beside `options`, not inside it (NumberType has a `scale` option of its own); so do
  the remote relation's `url`, `labels` and `depends*` (section 3 shows them in `options`). The
  cascade also takes `dependsValue`, the current value, so the search starts filtered.

Decided by the user on 2026-11-23: the form controls of metadata-driven are to be **exactly** the
controls of `fedale/gridview-bundle`, its `ControlType` enum and `ControlTypeRegistry`. A control
metadata-driven needs and gridview lacks is built in gridview first, then used here. The first
new one is `tags` ([tags-type-plan.md](tags-type-plan.md), decision 4).

This plan spans both repositories. The gridview work follows that bundle's own rules (its
`CLAUDE.md`): enum case, registry entry and fluent builder together, no CSS-framework classes,
every string translated in every locale, the guides `08_crud.md`, `19_form-components.md` and
`agents.md` updated in the same branch.

## Where we start

The two sides already share the **component layer**. metadata-driven renders its forms with
gridview's form theme (`@FedaleGridview/form/gv_form_theme.html.twig`, in `twig.yaml`), and uses
its Stimulus components: `gridview-conditional` (conditions), `gridview-dependent-select`
(cascades), `gridview-password-strength` and `gridview-password-reveal`, `gridview-collection`
(junction rows), and the `gv-switch` / `gv-segmented` classes.

Gridview's controls are themselves Symfony form types (`ControlTypeRegistry` maps a name to
`TextType`, `ChoiceType`…), so a simple text input already looks the same on both sides: same
form type, same theme. The goal is more than the look. **One implementation**: the name, the
form type, the options, the template and the JavaScript of each control live in gridview, and
the record forms here contain no control of their own.

They don't share the **control vocabulary**. `RecordFormFactory::control()` maps each `WidgetKind`
to a Symfony form type and its options on its own. Gridview maps a `ControlType` name to a form
type in `ControlTypeRegistry`, and `AbstractColumn::buildControl()` adds it to a form. That method
works only from a grid column, so nothing outside a grid can use it today.

| `WidgetKind` (metadata-driven) | Form type here | `ControlType` (gridview) | Gap |
|---|---|---|---|
| `text`, `email`, `url`, `tel`, `integer`, `date` | same Symfony type | same name | Option defaults only (`maxLength` → `maxlength`, `single_text` dates, `default_protocol: null`) |
| `datetime` | `DateTimeType` | `datetime` | Same as above |
| `textarea` | `TextareaType` | `html` → `TextareaType` | Name only (see decision 2) |
| `decimal` | `NumberType`, `step` from the scale | `number` | Name, and the `scale` → `step` option |
| `checkbox`, `display: switch` | `CheckboxType` + `gv-switch` | `boolean` | Name; the switch is an `attr` here, not a control option there |
| `choice`, `display: radio` | `ChoiceType` expanded + `gv-segmented` | `choice` | The display is not a control option there |
| `password` (`confirm`, `strengthMeter`, `revealToggle`) | `PasswordType` / `RepeatedType` + the two controllers | `password` → `PasswordType` | Gridview has the components, not the control options that wire them |
| `relation` (autocomplete, lookup URL, cascade) | `ChoiceType` + UX Autocomplete, choices re-added on submit | `relation` → `EntityType` | **Large**: gridview needs a managed Doctrine entity; records here are DBAL rows |
| `many_to_many` | the same, `multiple` | `relation` with `multiple` | Same as `relation` |
| `many_to_many` through a junction | `JunctionRowsType` (rows of controls) | — | No collection control in gridview |
| `file` (current file, replace, remove) | `RecordFileType` | `media` → `FileType`, unmapped, `upload` callable | **Large**: gridview shows no current file and can't remove one |
| `file`, `multiple` (reorder) | `RecordFileListType` | — | Same as above, plus the list |
| `status` | its type's control, disabled | — | Options only (`disabled`, `help`) |
| `tags` (planned) | — | — | [tags-type-plan.md](tags-type-plan.md) |

Gridview's other controls (`money`, `percent`, `range`, `color`, `time`, `country`, `language`,
`locale`, `timezone`, `currency`, `enum`, `hidden`) have no field type here. "Exactly gridview's"
means the controls used here are a subset of gridview's, never a control of their own. It doesn't
require a field type for every gridview control. `enum` binds a PHP enum class and has no meaning
for metadata.

## Design

### 1. A control without a column (gridview)

A public service `Fedale\GridviewBundle\Form\Control\ControlBuilder`:

```php
$controls->add($builder, 'price', ['type' => 'number', 'required' => true, 'options' => ['scale' => 2]]);
```

- It takes the **same `control` spec** a column takes, normalized by the same `ControlResolver`,
  and holds the body of today's `AbstractColumn::buildControl()`. `buildControl()` delegates to it
  with the column's attribute and label, so the grid's CRUD forms and metadata-driven go through
  one code path.
- This normalizes a *control* spec, not a column spec, so it doesn't break the bundle rule that
  only `ColumnFactory` normalizes column specs. `ColumnFactory` keeps calling `ControlResolver`
  as today.
- **Validation stays where it is on each side.** Gridview adds `NotBlank` for a required control.
  Here the record manager validates and maps its errors back onto the form
  (`RecordFormFactory::addErrors()`). The spec gains `'validate' => false`, which keeps the HTML
  `required` and adds no constraint.

### 2. Control options instead of attributes (gridview)

The settings metadata-driven turns into `attr` today become documented control options, resolved
by gridview:

| Control | Option | Becomes |
|---|---|---|
| text-family | `maxLength` | `attr.maxlength` |
| `number` | `scale` | `attr.step` (`0.01` for 2), no `scale` on the form type (it would round silently) |
| `number`, `integer` | `min`, `max` | `attr.min`, `attr.max` |
| `boolean` | `display: checkbox\|switch` | `gv-switch` + `role="switch"` |
| `choice` | `display: dropdown\|radio\|segmented\|checkboxes` | `expanded`, `gv-choices` / `gv-segmented`, no placeholder when expanded; `checkboxes` is expanded with `multiple` |
| `password` | `confirm`, `strengthMeter`, `minLength`, `revealToggle` | `RepeatedType` and the two controllers, as `RecordFormFactory::password()` does today |
| `date`, `datetime` | (defaults) | `widget: single_text`, `input: datetime_immutable` unless given |

The logic moves from `RecordFormFactory` to gridview. What's left here is translating a field's
settings into a control spec.

### 3. A relation without an entity (gridview)

`relation` gets a second source next to `options.class`. The choice of a single control is open
decision 3.

```php
['type' => 'relation', 'options' => [
    'url' => '/o/municipality/lookup',   // remote: UX Autocomplete loads the choices
    'labels' => $labels,                 // callable(list<string> $ids): array<string, string>
    'multiple' => false,
    'dependsOn' => 'province', 'dependsParam' => 'filter[province]',   // a cascade, optional
]]
```

- Scalar ids in and out, no Doctrine. A `ChoiceType` whose choice loader calls `labels` for the
  current ids, and again on submit for the submitted ones. That replaces the `PRE_SUBMIT`
  listener of `RecordFormFactory::addControls()`, which re-adds the submitted records as choices.
- `dependsOn` puts the `gridview-dependent-select` attributes on the row, as `rowAttr()` does
  today. The server-side check that the record belongs to the deciding one stays with the host.
- `symfony/ux-autocomplete` becomes a `suggest` of gridview, not a requirement. Without it, a
  remote `relation` fails with a clear message.

### 4. Files (gridview `media`)

`media` gains what `RecordFileType` and `RecordFileListType` have: the current file shown (name,
size, a thumbnail for an image), "Replace" and "Remove", and with `multiple` the ordered list with
reorder and remove.

- Gridview doesn't store files. The host gives it what to show through a small DTO
  (`MediaFile`: id, name, size, URL, thumbnail URL) and keeps the storage
  (`FileStorageInterface` here, the `upload` callable for a Doctrine host).
- The templates move from `templates/form/record_fields.html.twig` here into gridview's form
  theme, with `gv-*` classes and `gridview.cls()` keys.

### 5. Rows of a junction (gridview `collection`)

A `collection` control: a `CollectionType` wired to the existing `gridview-collection` controller
and its `gv-collection` markup (`19_form-components.md`), with `min`, `max` and a per-field
`prototype_name`. `JunctionRowsType` becomes this control, its entries built with
`ControlBuilder` for the junction's fields.

### 6. The mapping here

`RecordFormFactory::control()` stops naming Symfony form types. It turns a field into a
gridview control spec and calls `ControlBuilder`. The match on `WidgetKind` stays, but each arm
returns a spec, not a form type.

## Decisions

All four decided by the user on 2026-11-23, as recommended.

1. **`WidgetKind`** stays in `FieldType/Contract`, since the domain shouldn't depend on a UI
   bundle (Deptrac), but its cases take the **values of `ControlType`**: `boolean` for
   `checkbox`, `number` for `decimal`, `relation` for `many_to_many` (with `multiple`), `media`
   for `file`. A test asserts that `ControlType::from($kind->value)` succeeds for every case,
   which turns "exactly gridview's" into a check the build enforces. Rejected:
   `FieldTypeInterface::widget()` returning gridview's `ControlType` directly, which removes the
   mapping but ties the field type contract to the bundle.
2. **`textarea`** is added to gridview as the name of the plain multiline control. `html` is kept
   for a rich text editor (see the [catalog](#the-control-catalog)); until then it's an alias of
   `textarea`, documented as such. metadata-driven uses `textarea`.
3. **One `relation` control, two sources** (a Doctrine entity, or a remote lookup), not a
   separate `lookup` control: it's one concept (a link to another record), and `relation` is
   already the name of the grid column and of the filter.
4. **Releases.** Each gridview phase is tagged before metadata-driven requires it. During
   development the bridge symlink is used (as today), but metadata-driven's `composer.json`
   names a released version at the end of each phase.

## The control catalog

What the shared set should grow into, checked on 2026-11-23 against the interfaces Directus
offers (its interface picker, "Text & Numbers", "Selection", "Relational", "Presentation",
"Groups", "Other"). A control here is a gridview `ControlType`: one name, one form type, its
options, its template in the form theme and its Stimulus controller, the same in a grid's CRUD
form and in a record form.

**In this plan** (needed for the alignment):

| Control | Directus equivalent | Notes |
|---|---|---|
| `textarea` | Textarea | Decision 2 |
| `boolean`, `display: switch` | Toggle | Section 2 |
| `choice`, `display: dropdown\|radio\|segmented\|checkboxes` | Dropdown, Radio Buttons, Checkboxes, Dropdown (Multiple) | `checkboxes` and the multiple dropdown are `multiple: true`, expanded or not |
| `password` with its options | Hash | Section 2 |
| `relation`, entity or remote | Many to One, Many to Many | Section 3 |
| `media`, with `image` and `multiple` | File, Image, Files | Section 4 |
| `collection` | Many to Many through a junction (rows of fields) | Section 5 |
| `tags` | Tags | [tags-type-plan.md](tags-type-plan.md) |

**Candidates next**, each its own small plan, with a field type or a display setting here:

| Control | Directus equivalent | What it would take |
|---|---|---|
| `range` (exists in gridview) | Slider | A `display: slider` on `number` with `min` and `max`; no new control |
| `color` (exists in gridview) | Color | A `color` field type here; a `swatches` option for preset colours |
| `autocomplete` | Autocomplete Input | A text input suggesting values from a URL (the values already used, or an external list), free text still accepted. Shares its suggestion code with `tags` |
| `markdown` | Markdown | A textarea with a toolbar and a preview; a `text` setting `format: plain\|markdown`, rendered safely in the grid and on the record page |
| `html` | WYSIWYG | A rich text editor. The editor library is the decision (framework-agnostic, no CSS framework): its own plan. The output must be sanitized on save |
| `code` | Code | A monospace textarea with a `language` option; syntax highlighting optional |
| one-to-many list | One to Many | The children of a record, listed and edited on its page (a project's tasks). Today an inverse relation is read-only here; this is a grid embedded in the form more than a control |

**Not planned**: Block Editor (a JSON document of blocks), Map (needs a geometry type), Icon,
Checkboxes (Tree), Tree View, Builder (M2A, a link to records of several objects), Translations
(per-language values of a record field), Collection Item Dropdown (choosing an object, an admin
concern, already the `object_name` setting kind).

**Not controls**: Directus's Presentation (Header, Divider, Buttons, Notice) and Groups
(Accordion, Detail Group, Raw Group, Tabs) arrange a form; they hold no value. Here that's the
form view's HTML layout and the forms' definitions, not the control set. If a layout element is
missing there, it goes in the views' plans, not here.

## Phases (one branch per repository each, docs in the same branch)

0. **Golden forms** (metadata-driven). A test object with one field of every type and every
   display. A functional test renders its new and edit forms and compares the HTML with a stored
   copy. Every later phase must leave it unchanged, unless the phase says otherwise and updates
   the copy on purpose.
1. **`ControlBuilder` and the option table** (gridview: sections 1 and 2, `textarea`, `validate`).
   Then metadata-driven moves the simple kinds onto it: text, textarea, email, url, tel, integer,
   decimal, checkbox, date, datetime, choice, status.
2. **Password** (gridview options). Then metadata-driven drops `RecordFormFactory::password()`.
3. **Remote relation** (gridview: section 3). Then metadata-driven moves `relation` and plain
   `many_to_many`, and drops the `PRE_SUBMIT` listener and `rowAttr()`'s cascade part.
4. **Media** (gridview: section 4). Then metadata-driven drops `RecordFileType` and
   `RecordFileListType` and their templates.
5. **Collection** (gridview: section 5). Then `JunctionRowsType` and `JunctionRowType` go.
6. **`WidgetKind` values** (decision 1) and the test enforcing the mapping.
7. **Tags.** Phase 3 of [tags-type-plan.md](tags-type-plan.md) builds on phase 1 here. It can
   start as soon as phase 1 is released, in parallel with phases 2 to 6.

### Tests

- Gridview: unit tests of `ControlBuilder` per control and option, the `buildControl()`
  delegation (the existing CRUD tests stay green), the remote relation's choice loader with
  submitted ids it hasn't shown, `media` with and without a current file.
- metadata-driven: the golden forms of phase 0, the existing record form functional tests, the
  mapping test of phase 6.

## Not in scope

- Field types for the gridview controls metadata-driven doesn't have (money, color, country…):
  each one is its own decision.
- The **filters**. The grid side already goes through gridview (`RecordGridFactory`), and the
  filter types are a separate vocabulary.
- Changing how either side validates (see section 1).
