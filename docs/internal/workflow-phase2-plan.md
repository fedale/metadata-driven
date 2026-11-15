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

## 2b and 2c

To be detailed when 2a is merged.
