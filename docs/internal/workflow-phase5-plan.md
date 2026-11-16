# Plan: workflows, phase 5

Status: **agreed** (2026-11-16). Phase 5 of [workflow-plan.md](workflow-plan.md): states.

## Decisions (user, 2026-11-16)

1. **A field type, Status**: its settings hold the states (value and label, as a Choice's
   options), the initial one, and the transitions (a name, a label, the states it goes from, the
   one it goes to, the roles that may take it). Configured on the field's page; packages carry it
   as any field.
2. **Only the transitions change it**: read-only in the forms; changed by a transition's button,
   or by a workflow's `transition` step (5b). Any other write that changes it (a form, an import,
   `update_record`) is refused; a new record starts in the initial state. A package's seeds and
   upgrades, which are no one's write, are not held to it.
3. **Who**: whoever may update the record (`record.<object>.update`) and holds one of the
   transition's roles; no role listed: whoever may update the record.
4. **Two parts**: 5a the field type, its settings, the buttons, the guard; 5b the transition as a
   workflow trigger (before it: may refuse it, the conditions of a transition; after it: in the
   background) and the `transition` step.

## 5a: the Status field

```
States (value | label):          Initial state: draft
  draft | Draft                  Transitions (name | label | from, from | to | roles):
  sent | Sent                      send | Send | draft | sent | ROLE_CLERK, ROLE_BOSS
  paid | Paid                      pay | Mark as paid | sent | paid |
                                   reopen | Reopen | sent, paid | draft | ROLE_BOSS
```

- **FieldType**: `StatusFieldType` (`status`), its settings `states` (an option list), `initial`
  (one of them; the first by default), `transitions` (a new setting kind, `transition_list`, a
  textarea of lines as above, `TransitionListTransformer`); `StatusMachine` (FieldType contract)
  reads them. Stored as a text column, as a Choice; shown in grids and filtered as one.
- **The guard** (Record module, `StateGuard`, in `RecordManager` whatever the events say): a new
  record's status is its initial state; an update that changes a status is refused unless a
  transition is under way (`TransitionContext`), with the field's error "Changed by its
  transitions only". Not for `RecordEventScopeInterface::withoutEvents()` (a package's writes).
- **The transitions** (Record contract `RecordTransitionsInterface`, `RecordTransitions`):
  `available(record)` the transitions from its state the user may take; `apply(record, field,
  name)` checks the state and who, then updates the record through `RecordManager` (its events,
  its workflows, its validation).
- **The record's page**: a button per transition available, next to the others, posting its
  form (`/o/<object>/<id>/transition/<field>/<name>`, its token checked); the result a flash.
- **Docs**: field-types.md (Status), forms (read-only), workflows (5b), status.

### Done (2026-11-16, branch `workflow_states`)

As planned. On the way: the guard runs after the workflows before the write (so a `set_values`
changes no status either), and sets the initial state of a new record when none is given (an
import without the column); a package's write is told by `RecordEventScopeInterface::
isPackageWrite()` (its `withoutEvents()`), not by the events being off (a workflow's step may turn
them off, and is held to the rule); the form shows the field disabled, with a help text.

## 5b: transitions in workflows

- **Trigger**: `on: transitioned`, `object`, `field`, `transition` (optional: any), `when`
  (before: may refuse it — a transition's conditions; after: in the background). `$trigger` as
  an update's, with `transition`, `from`, `to`.
- **Step**: `transition` (`object`, `id`, `field`, `name`, `act_as`): takes a transition, the
  `act_as` role standing for the transition's roles.

### Done (2026-11-16, branch `workflow_transitions`)

As planned. On the way: the write events carry the transition they take (`RecordTransition`,
from the Record module's `TransitionContext`); a step's roles are checked with a new
`AccessCheckerInterface::userHolds()` (holding a role, not holding its permissions, which is
`userCanGrant()`'s rule); the security review of 5a asked, and got, the guard on the values as
stored (after the conversions and the conditions) and the state read again, locked, in the
transition's write (two transitions at once: the second is refused).
