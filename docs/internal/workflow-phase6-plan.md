# Plan: workflows, phase 6

Status: **agreed** (2026-11-16). Phase 6 of [workflow-plan.md](workflow-plan.md), "later".

## Decisions (user, 2026-11-16)

1. **Now: schedules**, then **the workspaces' secrets**, then **incoming webhooks** (asked the
   same day), then **sub-workflows**, then **workflows in packages**. Quotas per plan wait.
2. **Email and notifications stay out** until the application sends email.

## Schedules

```yaml
trigger: { on: schedule, cron: '0 8 * * 1' }   # UTC
steps: [...]                                    # every step reading or writing records: act_as
```

- **Trigger** `schedule`, `cron` (validated with `dragonmantank/cron-expression`), in UTC;
  `$trigger.scheduled_at` the minute due. No record, no one: `$user` null, and every step that
  reads or writes records needs an `act_as` (checked on activation).
- **Symfony Scheduler**: a schedule of the Workflow module, `workflows`
  (`WorkflowSchedule`, stateful, the last missed run only), sends `CheckSchedules` every minute;
  `ScheduledWorkflows` reads, workspace by workspace, the active scheduled workflows due that
  minute and queues a `RunScheduled` each (async); the engine runs it in its workspace, if the
  version due is still active, its steps in one transaction.
- **Ops**: the worker consumes `async scheduler_workflows`; one worker only for the second.

### Done (2026-11-16, branch `workflow_schedule`)

As planned. The Flex recipe of `symfony/scheduler` added `src/Schedule.php`, the application's
default schedule, empty: kept for the application's own tasks; the workflows have theirs.

## Secrets

Decisions (user, 2026-11-16): a key of their own, `WORKFLOW_SECRETS_KEY`; read in an
`http_request` only; managed by whoever holds `admin.workflows`.

- **Storage**: `core.workflow_secret` (a workspace's, row-level security, DBAL), its value sealed
  with libsodium's secret box (`SecretBox`); `workflow:secrets:key` prints a key. No key: no
  secret written nor read, the page and the step say why.
- **The page**: `/admin/workflows/secrets`, linked from the workflows' list: the names, when and
  who changed them, a value written (or replaced) and never shown again, a secret deleted.
- **In a workflow**: `{{ $secrets.<name> }}` in an `http_request`'s `url`, `headers` and `body`
  only, checked on activation (anywhere else, or a name missing, refused). The engine puts the
  values on a copy of the run's data for the request alone, and masks them (`[secret]`) in the
  step's input, output and error; the picker lists them.

### Done (2026-11-16, branch `workflow_secrets`)

As planned. The tests' key is a parameter of `when@test` (`app.workflow.secrets_key`). The
security review asked, and got, every form of a secret masked (as it is, in an address, in JSON,
in base64), the error a request wraps not chained, and a network's error of a request that read a
secret told by its host only.

## Incoming webhooks

Chosen without asking (the user's earlier choices for schedules and secrets as the guide):

- **Trigger** `on: webhook`, nothing else; `$trigger.body` (JSON decoded, else the text, at most
  64 KB), `$trigger.query`. No one behind it: `act_as` on every step reading or writing records.
- **Its address**: `/hooks/<workflow id>/<token>` on the workspace's host, the token in the path
  (systems outside can set an address, not always a header); kept as its SHA-256
  (`core.workflow_webhook`), shown once when made on the workflow's page, made again to replace
  it. Its own firewall, stateless, no one signed in.
- **The answer**: `202` and the run in the worker; `404` for anything wrong (no hint of which);
  `429` past the workspace's runs a minute, nothing queued (a flood fills no queue).

### Done (2026-11-16, branch `workflow_webhook`)

As above. The unattended runs (schedule, webhook) share `WorkflowEngine::runUnattended()`. The
security review asked, and got, the requests counted as they come (a rate limiter per workspace,
`workflow_webhook`), not the runs stored: those are stored once the worker ran them, after a
burst.

## Sub-workflows

Decisions (user, 2026-11-16): **in line** (the called workflow runs inside its caller's run and
transaction, its result read by the steps after); a **`return`** step gives the values back;
**who may call** is the called workflow's run permission, as a manual one's.

- **Trigger** `on: called`, its `input` the inputs of a manual form (label optional), read as
  `$trigger.input.<name>`; no record, `$user` the caller's.
- **Step** `run_workflow`: `workflow` (the called one's name), `input` (its inputs to values,
  checked as a form's answers). Its output: the values of the `return` that ended it (`{}`
  without one). **Step** `return` (`values` optional): only in a called workflow, it ends it, from
  a branch or an iterator too.
- **Its run**: a `RunLog` of its own (action and timing `called`), one level deeper, as whoever
  triggered its caller; stored as its caller is, once the transaction ended. `max_depth` stops a
  chain of calls (a workflow calling itself too); each call counts in the runs a minute.
- **Permissions**: the person activating a caller holds `workflow.<id>.run` of the called one, on
  activation (`DefinitionValidator`) and at each run (`userCanGrant`); the called workflow's steps
  check theirs against its own activator, as any step.
- **Failures**: its failure fails the step (and the caller); called before a write, its `refuse`
  refuses the write with its message. No `wait` in a called workflow (its caller waits for no
  one), no `http_request` when called before a write (the step fails at the run).

### Done (2026-11-16, branch `sub_workflows`)

As above. The visual editor offers the trigger (its inputs edited as a form's), both steps (the
active workflows on `called` to choose from, their inputs), and the picker the values a called
workflow may return. The inputs are scalars (a form's types): lists and maps wait.

## Workflows in packages

Decisions (user, 2026-11-16): installed **as drafts** (no one to lend them permissions at an
install); the whole cycle (the format, export, install, upgrade, version numbers); an upgrade
writes the new version as the workflow's **draft**, the active one running on, a change on both
sides a conflict.

- **The format** (fedale/metadata-driven-package-format 2.3.0): kind `Workflow`
  (`workflows/<name>.yaml`): `name`, `description`, `definition` with the objects of its trigger
  and steps, and the workflow of each `run_workflow`, as ids; no platform event. The checker
  resolves the ids (in the package, then the packages it requires); `ItemStates` makes each
  workflow an item (its definition's keys sorted: jsonb orders them its own way); `VersionCheck`:
  added or definition changed, a minor; description, a patch; removed or renamed, a major.
- **The app**: the Workflow contract's `PackagedWorkflowsInterface` (Package may depend on
  WorkflowContract now); items of kind `workflow` in `core.package_item` (its local id the
  workflow's). The exporter (`--workflow`), the installer (drafts, a local name refused), the
  owned state (the current definition: draft, else active, else latest), the upgrader (the new
  version's definitions made canonical first, through stand-in names; drafts; removals
  deactivated and left to the instance).

### Done (2026-11-16, branch `workflow_packages`)

As above; the library tagged 2.3.0 by the user, required as `^2.3`. Found on the
way: `DoctrineWorkflowRepository::remove()` left the versions managed, which failed the next flush
in the same request; they go with their workflow now. A workflow references objects by name: an
object renamed breaks it (in packages and out), as before.
