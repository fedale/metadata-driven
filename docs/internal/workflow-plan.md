# Plan: workflows

Status: **proposed**, not started. Discussed with the user on 2026-11-13. "Declarative rules and
automations" of the roadmap ([status.md](status.md), "An app in a package", point d): when
something happens, run a series of actions, configured without code.

## Decisions (user, 2026-11-13)

1. **Who defines them**: each tenant's admins, for their tenant; and the operator, for every
   tenant (the events of the platform, such as a tenant created).
2. **Execution**: in the request or in the background, depending on the step.
3. **Permissions**: a step acts with the permissions of whoever triggered the event, by default.
4. **Where they are configured**: pages of the admin. Their inclusion in templates, as YAML, is
   undecided: the definition is designed to be exported anyway, so that it costs little later.
5. **States** (a status field whose transitions are allowed per role) come after the event
   hooks, as their own phase; the user agreed to start with the hooks.

## Ideas taken from Directus and Twenty

Read with the user on 2026-11-13: [Directus Flows](https://directus.com/docs/guides/flows.md) and
[Twenty workflows](https://docs.twenty.com/user-guide/workflows/capabilities/workflow-triggers).

Taken:

- **Blocking and non-blocking record triggers** (Directus): *before* the write, in its
  transaction, a workflow may validate, change or refuse it; *after* the write, it reacts without
  holding it up. This is what decision 2 turns into: the moment decides, not the step.
- **A data chain** (Directus): one JSON document per run, `$trigger`, `$user`, `$tenant`, `$last`
  and each step's output under its key, read with `{{ … }}`.
- **Versions** (Twenty): draft, active, deactivated, archived; editing an active workflow makes a
  draft while the active version keeps running; rolling back is "use as draft".
- **Runs to look at** (Twenty): each run with its status (running, completed, failed, waiting),
  and each step's input, output and error.
- **Record triggers by field** (Twenty): updated, optionally on some fields only; created or
  updated; deleted, with the record as it was.
- **Manual triggers** (both): one record, a selection (once for all, or once each), or none (from
  a command), with a form asking for input first.
- **If / else if / else** (Twenty), the first true condition winning, and a filter as a gate.
- **Search records and an iterator** (Twenty), with a cap on the records.
- **Upsert** (Twenty), matched on a unique field.
- **Wait** for a duration or until a date field (Twenty), the run then *waiting*.
- **Permissions per step** (Directus), within our rule (below).
- **"Emit events"** on the data steps, off by default (Directus): a workflow writing a record
  doesn't start another one, unless asked.
- **Imported or duplicated workflows start inactive** (Directus).
- **Usage counted per tenant** (Twenty's credits): the steps run, from the first phase, so that
  quotas per plan need no history rebuilt.
- **A variable picker** in the editor (Twenty): the `{{ … }}` chosen from the previous steps.

Left out: **code** (JavaScript) steps, in both: a tenant's admins would run code on the server,
against "declarative, never code"; AI steps; JWT, sleep in milliseconds; parallel branches
(Twenty's default), harder to reason about than if/else; a free canvas editor, costly to build
(an ordered list of steps with their branches first); "pick record" round robin, a CRM feature
for later.

## Concepts

- A **workflow** belongs to a tenant (its admins'), or to the installation (the operator's: no
  tenant, as the shared template indexes). It has a name, a description, a status, and versions.
- A **version** holds the definition: one trigger, then steps. Only the active version runs.
- A **step** has a key (unique in the version, the name of its output on the data chain), a type,
  options (with `{{ … }}`), and, for the branching steps, the steps of each branch.
- A **run** is one execution of one version: its status, its data chain, its steps' results.

### Triggers

| Trigger | Starts | Phase |
|---|---|---|
| Record created | after the record is written (or before: blocking) | 1 |
| Record updated | idem; optionally when some fields change only | 1 |
| Record created or updated | idem | 1 |
| Record deleted | idem, with the record as it was | 1 |
| Manual | from a record's page, a selection on a grid (once for all, or once each), or a command with no record; a form first, if the workflow asks | 2 |
| Platform event | a tenant created, a member added (the operator's workflows) | 3 |
| Schedule | a cron expression, in UTC | 6 |
| Incoming webhook | a request to a URL of its own (with the REST API of the roadmap) | 6 |

**When record events fire.** At the end of the write that made them, **once per record**:
a composite form writing an account, its profile and its phones gives three events, after the
whole composite form is written, never in the middle. Through `RecordManager` (`create()`,
`update()`, `delete()`): every write goes there (forms, composite forms, imports, templates'
seeds), and so does a delete's cascade, one event per record deleted. No event exists today: the
Record module starts dispatching them (its contract), the Workflow module listens.

**Blocking (before) triggers** run in the write's transaction, with the values about to be
written: a step may change them (the write takes the changed values) or refuse the write (an
error step, its message shown to the user). They are kept small: no waiting, no external call.

### Steps

| Step | Does | Phase |
|---|---|---|
| If / else if / else | branches on conditions, the first true one wins | 1 |
| Filter | goes on only if a condition holds | 1 |
| Create record | in any object, values from the chain | 1 |
| Update record | the trigger's record, or one from the chain | 1 |
| Delete record | idem (the delete rules apply) | 1 |
| Upsert record | matched on a unique field | 1 |
| Set values | before-triggers only: changes the values being written | 1 |
| Refuse | before-triggers: refuses the write with a message; otherwise ends the run as failed | 1 |
| Search records | with the grid's filters, up to 200 records | 2 |
| Iterator | the steps inside it for each item of a list | 2 |
| Form | asks the person running a manual workflow | 2 |
| Install template, assign role | the operator's platform workflows | 3 |
| HTTP request | to an external URL, in the background, with retries | 4 |
| Wait | a duration, or until a date | 4 |
| Send email, notification | when the application sends email (put off by the user) | 6 |

**Conditions** reuse the grid's filter operators (equals, contains, greater than, is empty…), as
Directus reuses its filter rules: one syntax for the user, in the grids and in the workflows.

### The data chain

```yaml
$trigger: { object: invoice, record: { id: …, amount: 1200, status: sent }, before: { status: draft } }
$user:    { email: anna@example.com, roles: [ROLE_ACCOUNTANT] }    # whoever triggered it, if anyone
$tenant:  { slug: acme, name: Acme Corp }
$last:    …                                                         # the previous step's output
create_reminder: { id: … }                                          # each step's output, by its key
```

`{{ $trigger.record.amount }}`, `{{ create_reminder.id }}`: a path into the chain, nothing else
(no expression language, no code). A relation holds the related record's id; a search step reads
more of it. Environment variables are not on the chain.

### Permissions

The rule that the members and roles pages hold to, applied here: **no way to more power**.

- A step acts, by default, **as whoever triggered the event**: a record step checks their
  `record.<object>.<action>` permission, as if they had done it themselves.
- A step may act **as a role the workflow's author chose**, but only a role the author could give
  (`AccessCheckerInterface::canGrant()`), checked when the version is activated, and again at each
  run against the author as they are then (a demoted author's workflows stop at that step).
- An event with **no one behind it** (a schedule, a platform event, a webhook) needs such a role
  on every step that touches data: no implicit full access.
- The operator's workflows act in the tenant concerned, with the role they name there.
- Defining workflows needs a new permission, `admin.workflows`; seeing the runs (which hold
  records' data), the same.

### Execution

- **Before** triggers: in the write's transaction, synchronously; a failure refuses the write.
- **After** triggers, phase 1: right after the write's transaction commits, in the same request
  (a failure doesn't undo the write; the run is failed, and visible). Phase 4 moves them to the
  background (Symfony Messenger, to install), with retries and the steps that wait.
- **No loops**: the data steps emit no event unless "emit events" is on; a run started by another
  run carries its depth, refused past 3.
- **Limits** (configurable): 50 steps per run, 200 records per search or iterator, a tenant's runs
  per minute (Twenty's 100) beyond which they queue (phase 4) or are refused (before).

### Versions, runs, usage

- **Versions**: draft, active, deactivated, archived, as above. A version is validated when
  activated (each step's options, each `{{ }}` path against the earlier steps, the permissions).
- **Runs**: status (running, completed, failed, waiting), the data chain, each step's status,
  input, output, error, duration. Kept 30 days by default (configurable), then deleted by a
  command run on a schedule (`workflow:runs:gc`, as `metadata:files:gc`).
- **Usage**: the steps run, per tenant and per day, counted from phase 1. Quotas per plan are for
  later; the history will be there.

### Where it lives

- A new module, **Workflow** (`src/Module/Workflow/`), with the usual layers. It sees only the
  contracts of the others (deptrac): the Record module's events and record manager, the
  metadata, Access (`canGrant()`, the roles), Template (phase 3), Shared's tenant ports.
- Tables in `core`, with a `tenant_id` and row-level security, as the other tenant tables
  (`TenantScope`): `workflow`, `workflow_version` (the definition as JSON), `workflow_run`,
  `workflow_run_step`, `workflow_usage`. The operator's workflows have no tenant, as the shared
  template indexes: read by the landlord, never by a tenant.
- The definition is a JSON document (trigger, steps), so that YAML in templates (decision 4) is a
  serialization away.

### The admin

`/admin/workflows`, for whoever holds `admin.workflows`:

- the list: name, trigger, status, last run, runs that failed;
- the editor: the trigger, then the steps as an ordered list, branches indented under their
  if/else; a **variable picker** for the `{{ }}` of each option; "test" on the draft against a
  record chosen by the admin (nothing written: a dry run, as the template upgrades' `--dry-run`);
- versions: publish the draft, deactivate, "use as draft" on an archived version;
- the runs: filtered by status and date, each with its steps' inputs, outputs and errors.

The operator's workflows: a page of the console (phase 3).

## Phases

One branch each, reviewed before the next.

1. **Record workflows.** The Record module's events; the Workflow module; record triggers
   (before and after); if/else, filter, create, update, delete, upsert, set values, refuse; the
   data chain; permissions per step; versions; runs; usage; `admin.workflows`; the admin pages
   (list, editor, versions, runs). Tests, the abuse cases first (a workflow as a way to more power,
   a loop, a cross-tenant read).
2. **Manual workflows**: buttons on a record's page and on a grid's selection, a command; forms;
   search records; iterator.
3. **The operator's workflows**: platform events (a tenant created, a member added), install a
   template, assign a role; the console's page. The user's SaaS case: an app installed with every
   new workspace.
4. **The background**: Symfony Messenger (a dependency to add, with its worker); after-triggers
   there; HTTP requests; waits; retries; queued runs past the limits.
5. **States**: a status field type, its states and transitions, each transition allowed to some
   roles and guarded by conditions, shown as buttons; a transition is a trigger.
6. **Later**: schedules, incoming webhooks (with the REST API), sub-workflows, email and
   notifications (with email sending), quotas per plan, export and import (as JSON, inactive on
   import), templates carrying workflows.

## Open decisions (for the user)

1. **After-triggers before Messenger** (phase 1): right after the commit, in the same request
   (recommended: simple, visible at once; the background comes in phase 4), or wait for
   Messenger and have before-triggers only in phase 1.
2. **The limits' defaults**: 50 steps per run, 200 records per search, 100 runs a minute per
   tenant, runs kept 30 days (recommended as written).
3. **Who sees the runs**: `admin.workflows` only (recommended: runs hold records' data), or
   anyone who may read the trigger's object.
4. **A workflow's author leaving**: a role-acting step checked against its author at each run
   stops when the author can no longer give that role (recommended: safe, and visible as a failed
   run), or the role is fixed at activation by whoever activated it.

## Not in scope

- Code steps, AI steps (see "Left out").
- A free canvas editor (an ordered list with indented branches first).
- Email and notifications until the application sends email.
- Billing (usage is counted; plans and quotas are for later).
