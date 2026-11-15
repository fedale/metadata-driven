# Plan: workflows, phase 1 in detail

Status: **done** (2026-11-15) on branch `workflow_phase1_design`, waiting for the user's review.
The guide is [../workflows.md](../workflows.md). Phase 1 of [workflow-plan.md](workflow-plan.md) ("Record workflows"), which holds the
decisions already taken (2026-11-13) and the concepts; this page says how they fit the code as it
is, and what is built, in which order.

## What phase 1 delivers

- Record triggers: created, updated (optionally on some fields), created or updated, deleted;
  each **before** the write (blocking, in its transaction) or **after** it (right after the
  commit, in the same request).
- Steps: if / else if / else, filter, create, update, delete and upsert record, set values
  (before only), refuse.
- The data chain and its `{{ … }}` paths; permissions per step; versions; runs with their steps;
  usage per workspace and day; the limits; no loops.
- `admin.workflows`; the admin pages: the list, the editor, the versions, the runs.
- `workflow:runs:gc`.

Not in phase 1, as the plan says: manual triggers, search, iterator, forms (phase 2); the system
admin's workflows (phase 3); the background, HTTP, waits (phase 4); states (phase 5).

## What the code has today, and what changes

| Today | Consequence |
|---|---|
| Every record write goes through `RecordManager` (`create()`, `update()`, `delete()`): forms, imports, packages' seeds, a delete's cascade | The events are dispatched there, and nowhere else |
| `RecordManager` validates **outside** the transaction, then writes inside it | A before-trigger must run before validation, so that the values it sets are validated: create and update run "before-trigger, validate, write" in one transaction |
| Record permissions are checked by the controllers only (`AccessCheckerInterface::canRecords()`), never by `RecordManager` | The workflow engine checks them itself, before each step that writes |
| `AccessCheckerInterface` answers for the signed-in user only | Two methods are added to the Access contract (below), to check a role, and a user other than the current one |
| `TransactionRunner` joins nested calls, with no hook after the commit | `DoctrineTransactionRunner` counts its nesting and runs what was deferred when the outermost transaction ends, committed or rolled back |
| The many-to-many rows through a junction object are written by `RecordManager` directly in the store | They raise no event in phase 1: the owning record's update does (its field changed) |

## The Record module's events (its contract)

Two events, in `App\Module\Record\Contract\Event`, dispatched with Symfony's event dispatcher.
The Record module knows nothing of workflows: the Workflow module listens.

**`RecordWriting`**, before the write, inside its transaction:

- `object`, `action` (create, update, delete), `id` (null on create), `values` (what is about to
  be written: the input as given, before validation), `current` (the record as stored, on update
  and delete);
- `setValue(field, value)`: the write takes the changed values, then validates them;
- `refuse(message)`: the write fails: `RecordValidationFailed` with the message as a global error
  on create and update (every form, the import and the seeds show those already), a new named
  constructor of `RecordDeleteRestricted` on delete (shown as a restrict rule is).

A delete's cascade raises one `RecordWriting` per record deleted, each before its own deletion; a
refusal anywhere refuses the whole delete (all or nothing, as today).

**`RecordWritten`**, after the outermost transaction commits:

- `object`, `action`, `id`, `record` (as written; on delete, as it was), `before` (on update, the
  values it had), `changed` (the fields whose value changed), `origin` (null, or the run that
  wrote it and its depth).

Dropped when the transaction rolls back: what was not written raised nothing. A form writing an
account, its profile and its phones raises its events once the whole form is committed.

**`RecordEventScope`** (Record contract): the workflow engine runs its data steps inside
`$scope->asRun($runId, $depth, $emitEvents, fn () => …)`; `RecordManager` reads it to stamp the
origin, and raises no event at all when `$emitEvents` is false (the default of every data step).

### The deferred work: `Shared\Transaction`

A new port, `TransactionRunner::whenDone(callable(bool $committed): void)`: run now when no
transaction is open, else when the outermost one ends. `DoctrineTransactionRunner` keeps a depth
counter around `transactional()`. Two users: `RecordWritten` (on commit only) and the runs of
before-triggers (always: a refused write is rolled back, its run must still be stored).

## The Workflow module

`src/Module/Workflow/`, with the usual layers. Deptrac: it uses the contracts of Record (events,
`RecordManagerInterface`, `RecordFinderInterface`, `RecordEventScope`), Metadata (objects and
fields, to validate a definition), Access (permissions, roles) and Shared (workspace, transactions).
No other module uses it.

### Tables

All in `core`, with a `workspace_id`, its trigger, its row-level security policy and a place in
`WorkspaceScope::TABLES`, as every workspace table. One migration.

| Table | Holds | Written with |
|---|---|---|
| `workflow` | name, description, position (the order among the workflows of one event), created by, dates | ORM |
| `workflow_version` | the workflow, a number, status (draft, active, deactivated, archived), the definition (JSONB), who activated it and when; one active version per workflow (a partial unique index) | ORM |
| `workflow_run` | the workflow and version, status (running, completed, failed, refused), the trigger (object, record id, action, timing), who triggered it (email, or none), depth, the data chain (JSONB), the error, start and end | DBAL, after the transaction ends |
| `workflow_run_step` | the run, position, key, type, status (completed, failed, skipped), input, output, error, duration | DBAL, idem |
| `workflow_usage` | workspace, day, steps run (an upsert per run) | DBAL, idem |

### The definition

JSON in the database; in the editor of phase 1, YAML (see the open questions):

```yaml
trigger:
  on: updated            # created, updated, created_or_updated, deleted
  object: invoice
  when: after            # before (blocking) or after
  fields: [status]       # updated only: run when one of these changed
steps:
  - key: overdue
    type: if
    branches:
      - when: { all: [{ field: $trigger.record.status, op: eq, value: sent },
                      { field: $trigger.record.due_on, op: lt, value: '{{ $now }}' }] }
        steps:
          - key: reminder
            type: create_record
            object: reminder
            values: { invoice: '{{ $trigger.record.id }}', note: 'Invoice {{ $trigger.record.number }} is overdue' }
            act_as: ROLE_ACCOUNTANT        # optional: else, as whoever triggered the event
      - steps:                              # else
          - { key: done, type: filter, when: { field: $trigger.record.amount, op: gt, value: 0 } }
```

- **Steps**: `if` (branches, the first true wins; a branch without `when` is the else), `filter`
  (`when`), `create_record` (`object`, `values`), `update_record` (`object`, `id`, `values`),
  `delete_record` (`object`, `id`), `upsert_record` (`object`, `match` (a unique field),
  `values`), `set_values` (`values`; before only), `refuse` (`message`). Every data step takes
  `act_as` and `emit_events` (default false).
- **Conditions**: `{ field, op, value }` with the grid's operators (`eq`, `neq`, `lt`, `lte`,
  `gt`, `gte`, `contains`, `not_contains`, `starts_with`, `ends_with`, `in`, `between`) plus
  `empty` and `not_empty`; combined with `all` and `any`, nested.
- **Paths**: `{{ $trigger.record.amount }}`, `{{ reminder.id }}`, `$user`, `$workspace`,
  `$last`, `$now`. A value that is one path alone keeps its type (a number, a list, null); a path
  inside a text is written as text. Nothing else: no expression, no function.

### Validation, on activation

A version is checked when it is activated, and refused with every problem, located (`steps.0.
branches.0.steps.0.values.invoice: …`), as a form's definition is:

- the trigger's object and fields exist; `set_values` and `refuse`-as-refusal only in a before
  trigger;
- each data step's object and fields exist, and its values fit their fields' types where they
  are constants;
- each path names the chain's roots or a step **before** it on the way to it (not a step of
  another branch);
- each `act_as` is a role the person activating can give (`canGrant()`);
- the keys are unique; at most 50 steps.

### Execution

1. A listener on `RecordWriting` / `RecordWritten` finds the active versions of the workspace for
   that object, action and timing (cached per workspace, the cache bumped when a version is
   activated or deactivated), in `position` order.
2. **Before**: each runs at once, in the write's transaction; `set_values` changes the values the
   write will validate; `refuse` refuses it; a failing step refuses it too ("The workflow X could
   not run: …"), since a before-workflow guards the write.
3. **After**: each run's data steps run in **one transaction of their own** (see the open
   questions): all of the run or none; a failure fails the run, never the write that triggered it.
4. The run and its steps are stored when the transaction ends (`whenDone()`), with the usage.

**Limits** (parameters `app.workflow.*`): 50 steps per run; depth 3 (a write by a run with
`emit_events` raises events of depth + 1; past 3, refused before, failed after); 100 runs a
minute per workspace (counted on `workflow_run`): beyond, a before-trigger refuses the write (it
would otherwise let a write skip the rule it guards), an after-trigger's run is stored as failed;
runs kept 30 days.

### Permissions

- A data step checks, before it writes, `record.<object>.<action>` (upsert: create and update):
  - **as whoever triggered the event** by default: the signed-in user, in the same request;
  - **as `act_as`**: the role must give it, and the version's activator must still be able to
    give that role, checked at each run.
- **No one behind the event** (a console command, the seeds of a package, an import from the
  command line): a data step without `act_as` fails, "no one triggered this event: choose a role
  to act as". Conditions, `set_values` and `refuse` need no permission.
- Two methods added to `AccessCheckerInterface`:
  - `roleGives(string $role, string $permission): bool`: the role holds it, through the roles it
    contains (the super admin role, everything);
  - `userCanGrant(string $email, string $item): bool`: `canGrant()` for another member of the
    workspace, from their assignments (none when they are no member any more).
- `admin.workflows` (`Permissions::WORKFLOWS`), created by `access:sync`: the pages, the runs.

### The admin

`/admin/workflows` (`admin.workflows`):

- **the list**: name, trigger, active version, last run, failed runs in the last day; the order
  (`position`);
- **the editor**: the draft's definition in YAML, with the errors of the last activation attempt
  under it, as the forms' page; "Activate" checks and activates it; editing an active workflow
  makes a draft, the active version runs meanwhile;
- **the versions**: number, status, who activated it, when; deactivate; "use as draft";
- **the runs**: by workflow, status and date; a run's page shows its chain and each step's input,
  output, error and duration.

### Console

- `workflow:runs:gc`: deletes the runs older than `app.workflow.runs_keep_days`, for every
  workspace (a system command), to schedule as `metadata:files:gc`.

## Order of the work, on one branch

1. `whenDone()` in `Shared\Transaction`, with its tests.
2. The Record events, `RecordEventScope`, the before-trigger moving validation inside the
   transaction; tests: a form's events after its commit, none after a rollback, a cascade's.
3. The two Access methods, with tests on the role hierarchy.
4. The Workflow module: tables, definition and its validator, the engine, the runs; tests, **the
   abuse cases first**: a workflow as a way to more power (an `act_as` the author can't give, an
   author demoted after activation), a loop (`emit_events` both ways), a write in another
   workspace, a refused before-run stored though its write rolled back.
5. The admin pages and `workflow:runs:gc`; functional tests.
6. The guide `docs/workflows.md`, and admin.md, console.md, access.md, architecture.md, the
   glossary (workflow, version, run, step, trigger), status.md.

## As delivered (2026-11-15)

What differs from the design above, and why:

- **The active workflows are read once per request**, object and moment (`DbalActiveWorkflows`),
  not cached across requests: one indexed query per write that has to know; a cache bumped on
  activation can come when it shows in a profile.
- **Constant values are not checked against their fields on activation**: the record's own
  validation checks them at each write, with its messages; activation checks the objects, the
  fields, the paths and the roles. A workflow does not write file fields.
- **The upsert finds its record with `RecordFinderInterface::findBy()`**, added to the Record
  contract (an import's matching query, one value).
- **A package's migrations (`copy_values`) are silent too**, as its seeds: installed, not written
  by anyone.
- **The list's order is set with ↑ and ↓**, not by dragging.
- **`workflow:runs:gc` works in its workspace**, as `metadata:files:gc`: every workspace with
  `workspace:run --all`.
- The run statuses are completed, failed and refused (a before-run that refused its write).
- **A step acting as whoever triggered the event also needs its activator to hold the
  permission**, on activation and at each run (found by a security review, 2026-11-15): else an
  admin with `admin.workflows` could write, through the users who hold a permission, what they
  may not write themselves. The user's decision stands (the step acts as the triggerer); the rule
  "no way to more power" now holds for the author too.
- **`set_values` needs its activator to hold the trigger's write** (create, update), on activation
  and at each run; it is refused on a `deleted` trigger, where it would have failed at run time.
  `refuse` and conditions need no permission: they only stop a write.
- Left as decided (decision 8 of the plan): the runs, with the data of the records they ran on,
  are seen by `admin.workflows`, whatever the viewer may read of those objects.

## Decisions (user, 2026-11-15)

1. **YAML first**: the editor of phase 1 is YAML, as the forms and the grid views; the visual
   editor (the ordered list of steps, the variable picker, "test" against a record) is phase 1b,
   right after.
2. **Imports trigger the workflows** of their object (one run per row, within the limits); a
   package's **seeds don't**.
3. **An after-run's data steps run in one transaction**: all of the run or nothing.
4. **Several workflows on one event run in the order of the list** (`position`).

## The questions, as they were asked

1. **The editor of phase 1**: YAML, as the forms and the grid views are written today, with the
   visual editor (the ordered list of steps, the variable picker, "test" against a record) as a
   phase 1b right after; or the visual editor at once? Proposed: **YAML first**, 1b next.
2. **Imports and seeds**: does a CSV import trigger the workflows of its object (one run per row,
   within the limits)? Do a package's seeds? Proposed: **imports yes, seeds no** (a package
   installs reference data; its own workflows will come with phase 6).
3. **An after-run's data steps**: in one transaction (all of the run or nothing), or each step
   committed on its own (what ran stays when a later step fails)? Proposed: **one transaction**.
4. **Several workflows on one event**: run in the order of the list (`position`, set by dragging
   on the list), or by name? Proposed: **the list's order**.
