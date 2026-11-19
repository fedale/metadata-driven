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
    draft's YAML again: comments written in the YAML tab are lost, nothing else.
    A value's text is read as YAML reads it: `null`, `true`, `false`, a number written as one
    (`100`, `1.5`; `00100` stays a text, as a postcode must), a list (`[a, b]`) or a map
    (`{ a: 1 }`); in quotes (`'100'`, `'true'`) it is a text, and a text that would read as
    something else shows in quotes. A date written without quotes (`2026-12-31`) is the text it
    shows, saved in quotes. A select's options are separated by commas, or written as a
    list when one holds a comma (`['Rossi, Mario', 'Bianchi, Luca']`). A step whose type the
    editor does not know (mistyped) is saved as it was written.
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
  workflow, whatever `emit_events` says, and the limits don't apply. An `http_request` is **not
  sent** (a request cannot be rolled back): its step shows the request as it would go, its
  secrets masked, and outputs `sent: false` with no `status` and no `body`, so the steps after it
  read no answer.
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
| `on` | `created`, `updated`, `created_or_updated`, `deleted`; `transitioned` (see [Transitions](#transitions)); `manual` (see [Manual workflows](#manual-workflows)); `schedule` (see [Schedules](#schedules)); `webhook` (see [Webhooks](#webhooks)); `called` (see [Sub-workflows](#sub-workflows)) |
| `object` | The object whose records start it |
| `when` | `before`: in the write's transaction, before it; `after`: once the write is committed. Not for `manual` |
| `fields` | `updated` and `created_or_updated` only: run on an update only when one of these fields changes |
| `label` | `manual` only, required: the text of its button |
| `selection` | `manual` only: `each` (also on a grid's selection, a run per record) or `all` (on a grid's selection only, one run on them all) |
| `form` | `manual` only: the inputs asked before it runs |
| `input` | `called` only: the inputs its callers give, as a form's (the label optional) |

**Before** a write, a workflow guards it: it may change the values (`set_values`, validated with
the others) or refuse the write (`refuse`, its message shown to the user), and a step that fails
refuses it too. **After** a write, the workflow reacts, **in the background**: the write answers
at once, and its runs wait in a queue for the worker (`messenger:consume async`, see
[Getting started](getting-started.md#run-the-app)). Each runs in its workspace, as whoever
triggered the event, its steps in one transaction, all or nothing; a failure fails the run, never
the write. A version deactivated, or replaced, before its run starts runs no more.

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
| `search_records` | `object`, `where` (tests, all of which hold), `sort`, `direction` (`asc`, `desc`), `limit`; `act_as` | Reads the records of an object: the first ones in its order (see [Search and iterator](#search-and-iterator)) |
| `iterator` | `items` (one `{{ path }}` to a list), `steps` | Runs its steps once per item |
| `wait` | `for` (`30 minutes`, `2 days`) or `until` (a date or a date-time, with `{{ … }}`) | Stops the run; the steps after it run then (see [Waits](#waits)) |
| `transition` | `object`, `id`, `field`, `name`; `act_as`, `emit_events` | Takes a transition of a record's [Status field](field-types.md#status) |
| `http_request` | `url`, `method`, `headers`, `body`, `timeout`, `retries`, `fail_on_error` | Calls an address outside (see [HTTP requests](#http-requests)) |
| `run_workflow` | `workflow` (the name of a workflow on `called`), `input` | Runs it inside this run; its output is what it returns (see [Sub-workflows](#sub-workflows)) |
| `return` | `values` (optional) | A called workflow's end: gives the values back to its caller |

The four steps that write records also take `act_as` (see [Permissions](#permissions)) and
`emit_events`: `false` by default, so that the records a workflow writes start no workflow; `true`
lets them, in a chain at most three runs deep (`app.workflow.max_depth`). A workflow does not
write file fields.

At most 50 steps, those of the branches included.

A date written without quotes (`due_on: 2026-12-31`) is the text it shows. A date-time without
quotes (`2026-12-31 10:30:00`) is read in UTC and kept as `2026-12-31T10:30:00+00:00`: quote it
to keep it as written.

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
| `$trigger.records` | `selection: all` only: the records selected, each with its `id` and fields (in place of `$trigger.record`) |
| `$trigger.before.<field>` | `updated` and `created_or_updated`: the record as it was before the update |
| `$trigger.changed` | The fields the write changes; not for `manual` |
| `$user.email`, `$user.roles` | Whoever triggered the event or ran the workflow; null from a command |
| `$workspace.slug`, `$workspace.name` | The workspace |
| `$now`, `$today` | The time (ISO 8601) and the date of the run |
| `<key>.<…>`, `$last.<…>` | A step's output, by its key; the previous step's |
### Transitions

A [Status field](field-types.md#status) changes by its transitions only. Taking one is an update
of the record that starts the workflows on `transitioned`:

```yaml
# Sending an invoice needs its number: the transition's condition.
trigger: { on: transitioned, object: invoice, field: state, transition: send, when: before }
steps:
  - key: unnumbered
    type: if
    branches:
      - when: { field: $trigger.record.number, op: empty }
        steps: [{ key: no, type: refuse, message: 'Number it before sending it.' }]
```

- `field` names the Status field; `transition` one of its transitions, or none for any of them.
  **Before** the transition, a workflow guards it (a `refuse` refuses it, its message shown);
  **after** it, it reacts in the background. `$trigger` holds what an update's does, and
  `transition` (`name`, `from`, `to`). An update that takes no transition starts none of them.
- The **`transition` step** takes one (`field`, `name`) on a record (`object`, `id`): as a write,
  it needs the update permission and one of the transition's roles, held by whoever triggered the
  event **and** by whoever activated the workflow (on activation, and still at each run: else they
  would take it through the users who may), or given by the role it acts as (`act_as`, which must
  be or contain one of them). It outputs the record as written; a
  transition not taken from the record's state fails it.

### Schedules

```yaml
# Every Monday at 8 (UTC): a reminder for each invoice still unpaid.
trigger: { on: schedule, cron: '0 8 * * 1' }
steps:
  - { key: unpaid, type: search_records, object: invoice, where: [{ field: state, op: eq, value: sent }], act_as: ROLE_ACCOUNTANT }
  - key: each
    type: iterator
    items: '{{ unpaid.records }}'
    steps:
      - { key: reminder, type: create_record, object: reminder, values: { invoice: '{{ each.item.id }}' }, act_as: ROLE_ACCOUNTANT }
```

- `cron`: minute, hour, day of the month, month, day of the week, **in UTC** (`0 8 * * 1`:
  Mondays at 8:00 UTC; `*/15 * * * *`: every quarter of an hour). `$trigger.scheduled_at` is the
  minute it was due.
- **No one is behind it**: every step that reads or writes records acts as a role
  (`act_as`), which its activator must be able to give; `$user` is null. Its steps run in one
  transaction, all or nothing.
- The worker checks every minute which workflows are due (Symfony Scheduler, the
  `scheduler_workflows` transport, consumed by **one** worker: see
  [Getting started](getting-started.md#run-the-app)). A minute missed while it was down runs no
  workflow afterwards, but the last one.

### Webhooks

```yaml
# A lead from the site's form: the site posts it to the workflow's address.
trigger: { on: webhook }
steps:
  - { key: lead, type: create_record, object: lead, values: { email: '{{ $trigger.body.email }}', source: '{{ $trigger.query.from }}' }, act_as: ROLE_SALES }
```

- **Its address** is made on the workflow's page (*Make its address*), on the workspace's host:
  `https://acme.example.com/hooks/<workflow id>/<token>`. It is shown **once**, in the message
  that follows: give it to the system that will call it. Its token is kept hashed; lost, make a
  new address, and the old one answers no more.
- A **POST** to it: `202`, and the run goes to the worker. `$trigger.body` is the body, JSON
  decoded (else its text; at most 64 KB), `$trigger.query` the address's parameters. A workflow
  not active, not on a webhook, or a wrong token: `404`, the same for all. Past the workspace's
  runs a minute (`app.workflow.runs_per_minute`), the requests counted as they come: `429`, with
  `Retry-After`, nothing queued.
- **No one is behind it**: every step that reads or writes records acts as a role (`act_as`).
  What it receives is anyone's who has the address: check it (a `filter`, a `refuse`) before
  writing it down.

### Sub-workflows

```yaml
# "Manager of a city": called by the others, it finds who looks after a city.
trigger:
  on: called
  input: [{ name: city, type: text, required: true }]
steps:
  - { key: found, type: search_records, object: manager, where: [{ field: city, op: eq, value: '{{ $trigger.input.city }}' }], limit: 1 }
  - key: check
    type: if
    branches:
      - when: { field: found.count, op: eq, value: 0 }
        steps: [{ key: nobody, type: return, values: { email: sales@acme.example } }]
  - { key: back, type: return, values: { email: '{{ found.first.email }}' } }
```

```yaml
# Any workflow: calls it, and reads what it returns.
steps:
  - { key: manager, type: run_workflow, workflow: Manager of a city, input: { city: '{{ $trigger.record.city }}' } }
  - { key: save, type: update_record, object: customer, id: '{{ $trigger.record.id }}', values: { manager_email: '{{ manager.email }}' } }
```

- **A workflow on `called`** runs only when another one calls it, by a `run_workflow` step naming
  it (its name, as written: rename it, and its callers fail until they are changed). Its
  `$trigger.input` holds what the caller gives, checked against its `input` as a form's answers
  (a required one missing, a number that is none: the step fails). `$user` and `$workspace` are
  its caller's.
- **In line**: it runs at once, in its caller's transaction: all of both, or nothing. A `return`
  ends it (in a branch, in an iterator too), and its values are the `run_workflow` step's output;
  without a return, the output is empty.
- **Its failure** fails the step, and its caller with it. Called **before a write**, its `refuse`
  refuses the write, its message shown; no `http_request` there, as in any workflow before a
  write (the step fails).
- **Its steps** act as whoever triggered its caller, or as their `act_as` roles, which its own
  activator must still be able to give. **Who may call it**: it has a run permission, as a manual
  workflow (*Run "Manager of a city"* on the roles page); the person activating a caller must hold it, on
  activation and still at each run. No wait in it: its caller waits for no one.
- **Its run** is logged on its own (*called*), one level deeper than its caller: calls in chain
  stop at `app.workflow.max_depth`, a workflow that calls itself too. Each counts in the runs a
  minute.

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
- **On a grid's selection** (`selection`): the grid of the object gets its selection column and,
  once rows are selected, a bar with a button per workflow the user may run on them. The button
  opens the form (or the count of records and *Run*) in a modal; the result is a message on the
  grid. *Select all* takes every record of the grid's current filters and search. At most
  `app.workflow.max_records` records at a time; the user must read them (else the modal is
  refused before the selection is read) and hold the workflow's permission. With `each`, a run per record, each all or nothing, a failed one stopping no other
  (the message tells how many completed, how many go on after a wait, and the first error); the
  workflow shows on a record's page too. Each run counts for `app.workflow.runs_per_minute`: a
  selection of more records than the workspace may still start runs this minute is refused whole,
  before any runs (with the defaults, `each` takes 100 records at a time, `all` 200). With `all`, one run, all or nothing, on `$trigger.records` (read with an
  [iterator](#search-and-iterator)), and no `$trigger.record`.

### Search and iterator

```yaml
- key: open
  type: search_records
  object: invoice
  where:
    - { field: customer, op: eq, value: '{{ $trigger.record.id }}' }
    - { field: paid_on, op: empty }
  sort: due_on
  limit: 50
- key: each
  type: iterator
  items: '{{ open.records }}'
  steps:
    - { key: overdue, type: filter, when: { field: each.item.due_on, op: lt, value: '{{ $today }}' } }
    - { key: reminder, type: create_record, object: reminder, values: { invoice: '{{ each.item.id }}' } }
```

- **search_records** tests the fields of its object (`id` too), with the operators of the
  conditions; every test must hold. Each field compares as its type: a number as a number, a date
  as `2026-12-31`, `true`/`false`, an id; text as written, `contains` and the like ignoring case. A
  value that does not fit its field fails the step before the search runs. Many-to-many, file and
  password fields cannot be searched on. Without `sort`, the oldest records first. It returns the
  first `limit` records (at most `app.workflow.max_records`, the default), and outputs `records`
  (each with its `id` and fields, never a password), `count` (returned), `total` (found in all:
  more than `count` when the limit cut them) and `first` (the first record, or null). It reads as
  a write writes: the permission `record.<object>.read`, as whoever triggered the event, or as the
  role of its `act_as`.
- **iterator** runs its `steps` once per item of `items` (a list: a search's `records`, a
  many-to-many field's ids…, at most `app.workflow.max_records` items), each reading
  `<key>.item` and `<key>.index` (from 0). A `filter` that does not hold **skips the item**; a
  `refuse` or a failure ends the run. The steps after it read `<key>.count`, never the steps inside
  it. Iterators nest; every step they run counts against `app.workflow.max_executed_steps`.

### Waits

```yaml
trigger: { on: created, object: invoice, when: after }
steps:
  - { key: later, type: wait, for: 7 days }
  - { key: unpaid, type: filter, when: { field: $trigger.record.paid_on, op: empty } }
  - { key: reminder, type: create_record, object: reminder, values: { invoice: '{{ $trigger.record.id }}' } }
```

- A `wait` stops the run for a duration (`for`: minutes, hours, days or weeks) or until a date
  (`until`: `2026-12-31`, `2026-12-31T09:00:00+01:00`, or a field's `{{ … }}`); a date passed
  already waits not. At most `app.workflow.max_wait_days` (30).
- What the run did before the wait is kept; the run is **waiting** (its page shows it), and the
  worker resumes it then, from the step after the wait, in a transaction of its own, as whoever
  triggered it. `$now` and `$today` are the resumed run's; `$trigger` holds the record as it was
  when the run started (a `search_records` reads it as it is). A version deactivated or replaced
  meanwhile **cancels** the run when it would resume.
- A wait is one of a definition's first steps, not inside an `if` or an `iterator`; in after-runs
  and manual runs (a manual run goes on in the worker), never before a write, nor in the system
  admin's workflows. A test on a record does not wait.

### HTTP requests

```yaml
- key: hook
  type: http_request
  method: POST                                      # GET (the default), POST, PUT, PATCH, DELETE
  url: 'https://crm.example.com/api/people/{{ $trigger.record.id }}'
  headers: { Accept: application/json }
  body: { name: '{{ $trigger.record.full_name }}' } # a map is sent as JSON; or a text
  timeout: 10                                       # seconds, 1 to 30
  retries: 2                                        # 0 to 3: again on a network error or a 5xx
```

- Its output: `status`, `headers` (by lowercase name), `body` (decoded when the answer is JSON,
  else the text): `{{ hook.body.ticket }}`. A status of 400 or more fails the step, unless
  `fail_on_error: false`. At most 1 MB is read; 3 redirects followed.
- **Never a private network**: an address of the server's own network (localhost, private and
  link-local addresses, the clouds' metadata service) is refused, whatever its name resolves to;
  `http` and `https` only. Tried again, it waits 1, then 2, then 4 seconds
  (`app.workflow.http_retry_delay_ms`).
- In after-runs, manual runs and the system admin's workflows; never before a write (no call to
  the outside while a write waits). The request and its answer are in the run's log. A
  [test on a record](#the-page) sends nothing.
- **Secrets**: tokens and keys go on the **Secrets** page of the workflows
  (`/admin/workflows/secrets`, `admin.workflows`), encrypted (`WORKFLOW_SECRETS_KEY`), a value
  never shown again once written. `{{ $secrets.crm_token }}` reads one in a request's `url`,
  `headers` and `body` only (anywhere else the activation refuses it: it could be written down in
  a record or a message); each value is `[secret]` in the run's log, its answer and its errors
  included, whether written as it is, encoded in an address, escaped in JSON or in base64; and
  never in the run's data. A request that read a secret and got no answer says so with its host
  only (the network's error tells the address as sent). A secret missing fails the activation; one deleted
  since, the step. The system admin's workflows have none.

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
- A **search** reads as a write writes: `record.<object>.read`, as whoever triggered the event
  (and its activator), or as the role of its `act_as`.
- Conditions and `refuse` need no permission: they can only stop a write, never give more.
- A `run_workflow` step needs the called workflow's run permission (`workflow.<id>.run`), held by
  the person activating the caller: on activation and still at each run. The called workflow's
  steps check their own permissions, as any step.

Defining workflows and seeing their runs needs `admin.workflows`. A run holds the data of the
records it ran on, whatever object: give `admin.workflows` to whoever may see them.

## Runs and limits

| Parameter (`config/services.yaml`) | Default | Meaning |
|---|---|---|
| `app.workflow.max_depth` | `3` | The longest chain of runs started by one another's writes (`emit_events`) or calls (`run_workflow`) |
| `app.workflow.runs_per_minute` | `100` | The runs a workspace may start a minute; beyond, a write a before-workflow guards is refused, a manual run fails, and an after-run goes back to the queue a minute later (ten times, then it fails). A run resumed after a wait counts for the minute it goes on in |
| `app.workflow.max_executed_steps` | `1000` | The steps one run may execute; beyond, the run fails (a definition holds 50 at most) |
| `app.workflow.max_run_seconds` | `300` | The seconds one run may take, checked between its steps (a run resumed after a wait, each of its parts); beyond, the run fails. A step under way is not cut: an `http_request` has its own `timeout` |
| `app.workflow.max_records` | `200` | The records one search reads, the items one iterator runs on |
| `app.workflow.max_wait_days` | `30` | The longest a run waits |
| `app.workflow.runs_keep_days` | `30` | How long the runs are kept |

**The plan's workflow steps**: a workspace's plan may limit the steps its workflows run in a
calendar month (see [Plans](workspaces.md#plans)). Once spent, no run starts until the next
month: as past `runs_per_minute`, a write a before-workflow guards is refused and a manual run
fails; a queued, scheduled or webhook run, and a waiting one resuming, fail too (no delay brings
the steps back). A run that started finishes; a sub-workflow is part of its caller's run. The page
of the workflows shows the month's steps against the plan, warned from 80%.

A run is **completed**, **failed** (an after-run or a manual run that could not finish: nothing
of it stays, since its last wait), **refused** (a before-run that refused its write), **waiting**
(stopped at a wait) or **cancelled** (its version was no longer active when it would resume). A
refused run is kept, though its write was rolled back. The steps run are counted per workspace and
day.

A definition is checked when it is activated, and the metadata follows it after: archiving an
object or a field an active workflow names is refused, with the places it is named, and renaming
one rewrites the workflows that name it (see [Renaming and archiving](lifecycle.md)). Should a step
still name an object or a field that is no more, it **fails**, with that reason, as any step that
fails. Before a write, that refuses the write: deactivate the workflow, or correct it.

A run is stored once its steps are done, whatever it holds: in an answer from outside that is not
UTF-8, the invalid bytes are replaced in the log (and a NUL character dropped). A run that still
cannot be stored (the database gone) is written to the application's log, and **not run again**:
its writes and its requests are done already.

`workflow:runs:gc` deletes the old runs of its workspace, never one that waits; with several workspaces, schedule
`workspace:run --all -- workflow:runs:gc` (see [Console commands](console.md#workflowrunsgc)).

## In packages

A package carries workflows (`metadata:package:export --workflow=<name>`), installed and upgraded
as **drafts**: an admin activates them here, and their steps act with that admin's permissions.
A new version of a package changes a workflow's draft; its active version runs on until the
draft is activated. See [Packages](packages.md#workflows).

## The system admin's workflows

In the [system console](workspaces.md#the-system-console), **Workflows** (`/console/workflows`):
the same pages (list, visual and YAML editor, versions, runs) for the workflows of the
installation, on the platform's events. The user's SaaS case: an app installed in every new
workspace.

```yaml
# Every new workspace gets the registration app.
trigger: { on: workspace_created }
steps:
  - { key: app, type: install_package, package: fedale/registration }
```

```yaml
# In acme, every new member is a clerk.
trigger: { on: member_added }
steps:
  - { key: acme_only, type: filter, when: { field: $trigger.workspace.slug, op: eq, value: acme } }
  - { key: clerk, type: assign_role, email: '{{ $trigger.member.email }}', role: ROLE_CLERK }
```

| Trigger | When | `$trigger` holds |
|---|---|---|
| `workspace_created` | a workspace made on the console's page or with `workspace:create`, once its permissions, its first admin and its package are there | `workspace` (`id`, `slug`, `name`) |
| `member_added` | someone becomes a member: an invitation accepted, `workspace:member:add`, `user:create --workspace` (not one who was a member already) | `workspace`, `member` (`email`) |

| Step | Options | Does |
|---|---|---|
| `install_package` | `package` (`vendor/name`, `vendor/name@1.0.0`), `repo` (an index, by name) | Installs it in the workspace from the indexes, with the packages it requires, all or nothing; outputs `installed` |
| `assign_role` | `email` (with `{{ … }}`), `role` | Gives a role of the workspace (it must be there when the step runs) to the email, a member of the workspace (else the step fails); outputs `given` (false when held already) |

`if`, `filter` and `refuse` work as anywhere; no other step. They act **as the system**, as the
console's commands do: no `act_as`, no permission checked; only system admins define them, and
no workspace sees them or their runs (`$user` is null, `$workspace` the workspace concerned).
They run **in the background**, once the response is sent (or the command done): one after another, each step
entering the workspace for what it does there; a failed step fails the run, what the steps before
it did stays (an error no step expects fails it too: *It broke: …*, the error in the application's
log). Their runs are listed on the console's pages. A workspace's workflow may not use
these triggers or steps, and the console defines no workflow on a record's.

## Under the hood

| Part | Where |
|---|---|
| The events | `RecordManager` raises `RecordWriting` before a write, in its transaction, and `RecordWritten` once the outermost transaction committed (`TransactionRunner::whenDone()`); `RecordEventScopeInterface` silences them (a package's seeds) or stamps them with the run they come from (Record contract) |
| The definition | `DefinitionParser` (structure, every problem by its path), `DefinitionValidator` (objects, fields, paths, roles, on activation), stored compiled as JSON (`core.workflow_version.definition`) next to the YAML as written (`source`), read by `WorkflowYaml` (unquoted dates kept as text); `DefinitionNames` finds and renames the objects and fields it names, for the metadata's archive and rename (`MetadataInWorkflows`, the Metadata contract's `MetadataDependentInterface`) |
| Secrets | `core.workflow_secret` (DBAL, row-level security; migration `Version20261116140000`), `WorkflowSecrets` (`DbalWorkflowSecrets`), sealed by `SecretBox` (libsodium's secret box, `WORKFLOW_SECRETS_KEY`, `workflow:secrets:key`); read on a copy of the run's data for the request only, masked in its log |
| HTTP | `WorkflowHttp`: Symfony's `HttpClient` through `NoPrivateNetworkHttpClient` (`app.workflow.allow_private_networks`, true in the tests only), the retries and the 1 MB cap |
| Packages | The Workflow contract's `PackagedWorkflowsInterface` (`PackagedWorkflows`: every workflow with its current definition, a definition as the app writes it, a new workflow or a draft, never activated); the Package module's `WorkflowReferences` turns objects and called workflows into ids and back; items of kind `workflow` in `core.package_item` |
| Plans | `WorkflowQuota` (the Workflow contract's `WorkflowUsageInterface`): the month's steps from `core.workflow_usage` (`RunStore::stepsThisMonth()`), the plan from Shared's `Plans` (`ConfiguredPlans`, `config/packages/plans.yaml`); checked where each run starts |
| Sub-workflows | `WorkflowEngine::call()` (in line, the called run its own `RunLog`, stored as its caller's), `ActiveWorkflows::byName()` and `called()`; the run permission made by `WorkflowManager` for `called` as for `manual` |
| Webhooks | `WebhookController` (`/hooks/<id>/<token>`, its own stateless firewall, `security.yaml`), `WebhookTokens` (`core.workflow_webhook`, the token's SHA-256; migration `Version20261116160000`), `RunWebhook` and `WorkflowEngine::runWebhook()` |
| Schedules | Symfony Scheduler: `WorkflowSchedule` (`#[AsSchedule('workflows')]`) sends `CheckSchedules` every minute, `ScheduledWorkflows` queues a `RunScheduled` for each workflow due (`dragonmantank/cron-expression`), `WorkflowEngine::runScheduled()` |
| The queue | Symfony Messenger on Redis (`config/packages/messenger.yaml`, the `async` transport, `failed` for what broke outside a run's steps: `messenger:failed:show`): `RunWorkflow` (an after-run, `WorkflowEngine::runQueued()`), `RunPlatformEvents` (the system admin's); each step checks its permission against whoever triggered it, from their assignments (`AccessCheckerInterface::userCanGrant()`), in a request as in the worker |
| The engine | `WorkflowEngine` listens to both events, reads the active versions (`ActiveWorkflows`, once per request), runs the steps over a `DataChain`, checks each write's permission (`AccessCheckerInterface::roleGives()`, `userCanGrant()`), and stores each run once its transaction ended (`RunStore`, DBAL) |
| The tables | `core.workflow`, `core.workflow_version` (ORM); `core.workflow_run`, `core.workflow_run_step`, `core.workflow_usage` (DBAL); each with its `workspace_id` (none for the system admin's) and row-level security |
| Search | `RecordFinderInterface::search()` (Record contract, `RecordFilter`, `RecordSearchResult`), its SQL in `DbalRecordStore`: identifiers from the metadata, values checked then bound and cast to the column's type |
| Manual runs | `ManualRuns` (who may run what, the answers checked), `WorkflowEngine::runManual()`; on a record's page and a grid's bulk bar through the Record contract's `RecordActionsInterface` (`WorkflowRecordActions`), the form at `/w/<workflow id>/run/<record id>` and `/w/<workflow id>/run-selection` (`ManualRunController`), the selection told back by `RecordSelectionInterface` (`GridRecordSelection`); `workflow:run` |
| The admin | `WorkflowAdminController` and the console's `ConsoleWorkflowController`, both an `AbstractWorkflowController` (their routes' prefix "wf" in the templates); `WorkflowManager` (which creates and removes the run permission, `AccessSetupInterface::ensurePermission()`) |
| The platform | `WorkspaceCreated` (Shared) and `MemberAdded` (User contract) kept by `PlatformWorkflows`, run on kernel.terminate and console.terminate in the system session (`WorkspaceSessions::asSystem()`), by `WorkflowEngine::runPlatform()`; `install_package` through the Package contract's `PackageInstallationInterface`. Their rows have no workspace (`WorkspaceScope::SYSTEM_OWNED`, migration `Version20261116100000`) |

## Not yet

Other platform events (a workspace suspended or deleted), record steps in the system admin's
workflows; a sub-workflow's input of lists or maps; email and notifications, once the application sends email. See [the plan](internal/workflow-plan.md).
