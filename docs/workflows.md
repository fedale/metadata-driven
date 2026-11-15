# Workflows

A workflow runs a series of steps when something happens to a record: it checks or changes the
record **before** it is written, or writes other records **after** it. A **manual** workflow runs
when someone asks, from a record's page or a command. It is defined in YAML from the admin,
`/admin/workflows`, by whoever holds `admin.workflows`; no code.

```yaml
# When an invoice's status changes: a reminder for the overdue ones.
trigger: { on: updated, object: invoice, when: after, fields: [status] }
steps:
  - key: overdue
    type: if
    branches:
      - when: { all: [{ field: $trigger.record.status, op: eq, value: sent },
                      { field: $trigger.record.due_on, op: lt, value: '{{ $today }}' }] }
        steps:
          - key: reminder
            type: create_record
            object: reminder
            values: { invoice: '{{ $trigger.record.id }}', note: 'Invoice {{ $trigger.record.number }} is overdue' }
```

## The page

`/admin/workflows`, linked from the admin's index:

- **The list**: each workflow with its trigger, its active version, its last run and the runs that
  failed in the last day. The workflows of one event run **in the list's order**: ↑ and ↓ move
  one.
- **The editor**, in two tabs, both working on the **draft**:
  - **Visual** (the default): the trigger, then the steps as a list, each with its options; the
    branches of an `if` indented under it, with their steps. Steps are added by type, moved and
    removed; values are rows of a field and a text; a condition is *all of* or *any of* a list of
    tests (a condition nested deeper shows as YAML, to edit in the YAML tab). Saving writes the
    draft's YAML again: comments written in the YAML tab are lost.
  - **YAML**: the definition as text, comments kept.

  *Save the draft* keeps it; *Save and activate* checks it first, and lists every problem by its
  place in the definition (`steps.0.branches.1.steps.0.values.note: …`), under the step it
  concerns in the visual tab.
- **Variables**: next to the editor, the paths the draft can read (the trigger's record and its
  fields, the run's context, each step's output); a click puts `{{ path }}` where the cursor is.
- **Test on this record**: choose a record of the trigger's object; the draft is saved, checked as
  an activation would, then run on it **for real and rolled back**: each step's input, output and
  error are shown on the page, and nothing is kept (no record written, no run stored). It acts with
  your permissions, and offers only the records you may read (`record.<object>.read`); a before-trigger runs as the update of the record with its own values (a create
  for a trigger on `created`), an after-trigger as the record written. Its writes start no other
  workflow, whatever `emit_events` says, and the limits don't apply.
- **Versions**: activating a draft makes it the active version, the previous one archived. Editing
  an active workflow writes a new draft while the active version keeps running. *Deactivate* stops
  it; *Use as draft* on an archived or deactivated version copies its definition into the draft,
  to go back.
- **Runs**: each run with its status, the record and who triggered it; a run's page shows each
  step's input, output, error and duration, and the data of the run.

## The definition

### The trigger

| Key | Value |
|---|---|
| `on` | `created`, `updated`, `created_or_updated`, `deleted`; `manual` (see [Manual workflows](#manual-workflows)) |
| `object` | The object whose records start it |
| `when` | `before`: in the write's transaction, before it; `after`: once the write is committed. Not for `manual` |
| `fields` | `updated` and `created_or_updated` only: run on an update only when one of these fields changes |
| `label` | `manual` only, required: the text of its button |
| `form` | `manual` only: the inputs asked before it runs |

**Before** a write, a workflow guards it: it may change the values (`set_values`, validated with
the others) or refuse the write (`refuse`, its message shown to the user), and a step that fails
refuses it too. **After** a write, the workflow reacts: its steps run in one transaction, all or
nothing, and a failure fails the run, never the write.

Every write raises its events: a record's form, the forms of several records (one event per
record, once the whole form is saved), an import, a delete's cascade (one per record deleted). A
package's seeds and migrations raise none: they are installed, not written by anyone.

### The steps

Each step has a `key` (lowercase letters, digits, underscores; unique in the workflow: it names
the step's output), a `type`, and its options.

| Type | Options | Does |
|---|---|---|
| `if` | `branches`: each `when` and `steps`; the last may go without `when` (the else) | Runs the steps of the first branch whose condition holds |
| `filter` | `when` | Goes on only if the condition holds; else the run ends, completed |
| `create_record` | `object`, `values` | Creates a record |
| `update_record` | `object`, `id`, `values` | Changes the fields given |
| `delete_record` | `object`, `id` | Deletes the record, with the delete rules of its relations |
| `upsert_record` | `object`, `match` (a unique field), `values` (with the one matched) | Updates the record whose field holds the value, or creates one |
| `set_values` | `values` | Before a write only: changes the values written |
| `refuse` | `message` | Before a write, refuses it; after one, ends the run as failed |

The four steps that write records also take `act_as` (see [Permissions](#permissions)) and
`emit_events`: `false` by default, so that the records a workflow writes start no workflow; `true`
lets them, in a chain at most three runs deep (`app.workflow.max_depth`). A workflow does not
write file fields.

At most 50 steps, those of the branches included.

### Conditions

```yaml
when: { field: $trigger.record.amount, op: gt, value: 1000 }
when: { any: [{ field: $trigger.record.status, op: eq, value: sent }, { all: [...] }] }
```

`field` is a path (below); `op` one of `eq`, `neq`, `lt`, `lte`, `gt`, `gte`, `contains`,
`not_contains`, `starts_with`, `ends_with`, `in` (a list), `between` (a list of two), `empty`,
`not_empty` (no value), the operators of the grid's filters. Numbers compare as numbers, the rest
as text, case aside for `contains` and the like: dates (`2026-11-15`) compare as they sort. A list
(a many-to-many field) equals or contains a value when one of its items does.

### The data of a run

Each run holds its data, read by paths:

| Path | Holds |
|---|---|
| `$trigger.object`, `$trigger.action` | The object, and `create`, `update`, `delete` or `manual` |
| `$trigger.record.<field>`, `$trigger.record.id` | The record: as about to be written before the write, as written after it, as it was for a delete, as it is for a manual run (none from a command without `--record`). Never a password |
| `$trigger.input.<name>` | `manual` only: the answer to an input of its form |
| `$trigger.before.<field>` | `updated` and `created_or_updated`: the record as it was before the update |
| `$trigger.changed` | The fields the write changes; not for `manual` |
| `$user.email`, `$user.roles` | Whoever triggered the event or ran the workflow; null from a command |
| `$workspace.slug`, `$workspace.name` | The workspace |
| `$now`, `$today` | The time (ISO 8601) and the date of the run |
| `<key>.<…>`, `$last.<…>` | A step's output, by its key; the previous step's |
### Manual workflows

```yaml
trigger:
  on: manual
  object: invoice
  label: Send a reminder            # its button, on an invoice's page
  form:                             # optional: asked first, read as $trigger.input.<name>
    - { name: note, label: Note, type: text, required: true }
    - { name: tone, label: Tone, type: select, options: [kind, firm] }
steps:
  - { key: reminder, type: create_record, object: reminder,
      values: { invoice: '{{ $trigger.record.id }}', note: '{{ $trigger.input.note }}' } }
```

- **Where**: on a record's page, the **Run** menu lists the manual workflows of its object the
  user may run. One without a form runs at once; one with a form opens it first. The result is a
  message on the record's page, the error of a failed run in it. From the console:
  [`workflow:run`](console.md#workflowrun).
- **Who**: activating a manual version creates the permission `workflow.<workflow id>.run`, in the
  *Workflows* group of the roles page (*Run "name"*, the workflow's name when it was first
  activated). Only whoever holds it sees the button and may run it, on a record they may read;
  the super admin holds it already. Its steps still check their own permissions (see
  [Permissions](#permissions)). Activating a version on another trigger, or deleting the workflow,
  removes the permission, from every role and user.
- **The form**: each input has a `name`, a `label`, a `type` (`text`, `number`, `date` as
  `2026-12-31`, `boolean`, `select` with its `options`) and may be `required`. The answers are
  checked before the run starts; none is `null`, an unchecked boolean `false`.
- **The run**: as an after-run, its steps in one transaction, all or nothing; a `refuse` keeps
  nothing either, its message shown. `set_values` has nothing to change: refused.

A step that writes outputs the record as written (`{{ reminder.id }}`); an `if`, the branch it
took; a filter, whether it passed. A step reads only the steps run before it on every way there:
not the steps of another branch.

In the options, `{{ path }}` is replaced by the value: alone, it keeps the value's type (a
number, a list, null); inside a text, it is written as text (a list as JSON). Nothing else: no
expression, no function.

## Permissions

No way to more power, as the members and roles pages hold to:

- A step that writes acts **as whoever triggered the event**: the user needs the step's
  permission (`record.<object>.create`, `update`, `delete`; both create and update for an
  upsert), as if they wrote it themselves. So does the person who activated the workflow: on
  activation, and still at each run. Else they would write, through the users who hold it, what
  they may not write themselves.
- A step may act **as a role** instead, `act_as: ROLE_ACCOUNTANT`: a role the person activating
  the workflow may give (checked on activation). At each run the role must give the permission,
  and the person who activated the version must still be able to give the role: demoted, or no
  member any more, their workflow's step stops, and the run fails, visibly.
- **No one behind the event** (a command, an import from the command line): a step that writes
  needs an `act_as`.
- `set_values` changes what someone else writes: the person who activated the workflow must hold
  that write too (create or update the trigger's object), on activation and at each run.
- Conditions and `refuse` need no permission: they can only stop a write, never give more.

Defining workflows and seeing their runs needs `admin.workflows`. A run holds the data of the
records it ran on, whatever object: give `admin.workflows` to whoever may see them.

## Runs and limits

| Parameter (`config/services.yaml`) | Default | Meaning |
|---|---|---|
| `app.workflow.max_depth` | `3` | The longest chain of runs started by one another's writes (`emit_events`) |
| `app.workflow.runs_per_minute` | `100` | The runs a workspace may start a minute; beyond, a write a before-workflow guards is refused, the other runs fail |
| `app.workflow.max_executed_steps` | `1000` | The steps one run may execute; beyond, the run fails (a definition holds 50 at most) |
| `app.workflow.runs_keep_days` | `30` | How long the runs are kept |

A run is **completed**, **failed** (an after-run or a manual run that could not finish: nothing
of it stays), or
**refused** (a before-run that refused its write). A refused run is kept, though its write was
rolled back. The steps run are counted per workspace and day.

`workflow:runs:gc` deletes the old runs of its workspace; with several workspaces, schedule
`workspace:run --all -- workflow:runs:gc` (see [Console commands](console.md#workflowrunsgc)).

## Under the hood

| Part | Where |
|---|---|
| The events | `RecordManager` raises `RecordWriting` before a write, in its transaction, and `RecordWritten` once the outermost transaction committed (`TransactionRunner::whenDone()`); `RecordEventScopeInterface` silences them (a package's seeds) or stamps them with the run they come from (Record contract) |
| The definition | `DefinitionParser` (structure, every problem by its path), `DefinitionValidator` (objects, fields, paths, roles, on activation), stored compiled as JSON (`core.workflow_version.definition`) next to the YAML as written (`source`) |
| The engine | `WorkflowEngine` listens to both events, reads the active versions (`ActiveWorkflows`, once per request), runs the steps over a `DataChain`, checks each write's permission (`AccessCheckerInterface::roleGives()`, `userCanGrant()`), and stores each run once its transaction ended (`RunStore`, DBAL) |
| The tables | `core.workflow`, `core.workflow_version` (ORM); `core.workflow_run`, `core.workflow_run_step`, `core.workflow_usage` (DBAL); each with its `workspace_id` and row-level security |
| Manual runs | `ManualRuns` (who may run what, the answers checked), `WorkflowEngine::runManual()`; on a record's page through the Record contract's `RecordActionsInterface` (`WorkflowRecordActions`), the form at `/w/<workflow id>/run/<record id>` (`ManualRunController`); `workflow:run` |
| The admin | `WorkflowAdminController`, `WorkflowManager` (which creates and removes the run permission, `AccessSetupInterface::ensurePermission()`) |

## Not yet

Manual workflows on a grid's selection, search and iterator steps (phase 2); the system admin's workflows on platform events (phase 3); the background,
HTTP requests, waits (phase 4); states (phase 5). See [the plan](internal/workflow-plan.md).
