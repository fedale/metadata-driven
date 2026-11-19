# Users and permissions

Signing in is required on every page. What a signed-in user may do is decided by RBAC
permissions (`fedale/rbac-bundle`): roles, permissions, a role hierarchy and assignments, all stored
in the database and changed at runtime.

## The first administrator

```bash
symfony console access:sync                          # the permissions and the super admin role
symfony console user:create you@example.com --first-name=Ada --last-name=Lovelace   # asks the password
symfony console rbac:assign you@example.com ROLE_SUPER_ADMIN
```

Then sign in at `/login`. A super admin passes every check, including those of objects created
later.

## Users

A user is two records, one-to-one:

| Table | Holds |
|---|---|
| `core.app_user` | The login identity: email (unique, stored lowercase), password hash, active flag, last login |
| `core.app_user_profile` | The person: first and last name (the name shown in the top bar), the language of the application (see [Languages](i18n.md#languages-of-the-users)) |

Passwords are hashed with Symfony's `auto` hasher and rehashed on sign-in when the algorithm or
its cost changes; at least 12 characters. An unknown and a deactivated user get the same error.

### Users and workspaces

Users are global: one email, one password, one profile, whichever [workspaces](workspaces.md)
they work in. A **membership** (`core.workspace_membership`) lets a user sign in to a workspace:

- Signing in to a workspace you're no member of fails with the same error as a wrong password.
- A session belongs to the workspace it was opened in: you sign in to each workspace on its own, even
  when you're a member of several. The user is loaded again on every request, so a session whose
  membership was removed, or one replayed on another workspace's host, is signed out at its next
  request. A "keep me signed in" cookie, likewise, signs you in to its own workspace only.
- Roles and permissions are per workspace: each workspace has its RBAC tables. Being an admin in one
  workspace gives nothing in another. Run `rbac:*` with `--workspace=<slug>` for a workspace other than the
  default one.
- A user who's a member of two workspaces or more gets links to the others in the top bar.

```bash
symfony console workspace:member:add acme anna@example.com      # Anna may sign in to acme
symfony console rbac:assign --workspace=acme anna@example.com ROLE_PET_READER
symfony console workspace:member:list acme
symfony console workspace:member:remove acme anna@example.com   # her sessions there end
```

The `workspace:member:*` commands work for the workspace their first argument names. Removing a member
leaves their roles in the workspace, unused until they're a member again.

### System admins

System admins sign in to the system console, `console.<base host>`, to manage the
workspaces (see [Workspaces](workspaces.md#the-system-console)). `system:admin grant|revoke <email>`
makes them, from the command line only; there they hold `ROLE_SYSTEM_ADMIN`, and nothing in any
workspace they're no member of. Signing in, everywhere, is throttled: five tries a minute per address
and email.

### The members page — `/admin/members`

The same, from the admin, for whoever holds `admin.members` (or the super admin role), in each
workspace: linked from the top bar as **Members**.

- **The members** of the workspace: name, email, roles, last sign-in. Members of other workspaces
  aren't listed.
- **Invite someone** by email. The invitation's link goes to them by email, in your language,
  and is shown to you once too, to pass on if the email doesn't arrive (an address that receives
  no mail, or email not set up: see [Getting started](getting-started.md#email)); it lasts 7
  days, and pending invitations are listed, to revoke. You send at most 20 invitations an hour. Whoever opens it at
  `/invitation/…` joins the workspace and is signed in: with the password of their account if the
  email has one, or choosing a password (12 characters at least) and their name. Five wrong
  passwords burn the invitation, and an account gets five tries a quarter of an hour whatever the
  invitation, so inviting again gives no more guesses. One invitation per email at a time: your
  new one replaces yours, another admin's must be revoked first; a member can't be invited.
- **Roles**: a checkbox per role of the workspace, saved per member. Permissions assigned directly
  with `rbac:assign` are left alone.
- **Remove** a member: they can no longer sign in here, and their session ends at its next
  request.
- **Your own row can't be changed**: another admin changes your roles or removes you, so no one
  locks themselves out.
- **You give only what you have**: you can give and take only the roles you hold yourself
  (a super admin holds them all). A member holding a role you don't, or a permission assigned
  to them directly that you don't hold, is out of your reach: you
  can't change their roles, remove them, or add them back once removed (a removed member keeps
  their roles here). Managing members never makes anyone more powerful than the admin doing it.

Accounts are shared by every workspace, so the page tells you nothing about the email you invite:
the answer, and the email sent, are the same whether it has an account or not, and no name shows until the invitation
is accepted. Only opening the link yourself would show whether it asks for an existing password,
and each try costs an invitation. A former member invited back by an admin who doesn't hold all
they had here comes back without it.

### `user:create`

```
user:create [--first-name=…] [--last-name=…] [--password=…] <email>
```

Without `--password` the password is asked, hidden (prefer it: an option ends up in the shell
history). The user becomes a member of the command's workspace (the default one, or
`--workspace=<slug>`), and starts with no permission there: assign a role or a permission. An email
that exists already is refused: add that user to another workspace with `workspace:member:add`.

Any signed-in user picks their language in the top bar (`POST /account/locale`); no permission
is needed.

### My account — `/account`

Every signed-in user, in any workspace and on the console, reaches their account from their name in
the top bar:

- **Name**: first and last name, the name shown everywhere (accounts are global: every workspace
  sees the change).
- **Password**: the current one, then the new one twice (12 characters at least). Five wrong
  current passwords a quarter of an hour, then it waits: a session left open is no way to guess
  it. Changing it ends every other session of the account, on other devices and in other workspaces,
  and its "keep me signed in" cookies; this one goes on.

## Permissions

The application checks these RBAC items:

| Item | Type | Grants |
|---|---|---|
| `record.<object>.read` | permission | The object's grid, search, export, record pages (read-only), picking its records in a relation, and downloading the files its records hold ([file fields](field-types.md#file)) |
| `record.<object>.create` | permission | Creating records |
| `record.<object>.update` | permission | Saving changes to records |
| `record.<object>.delete` | permission | Deleting records |
| `admin.metadata` | permission | The admin UI (objects, fields, search, tables) — not the records |
| `admin.members` | permission | The members page, `/admin/members`: who may sign in to the workspace, with which roles |
| `admin.roles` | permission | The roles page, `/admin/roles`: which permissions each role gives |
| `admin.workflows` | permission | The workflows page, `/admin/workflows`: the workflows of the workspace, their versions and their runs (see [Workflows](workflows.md#permissions)) |
| `workflow.<workflow id>.run` | permission | Running a manual workflow, from a record's page; calling a workflow on `called` from another one, for whoever activates it (see [Sub-workflows](workflows.md#sub-workflows)); created when a manual or called version of it is activated, removed with the workflow (see [Manual workflows](workflows.md#manual-workflows)) |
| `ROLE_SUPER_ADMIN` | role | Everything, now and in the future |

The four permissions of an object are created with it (by the admin or the console). `access:sync`
creates whatever is missing, for objects created before access control existed; it never removes
anything. Renaming an object renames its permissions, and the roles and users holding them keep
them; purging an archived object removes them (see [Renaming and archiving](lifecycle.md)).

A user without `read` does not see the object on the home page; without `create` the grid has no
"New" button; without `update` a record opens read-only; without `delete` it has no "Delete"
button. Every action is checked on the server as well (403).

Where objects meet:

- The "related records" lists of a record only show the objects the user may read.
- A deletion that cascades (a relation with `onDelete: cascade`) needs the `delete` permission of
  every object it can reach; otherwise it is refused, and nothing is deleted. Clearing references
  (`set_null`) is part of the deletion and needs no extra permission.
- The grid UI settings modal changes the grids of every user: it needs `admin.metadata`.

## Roles

### The roles page — `/admin/roles`

Whoever holds `admin.roles` (or the super admin role) defines the workspace's roles from the admin,
linked from the top bar as **Roles**: a new role (`ROLE_` and capitals, a description, its
permissions, grouped by object), a role's permissions changed, a role deleted. Members get roles on
[the members page](#the-members-page--adminmembers). Defining roles never gives more than you have:

- a role gets only **permissions you hold**;
- you change a role only when you hold every permission it gives, and everyone holding it
  holds nothing you couldn't give yourself: members, and those who aren't (a removed member
  keeps their roles; an invited admin gets theirs ahead of their account); through the hierarchy
  too (a role contained in another counts as that one, its holders as its);
- you delete a role only when, on top of that, no one holds it and no role contains it;
- the super admin role is never changed here, and roles within roles stay the console's
  (`rbac:child:add`, below): shown, not changed.

On the members page you give a role you hold, or one all of whose permissions you hold: giving it
then gives nothing you don't have. The super admin role only a super admin gives.

### From the command line

Roles are groups of permissions and of other roles, defined at runtime with the commands of the
bundle — no code, no `security.yaml`:

```bash
# A reader of pets, and an editor who is also a reader
symfony console rbac:item:add ROLE_PET_READER --type=role
symfony console rbac:child:add ROLE_PET_READER record.pet.read

symfony console rbac:item:add ROLE_PET_EDITOR --type=role
symfony console rbac:child:add ROLE_PET_EDITOR ROLE_PET_READER
symfony console rbac:child:add ROLE_PET_EDITOR record.pet.create
symfony console rbac:child:add ROLE_PET_EDITOR record.pet.update

# Give it to a user; a permission can also be assigned directly
symfony console rbac:assign anna@example.com ROLE_PET_EDITOR
symfony console rbac:assign anna@example.com record.contact.read

# Check
symfony console rbac:list-assignments anna@example.com
symfony console rbac:check anna@example.com record.pet.update
```

Changes apply on the next request. A **permission** assigned or revoked works at once, without
signing in again. A **role** changed (`ROLE_SUPER_ADMIN` above all) signs the user's sessions out:
Symfony fixes the roles of a session at sign-in, so a revoked role would otherwise keep working
until logout. A user signed in with "remember me" is signed back in at once, with the new roles;
the others sign in again. Assignments are keyed by the user's email.

Other commands of the bundle: `rbac:list-items`, `rbac:revoke`, `rbac:item:remove`,
`rbac:child:remove`, `rbac:cache:clear` (after editing the `auth_*` tables by hand; it clears the
cache of the command's workspace, so `--workspace=<slug>` for another one).

## Not yet available

- Defining roles, and managing users beyond the members of a workspace, from the admin UI (console only).
- Permissions per field (hidden or read-only fields per role).
- Rules on single records (e.g. "only the records I created"): the bundle supports them
  (`auth_rule`), the application does not use them yet.
