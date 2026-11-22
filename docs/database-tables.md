# Tables loaded into the database

A table can reach the database without the application: a dump restored, a script run with
`psql`, an ETL. The application does not own it, but it sees it: the admin lists it, greyed, under
the objects (**Database tables**), and tells what it holds and whether it can become an object.
Making it one (adopting it) comes next; see the plan in
[internal/database-tables-plan.md](internal/database-tables-plan.md).

## Where to load them

Into the workspace's **data schema**, the PostgreSQL schema holding its record tables: `data` for
the first workspace, the one named by `core.workspace.data_schema` for the others (see
[Workspaces](workspaces.md)). No other schema is read: a table loaded into `public`, or into
another workspace's schema, is never listed.

```bash
pg_restore --schema=data --dbname=app dump.backup          # a custom-format dump
psql app -c 'SET search_path TO data' -f tables.sql        # a script creating plain tables
```

A script that names its schema (`CREATE TABLE public.project ...`) puts the table there whatever
`search_path` says: edit it, or move the table afterwards (`ALTER TABLE public.project SET SCHEMA
data`).

## What is listed

Every table, view or foreign table of the data schema that no object owns: neither the table of
an object (`c_<object>`, archived objects included) nor the link table of one of their fields
(`l_<object>__<field>`). The list is read from the database each time the page is shown: a table
loaded a moment ago is there, with no cache to clear and nothing to restart.

`/admin/tables/<table>` (and `metadata:table:list --table=<table>`) shows, for one table:

- its kind (table, view, partitioned or foreign table) and an estimate of its rows (PostgreSQL's
  statistics: `?` until the table is analysed, by `ANALYZE` or the autovacuum);
- its columns, with their types as PostgreSQL writes them, whether they may be empty, their
  default (`Numbered by the database` for an identity column);
- its primary key, its unique constraints, its foreign keys and their `ON DELETE`;
- the **object name** it would take: the table's name made a machine name (`ProjectTask` and
  `project-task` become `project_task`, the prefix `c_` is left out, `2024_sales` becomes
  `t_2024_sales`), unless an object (archived ones too) has it already: then a name is to choose;
- the **key** the object would get, from its primary key: `uuid` for a `uuid` column, `integer`
  for a `smallint`, `integer` or `bigint` one (see [Field types](field-types.md#keys));
- whether it can become an object, and why not.

## Why a table cannot become an object

| Reason | What to do |
|---|---|
| It is a view (a materialized view, a partitioned or foreign table): only a table can become an object | Copy it into a table, if that is what you want |
| It has no primary key | Add one: `ALTER TABLE data.t ADD PRIMARY KEY (id)`, or a new `id` column first |
| Its primary key has several columns | Not yet: a table linking two others (a junction) will become a many-to-many field later |
| Its primary key is of another type (a text, a date) | Not yet: only a UUID or an integer can be the key of an object |
| A trigger, a rule or a view uses it | Adopting it renames the table and some columns, which would break them: drop them first |

Nothing here changes a table: the page and the command only read the catalog.

## The console

```bash
symfony console metadata:table:list                    # every unmanaged table, one line each
symfony console metadata:table:list --table=project    # one table in detail
```

See [`metadata:table:list`](console.md#metadatatablelist).
