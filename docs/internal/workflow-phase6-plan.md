# Plan: workflows, phase 6

Status: **agreed** (2026-11-16). Phase 6 of [workflow-plan.md](workflow-plan.md), "later".

## Decisions (user, 2026-11-16)

1. **Now: schedules.** The rest of the phase (secrets for the HTTP requests, workflows in
   packages, incoming webhooks, sub-workflows, quotas per plan) waits.
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
