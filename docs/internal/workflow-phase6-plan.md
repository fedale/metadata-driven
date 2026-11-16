# Plan: workflows, phase 6

Status: **agreed** (2026-11-16). Phase 6 of [workflow-plan.md](workflow-plan.md), "later".

## Decisions (user, 2026-11-16)

1. **Now: schedules**, then **the workspaces' secrets** (asked the same day). The rest of the
   phase (workflows in packages, incoming webhooks, sub-workflows, quotas per plan) waits.
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
