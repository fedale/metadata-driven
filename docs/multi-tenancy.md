# Tenants

One installation serves several **tenants**: customers, companies or teams, each building an
application of its own. A tenant has its own objects, fields, views, forms, records, roles,
files and installed templates, and sees nothing of the others. Users are shared: one account
can be a member of several tenants, with different roles in each.

An installation starts with one tenant, `default`, which holds everything that existed before
tenants did. You add the others from the console.

## Where a tenant lives

Each tenant answers on a host of its own, `<slug>.<base host>`:

| Address | Tenant |
|---|---|
| `https://acme.example.com` | `acme` |
| `https://globex.example.com` | `globex` |
| `https://example.com`, or any other host | the default tenant (`TENANT_DEFAULT`) |

An unknown or suspended tenant answers 404. In development the base host is `localhost`, and
browsers resolve every `*.localhost` to 127.0.0.1, so `http://acme.localhost:8000` works without
any DNS.

| Setting | Default | What it does |
|---|---|---|
| `TENANT_BASE_HOST` | `localhost` | The host the tenants' hosts end with |
| `TENANT_DEFAULT` | `default` | The tenant of the base host, of any other host, and of a command without `--tenant`. Empty: every request names its tenant, and a command without `--tenant` works for none |

## The console

Operators manage the tenants from the **console of the installation**, at
`https://console.<base host>` (`console.localhost` in development), with their ordinary account:

```bash
symfony console tenant:operator grant olga@example.com    # olga may enter the console
symfony console tenant:operator                          # the operators
symfony console tenant:operator revoke olga@example.com  # her console session ends
```

- **Tenants**: the list (members, objects, status), a new one (its slug, its name, and its first
  admin, invited: they accept the link on the tenant's host, with their account or a new one, and
  hold its super admin role), suspend or resume, delete (typing the slug back; never the default
  tenant).
- **A tenant's members**: give or take the super admin role, remove, invite another admin.
- **Template indexes** offered to every tenant.

The console is no tenant: an operator has no power inside a tenant unless they're a member there,
with a role. Only operators sign in there, and only the command line makes operators. The slug
`console`, and `admin`, `www`, `api`, `mail`, `static`, are reserved: no tenant takes them.

## Creating and managing tenants

```bash
# A tenant ready to use: its schemas, its permissions, its first admin, a template
symfony console tenant:create acme --name="Acme Corp" --admin-email=anna@example.com --template=fedale/registration

symfony console tenant:list                     # slug, name, status, members, objects
symfony console tenant:suspend acme             # 404 for its requests, its data kept
symfony console tenant:resume acme
symfony console tenant:delete acme              # asks for the slug again; can't be undone
```

The slug is lower case letters, digits and dashes. It becomes the first label of the tenant's
host, so choose it as you would a subdomain. The default tenant can't be deleted. See
[Console commands](console.md#tenants) for every option.

## Working on one tenant from the console

The application's commands work for the default tenant unless you name another one with
`--tenant`:

```bash
symfony console metadata:list --tenant=acme
symfony console metadata:object:create invoice Invoice Invoices --tenant=acme
symfony console rbac:assign --tenant=acme anna@example.com ROLE_SUPER_ADMIN
```

To run one command for every tenant, each in a process of its own:

```bash
symfony console tenant:run --all -- metadata:files:gc
symfony console tenant:run --only=acme --only=globex -- access:sync
```

`tenant:run` carries on past a tenant that fails, prints each tenant's output under its slug, and
fails at the end when one of them did.

## Users

A user is one email and one password for the whole installation. A **membership** lets them sign
in to a tenant:

A tenant's admins manage its members on the **Members** page of the admin (`/admin/members`):
invite someone by email (they accept with a link, signing in or creating their account), tick
their roles, remove them. From the console, where the operator adds an account at once:

```bash
symfony console user:create anna@example.com --tenant=acme      # a new user, a member of acme
symfony console tenant:member:add globex anna@example.com        # an existing user, now of globex too
symfony console tenant:member:list globex
symfony console tenant:member:remove globex anna@example.com     # her sessions there end
```

- Signing in to a tenant you're no member of fails like a wrong password.
- You sign in to each tenant on its own: a session, and a "keep me signed in" cookie, belong to
  the tenant they were opened in, even when you're a member of several.
- Roles and permissions belong to the tenant. An admin of `acme` has no rights in `globex`
  until someone gives them a role there.
- A member of several tenants gets links to the others in the top bar.

The operator of the installation has no special power inside a tenant: they work in it as a
member with a role, like anyone else. A tenant's own admins change its model, with the
permissions of [Users and permissions](access.md).

## Templates

Every tenant installs and upgrades its templates on its own, and may stay on another version than
its neighbours. To upgrade every tenant:

```bash
symfony console tenant:run --all -- metadata:template:upgrade fedale/registration
```

A tenant that changed the template (a field added, a label changed) keeps its changes; one where
the upgrade stops (a conflict, a field of its own named like a new one) is listed at the end, to
resolve with `--tenant=<slug>`. See [Templates](templates.md#templates-and-tenants).

The template catalog is offered to every tenant. You can offer more indexes the same way, which
the tenants can use but not remove:

```bash
symfony console tenant:template:repo add acme-extra https://templates.acme.example/index.json --key=<public key>
```

## Files

Each tenant's files are in a directory of its own in the file storage (`RECORD_FILES_DIR`), named
after the tenant's id. The same file uploaded twice to one tenant is stored once; two tenants
never share a stored file. `tenant:delete` deletes the tenant's directory, and
`metadata:files:gc` runs per tenant (`tenant:run --all -- metadata:files:gc` on a schedule).

## Running in production

- **DNS and TLS.** Point a wildcard record, `*.example.com`, at the server, and serve a wildcard
  certificate: it covers the console's host, `console.example.com`, too. Pass the `Host` header through any proxy. If you set Symfony's
  `framework.trusted_hosts`, allow the subdomains: `'^(.+\.)?example\.com$'`.
- **The database role.** Connect the application as an ordinary role that owns the tables, not
  as a superuser. Row-level security, the database's own check that no tenant reads another's
  rows, doesn't apply to superusers or to roles with `BYPASSRLS`. The Docker database of
  development connects as a superuser: there the application's own filters do the work alone.

  ```sql
  CREATE ROLE metadata_app LOGIN PASSWORD '…' NOSUPERUSER NOBYPASSRLS;
  -- Create the database owned by it, or hand it the schemas and tables of an existing one.
  ```

- **Settings.** Set `TENANT_BASE_HOST` to your domain, and `TENANT_DEFAULT` to the tenant of the
  bare domain, or empty if the bare domain should answer 404.
- **Migrations and caches** work across tenants: run `doctrine:migrations:migrate` once, as
  before; it is not a per-tenant command.

## Upgrading an installation from before tenants

1. Back up the database and the file storage.
2. Run the migrations: the installation becomes the `default` tenant, its users members of it,
   its records where they were. Everyone signed in is signed out once: their sessions predate
   tenants.
3. Run `symfony console tenant:files:move` once: it moves the stored files into the default
   tenant's directory. Until then they stay readable where they are.
4. Set `TENANT_BASE_HOST`, then create the other tenants.

## Not yet

- A second factor for signing in, operators included: planned for every user at once.
- Signing up, and inviting a user to a tenant.
- One sign-in for all the tenants a user belongs to: you sign in to each host.
- `fedale/rbac-bundle`'s cache: off, because it keeps one set of roles for the whole
  installation. Roles are read from the database on every request.
- Moving a tenant to a database of its own.

How it works inside (the schemas, the session's tenant, row-level security) is in
[Architecture](architecture.md#tenants).
