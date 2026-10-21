# Getting started

## Requirements

- PHP 8.4+, with `pdo_pgsql` and `intl`
- Composer, the [Symfony CLI](https://symfony.com/download), Docker with Compose
- The sibling bundles in the parent directory, installed as Composer path repositories (symlinks):
  `../gridview-bundle` and `../setting-bundle`

## Run the app

```bash
composer install
make up        # PostgreSQL 17 in Docker
make db        # create the database, run the migrations
make assets    # compile the Sass stylesheets (pages do not render without them)
symfony serve -d
```

Create your account, as super admin (see [Users and permissions](access.md)):

```bash
symfony console access:sync
symfony console user:create you@example.com --first-name=Ada --last-name=Lovelace
symfony console rbac:assign you@example.com ROLE_SUPER_ADMIN
```

The app answers on `http://127.0.0.1:8000` and asks you to sign in. Run every command through `symfony console`, not
`bin/console`: the Symfony CLI passes the `DATABASE_URL` of the Docker container to PHP.

The home page lists the objects; each object has its grid at `/o/<object>`.

## Build a model in five minutes

The example models a small library: books, their authors, and loans. Every command below takes
effect at once: it saves the definition **and** changes the physical table in the same transaction.

### 1. Objects

```bash
symfony console metadata:object:create author Author Authors
symfony console metadata:object:create book Book Books
```

Each object gets a table in the `data` schema (`data.c_author`, `data.c_book`) with the system
columns `id`, `created_at`, `updated_at`, `created_by`, `deleted_at`.

### 2. Fields

```bash
symfony console metadata:field:create author full_name text "Full name" --required
symfony console metadata:field:create author email email Email --unique
symfony console metadata:field:create author born_on date "Born on"

symfony console metadata:field:create book title text Title --required --settings='{"maxLength":200}'
symfony console metadata:field:create book isbn text ISBN --unique --settings='{"maxLength":17}'
symfony console metadata:field:create book pages number Pages
symfony console metadata:field:create book price number Price --settings='{"decimals":2}'
symfony console metadata:field:create book available boolean Available
symfony console metadata:field:create book genre select Genre \
  --settings='{"options":[{"value":"novel","label":"Novel"},{"value":"essay","label":"Essay"}]}'
```

The [field types guide](field-types.md) lists every type and its settings.

### 3. A relation

```bash
symfony console metadata:field:create book author relation Author \
  --settings='{"target":"author","onDelete":"restrict","inverseName":"books","inverseLabel":"Books"}'
```

A book now points at its author: the form offers an autocomplete over the authors, the grid shows
the author's name and filters by author, and an author's page lists their books. `restrict` refuses
to delete an author who still has books.

Records are named by their first text field (here `full_name`); choose another one with
`metadata:object:label`.

### 4. Search

By default the search box of a grid looks for every word in every text field, in any order.
Narrow it down when needed:

```bash
symfony console metadata:object:search book --fields=title,isbn
```

### 5. Check

```bash
symfony console metadata:list            # every object
symfony console metadata:list book       # the fields of one
symfony console schema:sync --dry-run    # should print "Up to date." for each object
```

Open `/o/author`, create an author, then `/o/book` and create a book pointing at them.

## Quality checks

```bash
make qa        # coding standard, PHPStan (level max), Deptrac, the test suite
make test      # tests only (starts the database and builds the styles if needed)
```

Integration and functional tests run against the real PostgreSQL test database; each test is
rolled back, DDL included.
