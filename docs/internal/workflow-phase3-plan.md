# Plan: workflows, phase 3

Status: **agreed** (2026-11-16). Phase 3 of [workflow-plan.md](workflow-plan.md): the system
admin's workflows, on the platform's events.

## Decisions (user, 2026-11-16)

1. **They act as the system** in the workspace concerned, as the console's commands do
   (`workspace:create`, `metadata:package:install`): no `act_as`. Only system admins define them.
2. **Two events**: a workspace created, a member added.
3. **Two steps of their own**: `install_package`, `assign_role`; with `if`, `filter` and `refuse`.
   No record steps.
4. **The console's page reuses the admin's**: list, visual and YAML editor, versions, runs, at
   `/console/workflows`.

## The definition

```yaml
# Every new workspace gets the registration app, and its first admin the clerk's role too.
trigger: { on: workspace_created }
steps:
  - { key: app, type: install_package, package: fedale/registration }
---
trigger: { on: member_added }
steps:
  - { key: acme_only, type: filter, when: { field: $trigger.workspace.slug, op: eq, value: acme } }
  - { key: clerk, type: assign_role, email: '{{ $trigger.member.email }}', role: ROLE_CLERK }
```

- **Triggers**: `workspace_created` (from the console's page or `workspace:create`, once its
  schemas, permissions, first admin and package are there) and `member_added` (an invitation
  accepted, `workspace:member:add`, `user:create --workspace`). No `object`, no `when`.
- **The data**: `$trigger.workspace` (`id`, `slug`, `name`), `$trigger.member` (`email`, for
  `member_added`); `$user` is null, `$workspace` the workspace concerned.
- **Steps**: `install_package` (`package`: `vendor/name` or `vendor/name@1.0.0`, `repo`
  optional; from the indexes, with the packages it requires, all or nothing; its output the
  packages installed); `assign_role` (`email`, `role`: a role of the workspace at run time; its
  output whether it was given now). `if`, `filter`, `refuse` as anywhere. A workspace's workflow
  may not use these two steps; a system workflow may not use the others.

## How they run

- **After the response** (kernel.terminate, console.terminate): the events are collected, then
  each active system workflow of the event runs in the system session, its steps entering the
  workspace concerned for what they do there. Switching workspaces clears the entity manager: done
  after the response, it cannot disturb the request that raised the event.
- A run is all or nothing per step's own transaction (a package installs whole or not at all); a
  failed step fails the run. Its log is the system's (no workspace), seen in the console's runs.
- Limits: `app.workflow.max_executed_steps`; no depth (they raise no record event), no rate (the
  platform's events are few).

## Where it lives

- **Storage**: the same tables. `workflow`, `workflow_version`, `workflow_run`,
  `workflow_run_step` take a NULL `workspace_id` for the system's rows (a migration), seen by the
  system session only: `WorkspaceScope::SYSTEM_OWNED`, the ORM filter
  (`workspace_id IS NOT DISTINCT FROM` the current one), the DBAL queries the same way; row-level
  security already lets only the system session see them. A name is unique among the system's.
- **Events**: `App\Shared\Workspace\WorkspaceCreated` (dispatched by the console's page and
  `workspace:create`), `App\Module\User\Contract\Event\MemberAdded` (by `UserManager`).
- **Ports**: Package contract `PackageInstallationInterface` (open from the indexes, install);
  Access contract `WorkspaceRolesInterface::assign()`; `WorkspaceSessions::asSystem()`.
- **The console**: `/console/workflows`, the admin's controller and templates, its routes under
  another prefix; the visual editor offers the platform's triggers and steps there only. The test
  on a record is not offered for them (no record).

## Done (2026-11-16, branch `workflow_system`)

As planned. On the way: the activation checks where a workflow is defined (a platform trigger in a
workspace, a record trigger in the console: refused before anything else is checked, the
console's session having no objects); the picker offers the platform's paths without reading any
metadata; the run's usage is counted per workspace only (none for the system's); the active
workflows read for a request are forgotten on a workspace switch; no test on a record in the
console; the console's top bar links its workflows. The rbac-bundle and the policies: the tests'
database user bypasses row-level security, so the tests check through the application's queries.

## Not in this phase

Other platform events (a workspace suspended, deleted), record steps in system workflows, a test
of a system workflow on a workspace.
