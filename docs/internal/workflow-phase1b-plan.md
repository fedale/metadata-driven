# Plan: workflows, phase 1b

Status: **done** (2026-11-15): part 1 merged into `main`; part 2 on branch
`workflow_visual_editor`, waiting for the user's review. Phase 1b of [workflow-plan.md](workflow-plan.md), after
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

## Part 2: the visual editor, as delivered

- `symfony/ux-live-component` 3.7 (its Flex recipe: the bundle, `/_components`, the Stimulus
  controller). Its actions are guarded by the `X-Requested-With` header and its props by a
  checksum; `WorkflowEditor` checks `admin.workflows` in `mount()`, after every hydration and so in
  every action.
- `EditorModel`: a draft's YAML read tolerantly into a model the page binds to (every option of
  every step; values as rows; conditions as all/any of tests, a deeper one kept as YAML) and
  written back; the round trip gives the same definition, without the comments.
- `WorkflowEditor` (`templates/admin/workflow_editor.html.twig`): the trigger, the steps with their
  branches, add/move/remove steps, branches, values and tests; save, activate (the problems shown
  under the step whose path they start with), test. The test moved to `WorkflowTester`, shared by
  both tabs.
- The edit page has two tabs, visual by default (`?mode=yaml` for the YAML); the YAML tab opens by
  itself when the YAML cannot be read, and after its form is sent.
- The variable picker inserts into the input last focused in either tab.
- Not done: dragging steps (↑ and ↓ move them); a condition deeper than all/any of tests in the
  visual tab.
