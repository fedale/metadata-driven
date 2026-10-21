# metadata-driven

A metadata-driven application in the style of Twenty CRM or Salesforce: objects and fields are
defined at runtime, and every object gets a real PostgreSQL table, with typed columns, unique
indexes, foreign keys and search indexes. Records are listed in a grid (filters, search, sort,
export) and edited in forms, both generated from the metadata.

Objects are defined from the admin UI at `/admin` or from the console.

## Guides

| Guide | What is in it |
|---|---|
| [Getting started](getting-started.md) | Run the app, then build a small model from scratch: objects, fields, a relation, search |
| [Admin UI](admin.md) | The no-code builder at `/admin`: objects, fields, labels, search, table status |
| [Users and permissions](access.md) | Signing in, users, the permissions of every object, roles |
| [Console commands](console.md) | Every command, its arguments and options, what it changes in the database |
| [Field types](field-types.md) | The seven field types, their settings, columns, validation and grid behaviour |
| [Architecture](architecture.md) | Modules and layers, the boundaries Deptrac enforces, how a definition becomes a table |

## At a glance

```bash
make up && make db && make assets          # database, migrations, styles
symfony console access:sync                # permissions and the super admin role
symfony console user:create you@example.com && symfony console rbac:assign you@example.com ROLE_SUPER_ADMIN
symfony serve -d                           # http://127.0.0.1:8000, sign in

symfony console metadata:object:create book Book Books
symfony console metadata:field:create book title text Title --required --settings='{"maxLength":200}'
symfony console metadata:list book
```

Then open `/o/book`: the grid and the forms of the new object are already there. The same can be
done from `/admin`, without the console.
