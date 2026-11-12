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

### Users and tenants

Users are global: one email, one password, one profile, whichever [tenants](multi-tenancy.md)
they work in. A **membership** (`core.tenant_membership`) lets a user sign in to a tenant:

- Signing in to a tenant you're no member of fails with the same error as a wrong password.
- A session is per host, so per tenant: you sign in to each tenant on its own. The user is loaded
  again on every request, so a session whose membership was removed, or one replayed on another
  tenant's host, is signed out at its next request.
- Roles and permissions are per tenant: each tenant has its RBAC tables. Being an admin in one
  tenant gives nothing in another. Run `rbac:*` with `--tenant=<slug>` for a tenant other than the
  default one.
- A user who's a member of two tenants or more gets links to the others in the top bar.

```bash
symfony console tenant:member:add acme anna@example.com      # Anna may sign in to acme
symfony console rbac:assign --tenant=acme anna@example.com ROLE_PET_READER
symfony console tenant:member:list acme
symfony console tenant:member:remove acme anna@example.com   # her sessions there end
```

The `tenant:member:*` commands work for the tenant their first argument names. Removing a member
leaves their roles in the tenant, unused until they're a member again.

### `user:create`

```
user:create [--first-name=…] [--last-name=…] [--password=…] <email>
```

Without `--password` the password is asked, hidden (prefer it: an option ends up in the shell
history). The user becomes a member of the command's tenant (the default one, or
`--tenant=<slug>`), and starts with no permission there: assign a role or a permission. An email
that exists already is refused: add that user to another tenant with `tenant:member:add`.

Any signed-in user picks their language in the top bar (`POST /account/locale`); no permission
is needed.

## Permissions

The application checks these RBAC items:

| Item | Type | Grants |
|---|---|---|
| `record.<object>.read` | permission | The object's grid, search, export, record pages (read-only), picking its records in a relation, and downloading the files its records hold ([file fields](field-types.md#file)) |
| `record.<object>.create` | permission | Creating records |
| `record.<object>.update` | permission | Saving changes to records |
| `record.<object>.delete` | permission | Deleting records |
| `admin.metadata` | permission | The admin UI (objects, fields, search, tables) — not the records |
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
`rbac:child:remove`, `rbac:cache:clear` (after editing the `auth_*` tables by hand).

## Not yet available

- Managing users, roles and assignments from the admin UI (console only).
- Permissions per field (hidden or read-only fields per role).
- Rules on single records (e.g. "only the records I created"): the bundle supports them
  (`auth_rule`), the application does not use them yet.
