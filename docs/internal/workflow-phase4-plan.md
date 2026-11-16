# Plan: workflows, phase 4

Status: **agreed** (2026-11-16). Phase 4 of [workflow-plan.md](workflow-plan.md): the
background, waits, HTTP requests.

## Decisions (user, 2026-11-16)

1. **Symfony Messenger on Redis** (`symfony/redis-messenger`): a Redis service in `compose.yaml`,
   the PHP `redis` extension where the worker runs, `messenger:consume async` as the worker. The
   tests use the `sync://` transport.
2. **Every after-trigger runs in the background**: the write answers at once, its workflows run
   from the worker. Before-triggers stay in the write. Runs past `app.workflow.runs_per_minute`
   wait in the queue instead of failing.
3. **HTTP requests never reach a private network** (localhost, private and link-local addresses,
   the clouds' metadata): `NoPrivateNetworkHttpClient`, a timeout, a cap on the response.
4. **Three parts, a branch each**: 4a Messenger and the after-runs in the background; 4b the
   `wait` step; 4c the `http_request` step with its retries.

## 4a: the background

- **The message**: `RunWorkflow` (the workspace's id, the workflow's id and the active version's,
  what `$trigger` holds — the record as written, as it was, the fields changed —, who triggered
  it, the depth, the run that wrote it), dispatched once the write's transaction committed
  (`TransactionRunner::whenDone()`), routed to `async`.
- **The handler** enters the workspace (`WorkspaceSessions::within()`), reads the workflow: it runs
  only if the version that was active is still active (a workflow deactivated meanwhile runs no
  more); then the run as in phase 1: its steps in one transaction, all or nothing, its log stored.
- **Who it runs as**: no one is signed in in the worker. Each step that writes checks its
  permission against the user who triggered the event (`AccessCheckerInterface::userCanGrant()`:
  for a permission, holding it), the activator as before; `$user` is that user. The same code for
  the runs in a request: one way to check.
- **Limits**: past `runs_per_minute`, the message goes back to the queue, delayed a minute
  (`DelayStamp`), its run not started; the depth as before (a run's writes with `emit_events`
  queue the next runs, one deeper).
- **Manual runs** stay in the request (their result is shown); the system admin's run in the
  worker too (instead of kernel.terminate).
- **Failures**: a step failing fails the run (stored), the message is done. An exception outside
  the steps (the database down) lets Messenger retry the message (3 times, then the failure
  transport `failed`, `messenger:failed:show`).
- **Ops**: `docs/workflows.md` and `workspaces.md#running-in-production`: the worker under a
  supervisor, restarted on deploy (`messenger:stop-workers`).

### Done (2026-11-16, branch `workflow_background`)

As planned. On the way: the tests' transport is `sync://`, so a message is handled as it is sent
(a run going back to the queue comes back at once, ten times, then fails); a queued run for the
current workspace runs without a switch (a switch clears the entity manager); the Flex recipe
added `MESSENGER_TRANSPORT_DSN` to `.env`, the user's to set (`redis://localhost:6379/messages`);
the system admin's runs go to the worker from kernel.terminate and console.terminate.

## 4b: waits

- **`wait`**: `for` (`30 minutes`, `2 days`) or `until` (a date or a date-time, `{{ … }}`), at most
  `app.workflow.max_wait_days` (30). Only among a definition's top-level steps: a wait inside an
  `if` or an `iterator` would have to resume halfway through it.
- **The run waits**: its status `waiting`, its chain and the next step's position stored; a
  `ResumeRun` message delayed until then. Resumed, the steps after the wait run in a transaction
  of their own (all or nothing from there); the run's page shows the wait. A waiting run of a
  version deactivated meanwhile is cancelled when it resumes.

### Done (2026-11-16, branch `workflow_wait`)

As planned, in after-runs and manual runs (a manual run that waits goes on in the worker, its
message says so); not in the system admin's workflows. On the way: `workflow_run` gets
`resume_at` and `resume_step` (migration `Version20261116120000`); a resumed run updates its row
and adds its steps after the others; `workflow:runs:gc` keeps the waiting runs; the tests route
`ResumeRun` to an in-memory transport (`messenger.transport.delayed`) and resume when they say;
phpstan reads the test container now (`make phpstan` warms it), where that transport is.

## 4c: HTTP requests

- **`http_request`**: `method`, `url`, `headers`, `body` (a map sent as JSON, or a text), `timeout`
  (at most 30 s), `retries` (0 to 3, on a timeout or a 5xx, after 1, 2, 4 s). Output: `status`,
  `headers`, `body` (JSON decoded, else the text), at most 1 MB.
- **Where**: in after-runs, manual runs and the system admin's workflows; never before a write
  (no external call in a write's transaction). A status of 400 or more fails the step unless
  `fail_on_error: false`.
- **Safety**: `NoPrivateNetworkHttpClient`; `http`/`https` only; the request and the response are
  in the run's log (no secrets yet: a workspace's secrets come later).

### Done (2026-11-16, branch `workflow_http`)

As planned. On the way: the request is `WorkflowHttp`'s (the guard tested on its own, as the
Package module's); the tests answer through the fake server (`FakePackageServer::answer()`,
`answerAny()`); in the visual editor the headers and the body are YAML on one line; the
security review of 4b asked, and got, a resumed run's `$user` as it is now and the per-minute
limit on resuming. The review of 4c asked that the 1 MB cap stop the reading itself (a body with no
length announced): the response is read a chunk at a time, cancelled past the cap.

## Not in this phase

Secrets for the requests' headers, incoming webhooks, schedules (phase 6).
