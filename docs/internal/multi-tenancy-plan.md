# Plan: multi-tenancy

Status: **agreed**, not started. Discussed with the user on 2026-11-12, who chose the model
(shared definitions with a `tenant_id`, records in a schema per tenant), global users, and the
decisions below.

One installation of the app serving many **tenants** (a customer, a company), each building an
application of its own: its objects, fields, views, forms, permissions, records and files,
invisible to the others. Users are global and may belong to several tenants. "Multi-tenancy" in
[status.md](status.md)'s roadmap ideas.

Vocabulary: in PostgreSQL a *database* holds *schemas*, which hold tables. Everything here is in
**one database**: tenants are told apart by a `tenant_id` column in the shared schema and by a
schema of their own for their records.

## What a tenant is here

Each tenant builds its own model, so the record tables of two tenants differ: tenant A's
`c_account` may have a column tenant B's doesn't have, or B may have no `account` at all.
The records therefore live in a schema per tenant. The definitions they come from are rows,
and they can share tables with a `tenant_id`.

Two tenants running "the same app" install the same template, each getting its own copy of the
definitions, tracked by `template_item`. A field one tenant adds to the template's `account` is a
local item: upgrades never touch it (templates.md, "Upgrading"). That three-way upgrade is what
keeps a shared model and its per-tenant changes together.

## The model chosen (user, 2026-11-12)

The one Twenty CRM follows: shared tables with a `tenant_id` for everything that is a row
(users, definitions, files, templates, settings), a schema per tenant for the record tables.

```
core                         shared
  tenant                     the registry
  tenant_membership          (tenant_id, user_id): who may enter which tenant
  app_user                   global identities: email, password
  app_user_profile           + tenant_id: language and preferences in one tenant
  metadata_*, schema_change_log, metadata_state      + tenant_id
  file, template_installation, template_item          + tenant_id
  template_source            + nullable tenant_id: NULL = offered to every tenant
  setting, setting_scoped    tenant_id already there (fedale/setting-bundle)
  doctrine_migration_versions

t_<id>                       per tenant, until step 3 (below) is done
  auth_assignment, auth_item, auth_item_child, auth_rule

t_<id>_data                  per tenant: c_<object>, l_<object>__<field>
```

Alternatives considered and set aside: a database per tenant (queries across tenants need a
connection each), and a pair of schemas per tenant with everything in it (no `tenant_id`
anywhere, but no global users and every migration run once per tenant).

### RBAC, on standby

`fedale/rbac-bundle` has no tenants: `auth_item` has `name` as its primary key, and permission
names (`record.account.create`) collide across tenants. Adding `tenant_id` to it is a breaking
change of the bundle (composite keys, its queries, its cache) and is **on standby** (user,
2026-11-12).

Until then its four tables live in a schema per tenant, `t_<id>`. The bundle names them
unqualified, and the app never names them, so the `search_path` of the tenant switch
(`t_<id>, core, public`) sends the bundle to the current tenant's tables with no change to it.
`auth_assignment.user_id` is a string with no foreign key, so it holds the id of a global user:
the same person may be an admin in one tenant and a plain user in another.

When the bundle gets its `tenant_id`, the tables move into `core` and `t_<id>` disappears.

## Decisions (user, 2026-11-12)

1. **A request names its tenant by host**, `<slug>.<base host>`. `*.localhost` resolves to
   127.0.0.1 in browsers, so development needs no DNS. A path prefix (`/t/<slug>/…`) was set
   aside: every route and every generated URL would change.
2. **A name collision refuses the upgrade.** A tenant adds `rating` to `account`, then template
   v1.1 brings a `rating` of its own: the upgrade stops before changing anything, naming the
   local field in the way, and the tenant renames it first. No prefix for local fields.
3. **A tenant's own admins change its model**, with the `admin.*` permissions of today, checked
   in the tenant's RBAC tables. The operator of the installation has no special role in a
   tenant: they are a member with a role like anyone else.
4. **The operator creates tenants and memberships**, from the console. Self-service sign-up and
   invitations are out of scope.

## Design

### The current tenant

- `TenantContext` (`src/Shared/Tenant/`): the tenant of this request or command, its id and the
  name of its data schema (replacing `DataSchema::NAME`). It implements `ResetInterface`.
- Switching tenant does three things on the connection:
  - `SET app.tenant_id = '<id>'`, which the row-level security policies read;
  - `SET search_path TO t_<id>, core, public`, for the RBAC tables;
  - the data schema name, `t_<id>_data`, for the record SQL and the DDL of the Schema module.
- **Fail closed.** With no tenant, `app.tenant_id` is empty and the `search_path` is
  `core, public`. The policies then return no row and the RBAC tables are not found, so a query
  that forgot its tenant fails or sees nothing instead of reading someone else's data. The reset
  of the kernel (worker mode) and the end of every console command go back to this state.
- `CoreSearchPathMiddleware` goes away; the tenant switch sets the path.

### Row-level security, the safety net

Every query of the app filters by tenant explicitly. On top of that, each shared table with a
`tenant_id` gets a policy:

```sql
ALTER TABLE core.metadata_object ENABLE ROW LEVEL SECURITY;
ALTER TABLE core.metadata_object FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON core.metadata_object
    USING (tenant_id = current_setting('app.tenant_id', true)::uuid)
    WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);
```

- `FORCE` makes the policies apply to the owner of the tables too, which is the role the app
  connects with.
- The landlord's own work (`tenant:*` commands, reading `core.tenant` and
  `tenant_membership` to resolve a request) goes through tables without policies, or through a
  second role with `BYPASSRLS`. Which one is settled in phase 2.
- Migrations run as a role that bypasses the policies (they touch every tenant's rows).

### Users and memberships

- `app_user` stays global: one email, one password, one account for a person.
- `tenant_membership (tenant_id, user_id, created_at)` says which tenants they may enter.
  Signing in to a tenant they're not a member of fails like a wrong password.
- `app_user_profile` gets a `tenant_id`: a language and preferences per tenant.
- A user in several tenants gets a tenant switcher in the top bar, linking to the other
  tenants' hosts. Each host has its own session cookie, so they sign in once per tenant (a
  shared sign-in across hosts is not in scope).
- `user:create` creates the identity; `tenant:member:add <tenant> <email>` and
  `tenant:member:remove` manage memberships.

### Resolving the tenant

- **HTTP**: a `kernel.request` listener with a priority above the firewall's. It reads the
  host, looks the slug up in `core.tenant` (cached), and switches. An unknown or suspended
  tenant gets a 404, before the session is touched.
- **Sessions**: the session cookie is per host, so two tenants never share one. The tenant id is
  also written into the session at sign-in, and a session presented to another tenant is
  invalidated.
- **Console**: a global `--tenant=<slug>` option, read by a `console.command` listener. Commands
  that touch a tenant refuse to run without it. The `tenant:*` commands, `user:create` and the
  migrations are exempt.
- **Tests**: a fixed test tenant, switched to in the test kernel's boot, so the existing tests
  run unchanged. New tests use two tenants.

### Shared tables: what changes

| Change | Where |
|---|---|
| `tenant_id UUID NOT NULL`, a foreign key to `core.tenant` with `ON DELETE CASCADE` | the 11 tables of `core` holding tenant data (`metadata_field`, `metadata_form`, `metadata_object`, `metadata_state`, `metadata_translation`, `metadata_view`, `schema_change_log`, `file`, `template_installation`, `template_item`, `template_source`), and `app_user_profile` |
| Unique indexes made composite | `metadata_object (tenant_id, name)`, `metadata_form (tenant_id, name)`, `template_installation (tenant_id, name)`, `template_source (tenant_id, name)` with a partial index for the `NULL` rows, the `sha256` index of `file` becoming `(tenant_id, sha256)`, and the others found in the migrations |
| Indexes whose first column is a tenant's | the hot lookups get `tenant_id` first, so each tenant's rows are a range of the index |
| The 58 SQL statements naming `core.` (15 files) | each gets its `tenant_id` condition, and its inserts the column |
| The ORM repositories | a Doctrine SQL filter adding `tenant_id`, plus the column on the mapped entities |
| `metadata_state` | one row per tenant; the revision triggers become `FOR EACH ROW` and bump the row of the `tenant_id` they saw changed |
| `doctrine_migration_versions` | named `core.doctrine_migration_versions` in the migrations configuration, so it stays shared whatever the `search_path` |
| `schema_filter`, `DeclareDataSchemaListener` | the data schemas are `t_<id>_data`, hidden by a pattern |

Foreign keys between shared tables (a field to its object) keep working; a composite foreign
key including `tenant_id` would make a cross-tenant link impossible even by a bug, and is
considered table by table in phase 1.

### Caches

- Metadata: keyed by `metadata_state`'s instance id and revision, one row per tenant now, so the
  keys already differ between tenants. A new tenant gets a fresh `gen_random_uuid()`.
- **Audit every other pool before phase 2 is done**: the tenant lookup itself,
  `fedale/setting-bundle` (provide its `TenantProviderInterface` from `TenantContext`),
  `fedale/rbac-bundle`'s cache (`fedale_rbac.yaml`, which doesn't know the schema it read), the
  localized metadata, the translations. Any key that doesn't carry the instance id or the tenant
  id gets the tenant id.

### Files

- The bytes go under `RECORD_FILES_DIR/<tenant id>/` (a prefix per tenant on S3).
- Deduplication by SHA-256 stays **within** a tenant. Deduplicating across tenants would tell
  one tenant that another stores the same file, and would make one tenant's GC depend on all
  the others.
- `metadata:files:gc` runs per tenant.

### Templates

- An install is per tenant: definitions with its `tenant_id`, tables in its data schema, its
  `template_installation` and `template_item` rows. The other tenants see nothing.
- One tenant: `metadata:template:install acme/crm --tenant=acme`. Several or all: `tenant:run`
  (below). At creation: `tenant:create … --template=acme/crm`.
- Upgrades are per tenant too: tenants may stay on different versions, each keeping its own
  changes. `tenant:run "metadata:template:upgrade acme/crm" --all` upgrades them all, with a
  report of the conflicts per tenant.
- Indexes (`template_source`): a row with a `NULL` tenant is offered to every tenant (the
  catalog, configured by the operator); a tenant may add its own.

### Lifecycle commands

- `tenant:create <slug> --name=… [--admin-email=…] [--template=…]`: the registry row, its data
  and RBAC schemas, its `metadata_state` row, the first membership (with its admin role),
  optionally a template installed.
- `tenant:list`: slug, status, members, objects.
- `tenant:suspend` / `tenant:resume`: a suspended tenant answers 404 and its commands refuse.
- `tenant:delete <slug>`: deletes its rows (the cascade), drops its schemas and its file
  directory, after typing the slug back. Irreversible.
- `tenant:run <command> [--all | --tenant=…]`: runs a tenant command for each tenant, one
  process each, so no state leaks from one tenant to the next. It reports per tenant and
  carries on past failures.

### Migrating an existing installation

One migration turns today's single tenant into the first tenant:

1. create `core.tenant` with one row, and `tenant_membership` with every existing user;
2. add `tenant_id` to the shared tables, filled with that row's id, then `NOT NULL`, the new
   indexes, the policies;
3. `ALTER SCHEMA data RENAME TO t_<id>_data` (instant, the data stays);
4. create `t_<id>` and move the RBAC tables there (`ALTER TABLE … SET SCHEMA`);
5. move the files under their tenant's directory.

It is tested on a copy of the dev database before it runs anywhere else.

## Phases

One branch each, reviewed before the next, as usual.

1. **`tenant_id` in the shared tables, one tenant still.** `core.tenant` with one row, the
   column and its indexes, the queries and the ORM filter, `metadata_state` per tenant, the
   triggers, the data schema name behind `TenantContext`. No behaviour changes: `make qa` green
   proves it.
2. **The switch and the safety net.** The host resolver, the console option, the fail-closed
   state, the reset in worker mode, row-level security, the RBAC tables in `t_<id>`, the cache
   audit. Tests with two tenants: the same object name with different fields, and neither
   tenant's definitions, records, files or caches reachable from the other.
3. **Users and memberships**: global identities, `tenant_membership`, profiles per tenant, the
   session bound to its tenant, the tenant switcher, the membership commands. Tests of
   cross-tenant requests: a session cookie replayed on another host, a file id of another
   tenant on `/files/{id}`.
4. **Lifecycle commands**: create, list, suspend, resume, delete, run.
5. **Files per tenant**: the storage prefix, the GC per tenant, the move of existing files.
6. **Templates across tenants**: global and per-tenant indexes, the name-collision check of
   decision 2 (if the upgrade doesn't refuse it already, a test proves it either way),
   `tenant:run` over upgrades with their report.
7. **Documentation**: a `docs/multi-tenancy.md` guide; architecture.md (the shared schema, the
   data schemas, the switch, row-level security), console.md (`tenant:*`, `--tenant`),
   getting-started.md (`*.localhost`), templates.md (installing and upgrading per tenant);
   status.md.

Later, once `fedale/rbac-bundle` has tenants: its tables move into `core` and `t_<id>` goes
away.

## Not in scope

- `fedale/rbac-bundle` with a `tenant_id` (on standby, above).
- A landlord admin UI (tenants are managed from the console), self-service sign-up, invitations,
  billing, plans and quotas.
- A single sign-in shared across tenant hosts.
- Sign-in for the accounts of an app built on the platform (records with a password field):
  a separate feature, on top of this one.
- Messenger and Mercure: neither is installed. When they come, messages carry the tenant (a
  stamp, switched to by a middleware of the worker) and topics are prefixed with it.
