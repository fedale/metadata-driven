# Workflows

A workflow runs a series of steps when something happens to a record: it checks or changes the
record **before** it is written, or writes other records **after** it. It is defined in YAML
from the admin, `/admin/workflows`, by whoever holds `admin.workflows`; no code.

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
- **The editor**: the name, a description, and the definition of the **draft**. *Save the draft*
  keeps it as written, comments included; *Save and activate* checks it first, and lists every
  problem by its place in the definition (`steps.0.branches.1.steps.0.values.note: …`).
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
| `on` | `created`, `updated`, `created_or_updated`, `deleted` |
| `object` | The object whose records start it |
| `when` | `before`: in the write's transaction, before it; `after`: once the write is committed |
| `fields` | `updated` and `created_or_updated` only: run on an update only when one of these fields changes |

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
| `$trigger.object`, `$trigger.action` | The object, and `create`, `update` or `delete` |
| `$trigger.record.<field>`, `$trigger.record.id` | The record: as about to be written before the write, as written after it, as it was for a delete. Never a password |
| `$trigger.before.<field>` | `updated` and `created_or_updated`: the record as it was before the update |
| `$trigger.changed` | The fields the write changes |
| `$user.email`, `$user.roles` | Whoever triggered the event; null from a command |
| `$workspace.slug`, `$workspace.name` | The workspace |
| `$now`, `$today` | The time (ISO 8601) and the date of the run |
| `<key>.<…>`, `$last.<…>` | A step's output, by its key; the previous step's |

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
| `app.workflow.runs_keep_days` | `30` | How long the runs are kept |

A run is **completed**, **failed** (an after-run that could not finish: nothing of it stays), or
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
| The admin | `WorkflowAdminController`, `WorkflowManager` |

## Not yet

Manual workflows (a button on a record or a selection), search and iterator steps, forms asking
for input (phase 2); the system admin's workflows on platform events (phase 3); the background,
HTTP requests, waits (phase 4); states (phase 5); a visual editor with a variable picker and a
dry run against a record (phase 1b). See [the plan](internal/workflow-plan.md).
