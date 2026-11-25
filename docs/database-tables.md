# Tables loaded into the database

A table can reach the database without the application: a dump restored, a script run with
`psql`, an ETL. The application does not own it, but it sees it: the admin lists it, greyed, under
the objects (**Database tables**), tells what it holds and whether it can become an object, and
makes it one: it **adopts** it, records and keys kept, alone or with the tables it is linked to by
foreign keys; a table linking two others becomes a many-to-many field. See the plan in
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
| Its primary key has several columns | A table linking two others (its key two columns, each a foreign key) is no reason: see [Tables linking two others](#tables-linking-two-others). Any other key of several columns is not supported: give the table a key of one column first |
| Its primary key is of another type (a text, a date) | Not yet: only a UUID or an integer can be the key of an object |
| A trigger, a rule or a view uses it | Adopting it renames the table and some columns, which would break them: drop them first |

Nothing here changes a table: the page and the command only read the catalog.

## Adopting a table

**Make it an object…**, on the page of a table that can become one, opens
`/admin/tables/<table>/adopt` (or `metadata:table:adopt`, below): the object's machine name and
labels, and for each column the field it becomes (its type, name and label), or **(leave it
out)**. The key is the primary key's, shown, not chosen.

**Try it** does everything, then undoes it: the page lists the SQL it ran, or why it was refused,
and nothing has changed. **Adopt** does it for real, in one transaction: all or nothing. Make a
backup first if the table cannot be loaded again: there is no way back to its shape but
[archiving and purging](lifecycle.md) the object.

What adopting does to the table, in this order:

1. The foreign keys and unique constraints the fields will own are dropped: the application makes
   them again its own way (a unique index, a foreign key named `fk_<table>_<column>`). A unique
   constraint on several columns becomes a set of fields
   [unique together](field-types.md#rules-common-to-every-type) (records in the bin no longer
   count).
2. The columns are renamed as their fields; a column left out that is named like a field or a
   system column becomes `<name>_legacy`.
3. Each field's column gets the type the field wants (see below), and loses `NOT NULL` and its
   default: "required" is the field's rule now, checked when a record is saved.
4. The key becomes `id`: a `uuid` loses its default (the application makes the UUIDs); an integer
   becomes a `bigint` identity column, numbering on from the largest key (a `serial`'s sequence
   is dropped).
5. The system columns are added: `created_at` and `updated_at` set to now for the records there,
   `created_by`, `deleted_at`, `sort` (0). A column of that name and of a fitting type is reused
   (an existing `created_at` keeps its dates); of another type, it becomes a field named
   `<name>_legacy`.
6. The table becomes `c_<object>`, its primary key `c_<object>_pkey`.
7. The object, its fields and its unique sets are made, as from the admin (permissions included),
   then the application's indexes (search, unique, foreign keys).

## Tables linked by foreign keys

Tables loaded together often point at each other: `project_task.project_id` at `project.id`.
Adopted together, such a foreign key becomes a **relation** field to the object the other table
becomes, its `ON DELETE` the field's rule (`CASCADE` → `cascade`, `SET NULL` → `set_null`,
`RESTRICT` / `NO ACTION` → `restrict`); the links between the records stay, since every table
keeps its keys. A foreign key to the key of an object there already becomes a relation too.

The adoption page of a table lists, under **Adopted with it**, the tables it is linked to:

- the ones it points at, and those they point at, are ticked: left out, its foreign keys to them
  stay plain numbers;
- the ones pointing at it are not: tick them to adopt them in the same go.

**Update the tables** reloads the page with the tables ticked, one section each, the tables pointed
at first. **Try it** and **Adopt** take them all, in one transaction: all of them become objects,
or none does. Tables pointing at each other in a circle (a person and their partner), and a table
pointing at itself (a task and its parent), are adopted as well: every object is made before any
of their fields.

A foreign key to a table not adopted with it stays as it is, on a plain `number` field: the
synchronization never drops a foreign key the metadata does not define (the object's page lists
it, "not defined in the metadata"). Adopting that table later makes it no relation by itself:
the field stays a number.

## Tables linking two others

A table whose primary key is two columns, each a foreign key of its own (`task_tag (task_id,
tag_id)`), links the records of two tables: it becomes a **many-to-many** field on the object the
first column points at (`task.tags`), to the object the second one points at. Both must be
adopted with it, or be objects already: the page brings them along, and refuses the table alone
("Table task_tag links task, tag: adopt it with them."). Its section on the page asks what it
becomes:

- **The links of a many-to-many field** (when it has no other columns): the table becomes the
  field's link table, `l_<object>__<field>`, its columns `record_id` and `target_id`, `created_at`
  added. No object. The field's rule on delete is `unlink` when the target's foreign key cascaded,
  `restrict` otherwise.
- **A junction object, the field going through it** (the only choice when it has columns of its
  own, a `role`): the table becomes an object too, with a key of its own added (an integer,
  numbering the rows there are), its two columns relations unique together, its other columns
  fields; the many-to-many field goes through it (see [the `many_to_many`
  type](field-types.md#many_to_many)), so the form of a task edits its rows.

Either way the links stay: the task shows its tags as before.

The most common shape has a key of its own instead: `assignment (id, task_id, person_id, hours)`,
the pair made unique by `UNIQUE (task_id, person_id)`. It is a junction object already: adopted
with the two tables (or once they are objects), it becomes an object keeping its key, its two
columns relations unique together, and the page proposes the many-to-many field going through it
(`task.persons`); **An object, with no many-to-many field** leaves it a plain object with its two
relations. Without the unique constraint nothing is proposed: the same two records could be
linked twice, which a many-to-many field does not allow (add the constraint first, if they never
are).

## After adopting

The records and their keys stay: `/o/<object>/<key>/edit` opens them. A column left out stays in
the table, unknown to the application; the object's page says so ("not defined in the metadata:
not dropped"). It must allow empty values or have a default, or no record could be written:
adopting refuses it otherwise.

The field proposed for each column, and the others it may become:

| Column | Field proposed | Also |
|---|---|---|
| `varchar(n)` up to 255, `char(n)` | `text` of `n` characters | `email`, `url`, `tel` |
| `text`, longer `varchar` | `text`, long (multiline, 10000 characters at most) | `email`, `url`, `tel` |
| named like `email`, `url` / `website`, `phone` / `tel` | that type | `text` |
| `smallint`, `integer`, `bigint` | `number` without decimals (an `integer` column) | |
| `numeric(p,s)` | `number` with `s` decimals (at most 10) | |
| `real`, `double precision`, `numeric` | `number` with 2 decimals (rounded) | |
| `boolean` | `boolean` | |
| `date` | `date` | |
| `timestamp`, `timestamptz` | `date` with its time | |
| a foreign key alone to the key of an object | `relation` to that object, its `ON DELETE` as the field's rule | `number` |
| `json`, `jsonb` whose values are all lists of strings (or empty) | `tags`, labels of 100 characters at most; an empty list becomes no label | `text`, long |
| any other `json`, `jsonb` | `text`, long: the document as its text | `tags`, refused while a value is no list of strings |
| anything else (arrays, `uuid` not pointing at an object, enums…) | left out | |

A value the field's column cannot hold stops the adoption, and says which (`The database refused
it: integer out of range` for a `bigint` too large for a `number`): leave the column out, or
change it first. A value the field would not accept (an `email` column holding a text that is not
an address) stays as it is, and is refused the next time its record is saved.

Changing a column's type rewrites the table: on a large table this takes a while, and the table
is locked meanwhile.

## The console

```bash
symfony console metadata:table:list                    # every unmanaged table, one line each
symfony console metadata:table:list --table=project    # one table in detail
symfony console metadata:table:adopt project --dry-run # what adopting it would do
symfony console metadata:table:adopt project --name=plan --plural=Plans
symfony console metadata:table:adopt project project_task    # together: the foreign key a relation
```

The console adopts the tables named, with the fields proposed. See
[`metadata:table:list`](console.md#metadatatablelist) and
[`metadata:table:adopt`](console.md#metadatatableadopt).
