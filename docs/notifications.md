# Notifications

A **notification** tells a member of a workspace that something happened: an invoice is overdue,
a lead came in. It goes to the **bell** in the top bar, and by email too to those who chose it.
For now the workspace's [workflows](workflows.md) send them, with their `notify` step (see
[Email and notifications](workflows.md#email-and-notifications)); other parts of the application
may later.

## The bell

Every workspace's top bar has a bell, next to your name (the system console has none):

- its **count** is the number of your unread notifications in this workspace, asked again every
  minute while the page is open;
- opening it shows your **latest ten**, the unread ones marked; following one marks it read and
  leads to its page (a record's, usually), or to the history when it has none;
- **See them all** opens `/notifications`, the history, 25 a page, with **Mark all as read**.

Each workspace keeps its own notifications: the bell of `acme` knows nothing of `globex`'s. You
read your own only: no one else, admins included, sees them.

A notification has a title, a text, the page it leads to (always a page of the workspace: never
an address elsewhere) and where it came from ("Workflow Reminders").

## By email too

On your [account page](access.md#my-account--account), **Notifications → Also by email** (off by
default) sends every notification to your email as well, in your language, from the workspace's
name, with a button to its page. The choice is your account's, so it holds in every workspace
you're a member of. Emails need email set up (see [Getting started](getting-started.md#email))
and the worker running; an email that can't be sent is written to the application's log, and
the bell has the notification all the same.

## Who gets them

Only the workspace's members, with an active account: an address that isn't one is left out,
and counted (a workflow's run says how many). An address given twice gets one notification.

## How long they're kept

90 days, read or not (`app.notification.keep_days`). `notifications:gc` deletes the older ones,
for the command's workspace; schedule it for every workspace:

```
50 3 * * * cd /srv/app && php bin/console workspace:run --all -- notifications:gc --no-interaction
```

## Under the hood

| Part | Where |
|---|---|
| The contract | The Notification module's `NotifierInterface::notify()` (`NotificationMessage`: title, text, link, origin; `Notified`: notified, left out), implemented by `Notifier`: the members read once per call (`UserManagerInterface::members()`, with their language and choice) |
| Symfony Notifier | A `WorkspaceNotification` per member, its channels its own (`getChannels()`): `inbox` always, `mail` when chosen. Both channels are the module's (`InboxChannel`, `MailChannel`, tagged `notifier.channel`): Notifier has no channel that stores, and the email goes through the application's `Mailer` (the sender, layout and language of every email) rather than Notifier's email channel |
| The table | `core.notification` (DBAL, `DbalNotificationStore`; migration `Version20261119110000`), with its `workspace_id` and row-level security like every table of a workspace; every query names its recipient |
| The pages | `NotificationController` (`/notifications`, `/notifications/menu` as a lazy Turbo Frame, `/notifications/count` in JSON, `/notifications/<id>/open`, `/notifications/read-all`); the bell in `base.html.twig` (`unread_notifications()`, `NotificationBell`) and its Stimulus controller `notification-bell` |
| The choice | `UserProfile::notifyByEmail` (`core.app_user_profile.notify_by_email`), `UserManagerInterface::chooseNotificationsByEmail()` |

## Not yet

A daily digest instead of an email each; the count pushed as it changes (it needs a hub such as
Mercure); notifications from other parts of the application (an invitation accepted, a run that
failed). See [the plan](internal/notifications-plan.md).
