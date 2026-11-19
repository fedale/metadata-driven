# Workspaces

One installation serves several **workspaces**: customers, companies or teams, each building an
application of its own. A workspace has its own objects, fields, views, forms, records, roles,
files and installed packages, and sees nothing of the others. Users are shared: one account
can be a member of several workspaces, with different roles in each.

An installation starts with one workspace, `default`, which holds everything that existed before
workspaces did. You add the others from the console.

## Where a workspace lives

Each workspace answers on a host of its own, `<slug>.<base host>`:

| Address | Workspace |
|---|---|
| `https://acme.example.com` | `acme` |
| `https://globex.example.com` | `globex` |
| `https://example.com`, or any other host | the default workspace (`WORKSPACE_DEFAULT`) |

An unknown or suspended workspace answers 404. In development the base host is `localhost`, and
browsers resolve every `*.localhost` to 127.0.0.1, so `http://acme.localhost:8000` works without
any DNS.

| Setting | Default | What it does |
|---|---|---|
| `WORKSPACE_BASE_HOST` | `localhost` | The host the workspaces' hosts end with |
| `WORKSPACE_DEFAULT` | `default` | The workspace of the base host, of any other host, and of a command without `--workspace`. Empty: every request names its workspace, and a command without `--workspace` works for none |

## The system console

System admins manage the workspaces from the **system console**, at
`https://console.<base host>` (`console.localhost` in development), with their ordinary account:

```bash
symfony console system:admin grant olga@example.com    # olga may enter the console
symfony console system:admin                          # the system admins
symfony console system:admin revoke olga@example.com  # their console session ends
```

- **Workspaces**: the list (members, objects, status, plan), a new one (its slug, its name, and its first
  admin, invited: the link goes to them by email, from the workspace, and is shown once too; they
  accept it on the workspace's host, with their account or a new one, and hold its super admin
  role), suspend or resume, delete (typing the slug back; never the default
  workspace).
- **A workspace's members**: give or take the super admin role, remove, invite another admin.
- **A workspace's plan**, and its workflow steps this month (see [Plans](#plans)).
- **Package indexes** offered to every workspace.
- **Workflows** on the platform's events: a workspace created, a member added; install a package,
  give a role (see [Workflows](workflows.md#the-system-admins-workflows)).

The console is no workspace: a system admin has no power inside a workspace unless they're a member there,
with a role. Only system admins sign in there, and only the command line makes system admins. The slug
`console`, and `admin`, `www`, `api`, `mail`, `static`, are reserved: no workspace takes them.

## Creating and managing workspaces

```bash
# A workspace ready to use: its schemas, its permissions, its first admin, a package
symfony console workspace:create acme --name="Acme Corp" --admin-email=anna@example.com --package=fedale/registration

symfony console workspace:list                     # slug, name, status, plan, members, objects
symfony console workspace:plan acme business       # its plan (without one: shown)
symfony console workspace:suspend acme             # 404 for its requests, its data kept
symfony console workspace:resume acme
symfony console workspace:delete acme              # asks for the slug again; can't be undone
```

The slug is lower case letters, digits and dashes. It becomes the first label of the workspace's
host, so choose it as you would a subdomain. The default workspace can't be deleted. See
[Console commands](console.md#workspaces) for every option.

## Plans

A workspace is on a **plan**, which limits what it uses. The plans are in
`config/packages/plans.yaml`, with the code:

```yaml
parameters:
    app.default_plan: unlimited          # a workspace with no plan of its own
    app.plans:
        starter:
            label: Starter
            workflow_steps_per_month: 5000   # ~ for no limit
            emails_per_month: 1000
        business: { label: Business, workflow_steps_per_month: 100000, emails_per_month: 20000 }
        unlimited: { label: Unlimited, workflow_steps_per_month: ~, emails_per_month: ~ }
```

- **Workflow steps a month**: the steps its workflows run in a calendar month (the database's
  date). Once spent, no run starts until the next month: a manual run, a queued one, a scheduled
  one, a webhook's, a waiting one resuming fail, telling why; a write a workflow guards before it
  is refused. A run that started finishes, whatever it spends. Its admins see the month's steps
  on the workflows page, warned from 80%. See [Workflows](workflows.md#runs-and-limits).
- **Emails a month**: the emails its workflows send in a calendar month, one per recipient
  (`send_email`, and the emails of `notify`). Once spent, a `send_email` fails and a `notify`
  reaches the bells only. The console shows the month's emails next to the steps.
- **Choosing one**: in the console, on the workspace's page; `workspace:create --plan=…`;
  `workspace:plan <slug> <plan>`. A workspace whose plan is no longer configured is on the
  default one.

## Working on one workspace from the console

The application's commands work for the default workspace unless you name another one with
`--workspace`:

```bash
symfony console metadata:list --workspace=acme
symfony console metadata:object:create invoice Invoice Invoices --workspace=acme
symfony console rbac:assign --workspace=acme anna@example.com ROLE_SUPER_ADMIN
```

To run one command for every workspace, each in a process of its own:

```bash
symfony console workspace:run --all -- metadata:files:gc
symfony console workspace:run --only=acme --only=globex -- access:sync
```

`workspace:run` carries on past a workspace that fails, prints each workspace's output under its slug, and
fails at the end when one of them did.

## Users

A user is one email and one password for the whole installation. A **membership** lets them sign
in to a workspace:

A workspace's admins manage its members on the **Members** page of the admin (`/admin/members`):
invite someone by email (they accept with a link, signing in or creating their account), tick
their roles, remove them. From the console, where the system admin adds an account at once:

```bash
symfony console user:create anna@example.com --workspace=acme      # a new user, a member of acme
symfony console workspace:member:add globex anna@example.com        # an existing user, now of globex too
symfony console workspace:member:list globex
symfony console workspace:member:remove globex anna@example.com     # her sessions there end
```

- Signing in to a workspace you're no member of fails like a wrong password.
- You sign in to each workspace on its own: a session, and a "keep me signed in" cookie, belong to
  the workspace they were opened in, even when you're a member of several.
- Roles and permissions belong to the workspace. An admin of `acme` has no rights in `globex`
  until someone gives them a role there.
- A member of several workspaces gets links to the others in the top bar.

The system admin has no special power inside a workspace: they work in it as a
member with a role, like anyone else. A workspace's own admins change its model, with the
permissions of [Users and permissions](access.md).

## Packages

Every workspace installs and upgrades its packages on its own, and may stay on another version than
its neighbours. To upgrade every workspace:

```bash
symfony console workspace:run --all -- metadata:package:upgrade fedale/registration
```

A workspace that changed the package (a field added, a label changed) keeps its changes; one where
the upgrade stops (a conflict, a field of its own named like a new one) is listed at the end, to
resolve with `--workspace=<slug>`. See [Packages](packages.md#packages-and-workspaces).

The package catalog is offered to every workspace. You can offer more indexes the same way, which
the workspaces can use but not remove:

```bash
symfony console system:package:repo add acme-extra https://packages.acme.example/index.json --key=<public key>
```

## Files

Each workspace's files are in a directory of its own in the file storage (`RECORD_FILES_DIR`), named
after the workspace's id. The same file uploaded twice to one workspace is stored once; two workspaces
never share a stored file. `workspace:delete` deletes the workspace's directory, and
`metadata:files:gc` runs per workspace (`workspace:run --all -- metadata:files:gc` on a schedule).

## Running in production

- **DNS and TLS.** Point a wildcard record, `*.example.com`, at the server, and serve a wildcard
  certificate: it covers the console's host, `console.example.com`, too. Pass the `Host` header through any proxy. If you set Symfony's
  `framework.trusted_hosts`, allow the subdomains: `'^(.+\.)?example\.com$'`.
- **The database role.** Connect the application as an ordinary role that owns the tables, not
  as a superuser. Row-level security, the database's own check that no workspace reads another's
  rows, doesn't apply to superusers or to roles with `BYPASSRLS`. The Docker database of
  development connects as a superuser: there the application's own filters do the work alone.

  ```sql
  CREATE ROLE metadata_app LOGIN PASSWORD '…' NOSUPERUSER NOBYPASSRLS;
  -- Create the database owned by it, or hand it the schemas and tables of an existing one.
  ```

- **Settings.** Set `WORKSPACE_BASE_HOST` to your domain, and `WORKSPACE_DEFAULT` to the workspace of the
  bare domain, or empty if the bare domain should answer 404.
- **Migrations and caches** work across workspaces: run `doctrine:migrations:migrate` once, as
  before; it is not a per-workspace command.
- **The workflows' worker.** Run `php bin/console messenger:consume async scheduler_workflows --time-limit=3600`
  under a supervisor (systemd, Supervisor), against the Redis of `MESSENGER_TRANSPORT_DSN`, with
  the PHP `redis` extension; on each deploy, `messenger:stop-workers` (the supervisor starts it
  again, on the new code). It serves every workspace: each message names its own. One worker only
  consumes `scheduler_workflows` (the schedules): two would start each scheduled workflow twice;
  more workers may consume `async` alone. What broke
  outside a run's steps is kept: `messenger:failed:show`, `messenger:failed:retry`.
- **The workflows' secrets** are encrypted with `WORKFLOW_SECRETS_KEY` (`workflow:secrets:key`),
  one key for the installation, kept as a real secret (the vault, the environment), never in
  `.env`: losing it loses every workspace's secrets; changing it, too.
- **Several web servers** must share the cache of roles and permissions (the `rbac.cache` pool,
  `config/packages/cache.yaml`), e.g. on Redis: a role changed on one server deletes its workspace's
  key there only, and a pool of each server's own would keep the old roles on the others.

## Upgrading an installation from before workspaces

1. Back up the database and the file storage.
2. Run the migrations: the installation becomes the `default` workspace, its users members of it,
   its records where they were. Everyone signed in is signed out once: their sessions predate
   workspaces.
3. Run `symfony console workspace:files:move` once: it moves the stored files into the default
   workspace's directory. Until then they stay readable where they are.
4. Set `WORKSPACE_BASE_HOST`, then create the other workspaces.

## Not yet

- A second factor for signing in, system admins included: planned for every user at once.
- Signing up, and inviting a user to a workspace.
- One sign-in for all the workspaces a user belongs to: you sign in to each host.
- Moving a workspace to a database of its own.
- Billing: a plan limits, nothing charges for it; limits other than the workflow steps.

How it works inside (the schemas, the session's workspace, row-level security) is in
[Architecture](architecture.md#workspaces).
