# Plan: workflows, phase 1b

Status: **agreed** (2026-11-15). Part 1 done on branch `workflow_picker_test` (2026-11-15), waiting
for the user's review. Phase 1b of [workflow-plan.md](workflow-plan.md), after
[phase 1](workflow-phase1-plan.md): the editor's helpers, then the visual editor.

## Decisions (user, 2026-11-15)

1. **The visual editor is a Live Component** (`symfony/ux-live-component`): the server holds the
   definition and renders it again on each change; the checks and the variable picker are the
   PHP code of phase 1.
2. **Visual and YAML, in two tabs**: visual by default, YAML for whoever prefers it. A change made
   visually writes the YAML again: its comments are lost, and the page says so.
3. **The test runs the draft for real against a record, then rolls back**: each step's input,
   output and error shown, nothing kept (no record written, no run stored), with the permissions
   of whoever tests it.
4. **In two branches**: the variable picker and the test first, next to the YAML editor; the
   visual editor after.

## Part 1: the picker and the test

- **The variable picker**, next to the editor: the paths the draft can read, grouped: the
  trigger's record (each field of its object), the record before an update, `$trigger.changed`,
  `$user`, `$workspace`, `$now`, `$today`, `$last`, and each step's output by its key (a write's
  record and its fields, an if's branch, a filter's result). A click puts `{{ path }}` where the
  cursor is (a Stimulus controller). Which steps a path may read is still the validator's.
- **The test**: a record of the trigger's object chosen on the page (the latest ones, by their
  label), *Test on this record* saves the draft, checks it as an activation would, then runs it
  in a transaction rolled back at the end: a before-trigger as an update of the record with its
  own values (a create for `created`), an after-trigger as the record written. Its writes raise no
  event (no other workflow runs), whatever `emit_events` says; the limits don't apply; the result
  is shown on the page, as a run's.

## Part 2: the visual editor

To be detailed when part 1 is merged.
