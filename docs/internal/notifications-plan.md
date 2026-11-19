# Plan: email and notifications

Status: **agreed** (2026-11-19). Picks up what
[workflow-phase6-plan.md](workflow-phase6-plan.md) left out ("email and notifications stay out
until the application sends email"), and the email sending
[tenant-admin-ui-plan.md](tenant-admin-ui-plan.md) put off (invitations by email, a password
reset).

## Decisions (user, 2026-11-19)

1. **Symfony Mailer and Symfony Notifier**, in the application, queued with the Messenger it
   already has. No notification service of its own (Novu was weighed and left out: MongoDB and
   several services, a React inbox, users and tenants of its own to keep in step with the
   workspaces and their row-level security). **Mailpit** in `compose.yaml` for development; any
   SMTP in production, through `MAILER_DSN`.
2. **The scope**: the email sending itself, then the invitations by email and a password reset,
   then the notifications, then the workflow steps.
3. **A workflow's `send_email` writes to the workspace's members** (by email or by role) **and to
   the addresses read from an email field of a record**; never to an address written in the
   definition or come from outside (a webhook's body): the application is no way to send spam.
   A monthly quota of emails per plan, as for the steps.
4. **Notifications are in the application** (a bell in the top bar, a page with their history),
   **and by email when the user chooses it**.

## Part A: sending email (branch `mail_sending`)

- `symfony/mailer` (and its Twig integration: `TemplatedEmail`, `symfony/twig-bundle` is there).
  `MAILER_DSN` in `.env` as `null://null` (nothing sent, nothing to configure), in `.env.local`
  `smtp://localhost:1025` for Mailpit. `MAILER_FROM`, the sender (`noreply@<base host>` by
  default); the name shown is the workspace's ("Acme Corp"), or the application's in the
  system console.
- **Mailpit** in `compose.override.yaml` (`axllent/mailpit`, SMTP on 1025, its pages on 8025):
  development only, started by `make up`. getting-started.md says where to read the mail.
- **In the background**: `SendEmailMessage` routed to `async`, the worker that already runs
  (`messenger:consume async scheduler_workflows`). In the tests, sync, and the mailer's
  assertions (`assertEmailCount()`, `getMailerMessage()`).
- **The layout**: `templates/email/base.html.twig` and its text twin; plain, inline styles only
  (no CSS framework), every text through `|trans` (domain `email`, en and it), in the
  recipient's locale (`UserManagerInterface::changeLocale()` exists; otherwise the workspace's
  default, otherwise `en`).
- **Absolute links**: an email is read away from the request, so its links name the workspace's
  host. A Shared `WorkspaceUrls` (from `app.workspace.base_host` and the scheme of
  `DEFAULT_URI`) builds `https://<slug>.<base host>/...` in a request as in the worker; the
  console's links, `console.<base host>`.
- A port of the shared kernel, `App\Shared\Mail\Mailer` (and its `Mail`), so that every module
  sends with the same sender, layout and language; Symfony's `MailerInterface` behind it, in
  SharedInfrastructure (modules may not reach SharedInfrastructure: deptrac's layers).
  `App\Shared\Workspace\WorkspaceUrls` builds the absolute links.
- `mail:test <to>`, to check the setup.
- Ops (getting-started.md, a new "Email" section): `MAILER_DSN`, `MAILER_FROM`, SPF and DKIM for
  the sender's domain, the worker must run for any email to leave.

### Done (2026-11-19, branch `mail_sending`)

As planned, with the changes above. `symfony/mailer` 8.1 (its Flex recipe: `mailer.yaml`, the
`mailer` service of `compose.override.yaml`, given fixed ports; `MAILER_DSN` in `.env`).
`MAILER_DSN` (`null://null`) and `MAILER_FROM` (empty) have defaults in `services.yaml`, the
tests always `null://null` (`mailer.yaml`). `SymfonyMailer` renders the `TemplatedEmail` in the
request, in the recipient's language (`TemplatedEmail::locale()`, the subject translated
there), and queues it (`SendEmailMessage` on `async`); `HostWorkspaceUrls` takes the scheme and
port from the request, or from `DEFAULT_URI`. Templates `templates/email/base.{html,txt}.twig`
and `mail_test`, translations `email.{en,it}.yaml`. Tests: `MailerTest` (4),
`HostWorkspaceUrlsTest` (4). Checked by hand: `mail:test` through the worker into Mailpit.

## Part B: invitations by email (branch `invitation_email`)

- The members page and the console's (a workspace's first admin) **send the invitation's link by
  email**, and still show it once, for an address that does not receive mail. Inviting again
  sends again (it replaces the pending invitation, as today).
- The email is **the same whether the address has an account or not** (nothing of the account is
  told, as decided in tenant-admin-ui-plan.md): "<inviter> invites you to <workspace>", the link,
  its expiry (7 days). In the inviter's locale: the invitee's is not known before they accept.
- `Invitation` keeps its token never stored: the email is sent from the request that made it,
  where the token is still in hand (`UserManagerInterface::invite()` returns it), by the Admin
  module's `InvitationController` and the console's controller.
- Limited by a new rate limiter, `invitation_email` (20 an hour per inviter), on top of what
  `invite()` checks.

### Done (2026-11-19, branch `invitation_email`)

As planned. `InvitationMail` (Admin UI) checks the limiter, builds the link on the workspace's
host (`WorkspaceUrls`, which replaced the console's own `workspaceUrl()`) and sends the email
(`templates/email/invitation.{html,txt}.twig`: the inviter's name, the workspace, a button and the
address, the expiry in UTC), from the workspace's name, in the inviter's language. An email that
cannot be sent leaves the invitation, its link shown, and says so. Over the limit, no invitation
is made. Tests: `AdminMemberControllerTest` (two more: the email, the same for an account and
none; the limit), `SystemConsoleTest` (the admin's email).

## Part C: password reset (branch `password_reset`)

- **"Forgot your password?"** under the sign-in form, on any host (users are global): an email
  address, and always the same answer ("if an account has it, an email is on its way"), whether
  it has an account or not.
- **The token**: 32 random bytes in the link, its SHA-256 in `core.password_reset` (the account,
  the hash, `expires_at` an hour later, `used_at`); one pending per account, a new request
  replaces it. The same way as the invitations' tokens, rather than
  `symfonycasts/reset-password-bundle` (its own entity and conventions for what the User module
  already does).
- **Limited**: `password_reset_request`, 3 a quarter of an hour per address and per IP, the try
  taken before anything is looked up.
- **The reset**: the new password twice (12 characters at least, as `changePassword()`); it ends
  every other session and remember-me cookie, as a password change does; the token is used once.
- Not for a disabled account; the system admins' log (Monolog, still to come) records it later.

### Done (2026-11-19, branch `password_reset`)

As planned, but for the timing: an address with an account costs a lookup more, an email
rendered and queued, so the answer's timing is not made equal (a millisecond or so; the limits
keep it from being measured over many tries). `UserManagerInterface::requestPasswordReset()`,
`openPasswordReset()`, `resetPassword()` (the link used in one statement, before the password
changes: of two requests with it, one only wins); `core.password_reset` (migration
`Version20261119100000`, outside the ORM like the invitations); `PasswordResetController`
(`/password/forgot`, `/password/reset/<token>`, `Referrer-Policy: no-referrer`), let through on
the console's host (`HostWorkspaceListener`); `password_reset_request`, 3 a quarter of an hour per
address and per IP, both counted. An inactive account gets no link. The link names the host as configured
(`WorkspaceUrls::ofCurrent()`, `ofConsole()`), never the request's: any unknown host reaches the
default workspace, so a forged `Host` would otherwise mail a victim a link to another site (found
by a security review). Tests: `PasswordResetControllerTest` (10).

## Part D: notifications (branch `notifications`)

A new module, **Notification**: `Notification: [NotificationContract, UserContract, Shared]`,
`NotificationContract: [Shared]` in `deptrac.modules.yaml`.

- **The contract**: `NotifierInterface::notify(list<string> $emails, NotificationMessage $message)`,
  the message a title, a text and an optional link (a path of the workspace: a record's page);
  the members only (anyone else is left out, and counted). The Workflow module uses it (part E);
  others may later (an invitation accepted, a run that failed).
- **Symfony Notifier**: a `Notification` per recipient, through two channels. `inbox`, a channel
  of the module's own (Notifier has none that stores), writes `core.notification`; `email`,
  Notifier's own, through the Mailer, for those who chose it. The channel policy reads each
  user's choice.
- **`core.notification`**: workspace, recipient (the canonical email), title, text, link,
  `created_at`, `read_at`, where it came from (a workflow and its run, when it did). Row-level
  security by workspace, as every table; a user reads their own only (the queries filter on the
  signed-in user's email). Kept 90 days: `notifications:gc` (`app.notification.keep_days`),
  scheduled like `workflow:runs:gc`.
- **The bell** in the top bar of a workspace (not the console): the count of the unread, and a
  menu of the last 10 (a Turbo Frame, loaded when it opens); the count refreshed on each page and
  every minute by a Stimulus controller (no Mercure: the application has no hub; realtime is a
  later step). `/notifications`: the history, paged; one read when its link is followed, *Mark
  all as read*.
- **The choice**: on `/account`, "Also by email" (off by default), stored on the account
  (global, as the locale). An email for each notification; a daily digest is a later step.

## Part E: workflow steps (branch `workflow_email_notify`)

```yaml
- key: tell
  type: send_email
  to:
    - { role: ROLE_ACCOUNTANT }                  # the workspace's members holding it
    - { member: anna@acme.example }              # a member, else left out
    - { field: '{{ $trigger.record.email }}' }   # an email field of a record
  subject: 'Invoice {{ $trigger.record.number }} is overdue'
  body: |
    The invoice {{ $trigger.record.number }} of {{ $trigger.record.amount }} is overdue.
- key: ping
  type: notify
  to: [{ role: ROLE_ACCOUNTANT }, { member: '{{ $user.email }}' }]
  title: 'Overdue: {{ $trigger.record.number }}'
  text: 'Due on {{ $trigger.record.due_on }}'
  link: { object: invoice, id: '{{ $trigger.record.id }}' }
```

- **`send_email`**: `to` (1 to 50 recipients once expanded), `subject` (one line), `body` (text:
  written into the layout escaped, never as HTML, so a record's value cannot inject markup or
  links). A `{ field: }` must be a path to an email field (`$trigger.record.<f>`,
  `$trigger.before.<f>`, an iterator's `item.<f>`), checked on activation by
  `DefinitionValidator` against the field's type in the metadata; its value checked again when sent (an
  address, else left out). No literal address anywhere: a `{ member: }` names a member, checked
  when sent. Sent in the background, after the run's transaction commits
  (`TransactionRunner::whenDone()`): a run that fails sends nothing.
- **`notify`**: `to` members and roles only, `title`, `text`, `link` (a record of an object:
  its page). Through `NotifierInterface`.
- **The system admin's workflows**: `send_email` to `{ member: '{{ $trigger.member.email }}' }`
  on `member_added` (a welcome), `$trigger.workspace` for the text; no `role` there (no
  workspace yet to hold it, on `workspace_created`).
- **Quota**: `emails_per_month` in `config/packages/plans.yaml` (starter 1000, business 20000,
  unlimited ~), counted per recipient in `core.workflow_usage` (a new column, a migration);
  checked before the step sends, which fails past it. The console's usage shows it next to the
  steps. Notifications are not counted (they stay in the application), but their emails are.
- **The test on a record** shows the email or the notification as it would go, and sends nothing
  (as an `http_request`: `sent: false`). **The run's log** keeps the subject and the number of
  recipients, not their addresses (a role's members are not the workflow author's to read).
- **Packages**: the new step types travel in a package's workflows with no format release: the
  format's `workflow.json` (v2) lists the trigger events, not the step types.
- The visual editor: the two step types, `to` as rows of a kind (role, member, field) and a value.

## Docs

A new guide, `docs/notifications.md` (the bell, the choice by email, the notifications' table and
their retention); `workflows.md` (the two steps, the quota); `getting-started.md` (Mailpit,
`MAILER_DSN`, `MAILER_FROM`, the worker); `workspaces.md` (invitations by email); `access.md` or
the sign-in's page (the password reset); `console.md` (`notifications:gc`); `status.md`.

## Later, not in this plan

A daily digest of the notifications; realtime (Mercure, or Turbo Streams over a hub); a sender
address per workspace (its own domain, with its DKIM); notifications from other modules (an
invitation accepted, a run failed); email bounces and unsubscribes (a provider's webhooks).
