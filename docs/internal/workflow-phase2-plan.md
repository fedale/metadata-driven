# Plan: workflows, phase 2

Status: **agreed** (2026-11-15). Phase 2 of [workflow-plan.md](workflow-plan.md): manual
workflows, search and iterator.

## Decisions (user, 2026-11-15)

1. **A permission per manual workflow**: activating one creates `workflow.<id>.run`, given to roles
   on the roles page as any other; its button shows only to whoever holds it. Its steps still
   check their own permissions (as whoever runs it, or as their role, as in phase 1).
2. **The input form is in the trigger**, asked before the run: no run pauses halfway (a run that
   waits comes with the background, phase 4).
3. **At most 1000 steps executed per run** (`app.workflow.max_executed_steps`), the iterations
   counted; the 50 steps of a definition stay.
4. **Three parts, a branch each**: 2a the manual trigger on a record's page and from a command,
   its permission and its form; 2b search records and the iterator; 2c a grid's selection.

## 2a: the manual trigger

```yaml
trigger:
  on: manual
  object: invoice
  label: Send a reminder            # the button
  form:                             # optional: asked first, read as $trigger.input.<name>
    - { name: note, label: Note, type: text, required: true }
    - { name: due_on, label: New due date, type: date }
steps: [...]
```

- **Trigger**: `on: manual`, `object`, `label` (the button's text), `form` (optional). No `when`:
  a manual run is as an after-run, its steps in one transaction, all or nothing. `$trigger.record`
  is the record it runs on (none from a command without `--record`), `$trigger.action` is
  `manual`, `$trigger.input` the form's answers. `set_values` has nothing to change: refused.
- **Form inputs**: `name`, `label`, `type` (`text`, `number`, `date`, `boolean`, `select` with
  `options`), `required`. Checked when sent; the run starts only with valid answers.
- **Permission**: activating a manual version creates `workflow.<workflow id>.run` (its
  description the workflow's name), shown in a "Workflows" group on the roles page; deleting the
  workflow removes it. The run checks it (`AccessCheckerInterface::holds()`), and its steps
  check theirs.
- **The record's page**: a "Run" menu with the manual workflows of the object the user may run.
  The Record module offers a port for actions on a record (`RecordActionsInterface`, its
  contract), the Workflow module fills it: the Record module still knows nothing of workflows.
  A workflow with a form opens its page (`/w/<workflow id>/run/<record id>`), one without runs at
  once (a POST, its token checked); the result is a flash message, the error of a failed run
  shown.
- **The command**: `workflow:run <workflow> [--record=ID] [--input=name=value]...`, as no one: its
  writing steps need an `act_as`, as for any event with no one behind it.
- **Runs**: timing `manual`; the limits of phase 1, and the new one on executed steps.
- **Editors**: the visual editor gets the manual trigger (label, form inputs as rows); the picker
  offers `$trigger.input.<name>`.

### Done (2026-11-15, branch `workflow_manual`)

As planned, with these choices made on the way:

- A workflow without a form is posted from the record's page by a button in its **Run** menu: the
  header sits inside the record's form, so the button names a form of its own rendered after it
  (`form="…"`), its token the stateless `submit` one; a workflow with a form is a link to its page.
- The run permission's label on the roles page is its description, the workflow's name when it
  was first made (the bundle keeps no way to change a description); removing it revokes its
  assignments and edges first, through the bundle (`PermissionSynchronizer::removePermission()`):
  the database cascade alone left the entity manager holding assignments to a removed item.
- Running on a record needs reading it (`record.<object>.read`), on top of `workflow.<id>.run`.
- The test on a record runs a manual draft with its form unanswered (each input null, a boolean
  false).
- `max_executed_steps` is checked before every step of every run, record triggers included; it is
  reached only with the iterator (2b).

## 2b: search records and the iterator

Decisions (user, 2026-11-15): a `filter` inside an iterator skips the item, as a `continue`
(outside one it still ends the run); a search finding more records than its limit takes the
first ones in its order, and says how many there are in all.

```yaml
- key: open
  type: search_records
  object: invoice
  where:                              # optional: all must hold
    - { field: customer, op: eq, value: '{{ $trigger.record.id }}' }
    - { field: paid_on, op: empty }
  sort: due_on                        # optional, a field; else the oldest first
  direction: desc                     # optional: asc (the default) or desc
  limit: 50                           # optional: at most app.workflow.max_records (200)
  act_as: ROLE_ACCOUNTANT             # optional, as for a write
- key: each
  type: iterator
  items: '{{ open.records }}'         # a list: each item in turn as {{ each.item }}, {{ each.index }}
  steps:
    - { key: reminder, type: create_record, object: reminder, values: { invoice: '{{ each.item.id }}' } }
```

- **search_records**: `where` is a list of tests on the object's fields (`id` included), with the
  operators of the conditions; values may hold `{{ … }}`, checked against the field's type before
  the query (a date as `2026-12-31`, a number, `true`/`false`, an id), so that a wrong value fails
  the step, not the transaction. Many-to-many, file and password fields cannot be searched on.
  Output: `records` (each with its `id` and fields, no password), `count` (returned), `total` (in
  all), `first` (the first record, or null). It reads: it needs `record.<object>.read`, as whoever
  triggered the event (and its activator) or as the role of its `act_as`, as a write does.
- **iterator**: `items` is one `{{ path }}` to a list (records, ids, …), at most
  `app.workflow.max_records` items or the step fails; its `steps` run once per item, reading
  `<key>.item` and `<key>.index`; the steps after it read `<key>.count` (the items run), never the
  steps inside it. Iterators nest. A `filter` inside skips the item; a `refuse` or a failure ends
  the run, as anywhere. The executed steps count against `app.workflow.max_executed_steps`.
- **Validation**: the fields of `where` and `sort` exist and may be searched; `<key>.item.<field>`
  is checked when `items` is a search's `records`; the steps inside an iterator count against the
  50 of a definition.
- **The Record contract**: `RecordFinderInterface::search(object, filters, sort, descending,
  limit)`, filters as `RecordFilter` (field, operator, value), a `RecordSearchResult` back; SQL in
  `DbalRecordStore`, the identifiers from the metadata, the values bound and cast to the column's
  type.
- **Editors**: the visual editor gets both (the tests of `where` as rows; the iterator's steps
  indented under it, as a branch's); the picker offers their outputs.

### Done (2026-11-15, branch `workflow_search_iterator`)

As planned. On the way: the search's SQL went into `DbalRecordStore` (the grid's source has
neither `empty` nor its operators on every type); `neq` and `not_contains` keep the empty values,
as the conditions do; `sort` may also be `id`.

## 2c: a grid's selection

Decisions (user, 2026-11-15): the trigger says how a selection runs; "select all" (every page,
the current filters) works, up to a cap; the workflows show in the grid's bulk bar
(gridview-bundle), their form in its modal.

```yaml
trigger:
  on: manual
  object: invoice
  label: Send reminders
  selection: each        # each: a run per record; all: one run with $trigger.records
  form: [...]
```

- **`selection`** (manual only, optional): without it, the workflow runs on a record's page only.
  `each`: on a record's page and on a grid's selection, a run per record, each all or nothing; a
  failed one does not stop the others. `all`: on a grid's selection only, one run, all or
  nothing, `$trigger.records` the list of the records (read with an iterator); `$trigger.record`
  is not there.
- **The grid**: when the object has workflows the user may run on a selection, its grid gets the
  selection column and the bulk bar, a button per workflow. The button opens the modal of the
  bundle on `/w/<workflow id>/run-selection?ids[]=…` (or `all=1` and the grid's filters): the
  number of records, the form if any, *Run*. Running posts there, and comes back to the grid with
  a message (how many runs completed, the first error).
- **The records**: `ids[]`, or with `all=1` every record of the grid's current filters and search,
  resolved by the Record module (a new contract port, `RecordSelectionInterface`, the grid's
  export rows); more than `app.workflow.max_records` refuses before any run. The user must read
  them (`record.<object>.read`) and hold `workflow.<id>.run`.
- **The Record contract**: `RecordActionsInterface::selectionActionsFor(object, grid)` gives the
  bulk buttons; the Record module still knows nothing of workflows.
- **The command**: `--record` repeatable: a run per record (`each`), one run on all of them (`all`).
- **Editors**: the trigger's `selection` in the visual editor; the picker offers
  `$trigger.records`.

### Done (2026-11-16, branch `workflow_selection`)

As planned. On the way: a `selection: all` workflow is not offered on a record's page; "select
all" reads the grid's export rows (the whole filtered set, as an export does) and stops at the
cap; the modal's form posts natively to its url, the selection in its query string, and comes back
to the grid (no turbo stream); the iterator's item fields are checked over `$trigger.records` too.
