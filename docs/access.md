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
| `core.user_profile` | The person: first and last name (the name shown in the top bar) |

Passwords are hashed with Symfony's `auto` hasher and rehashed on sign-in when the algorithm or
its cost changes; at least 12 characters. An unknown and a deactivated user get the same error.

### `user:create`

```
user:create [--first-name=…] [--last-name=…] [--password=…] <email>
```

Without `--password` the password is asked, hidden (prefer it: an option ends up in the shell
history). The user starts with no permission: assign a role or a permission.

## Permissions

The application checks these RBAC items:

| Item | Type | Grants |
|---|---|---|
| `record.<object>.read` | permission | The object's grid, search, export, record pages (read-only), and picking its records in a relation |
| `record.<object>.create` | permission | Creating records |
| `record.<object>.update` | permission | Saving changes to records |
| `record.<object>.delete` | permission | Deleting records |
| `admin.metadata` | permission | The admin UI (objects, fields, search, tables) — not the records |
| `ROLE_SUPER_ADMIN` | role | Everything, now and in the future |

The four permissions of an object are created with it (by the admin or the console). `access:sync`
creates whatever is missing, for objects created before access control existed; it never removes
anything.

A user without `read` does not see the object on the home page; without `create` the grid has no
"New" button; without `update` a record opens read-only; without `delete` it has no "Delete"
button. Every action is checked on the server as well (403).

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

Changes apply on the next request, without signing in again: the roles of the user are reloaded on
every request. Assignments are keyed by the user's email.

Other commands of the bundle: `rbac:list-items`, `rbac:revoke`, `rbac:item:remove`,
`rbac:child:remove`, `rbac:cache:clear` (after editing the `auth_*` tables by hand).

## Not yet available

- Managing users, roles and assignments from the admin UI (console only).
- Permissions per field (hidden or read-only fields per role).
- Rules on single records (e.g. "only the records I created"): the bundle supports them
  (`auth_rule`), the application does not use them yet.
