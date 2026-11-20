# Plan: CSV imports in the background

Status: **done** (2026-11-20), all four phases, merged into `main` on 2026-11-20.

Item (C) of "CSV import" in [status.md](status.md): the import wizard (item B,
[csv-import-wizard-plan.md](csv-import-wizard-plan.md)) takes files of any size, the large
ones checked and imported by the Messenger worker. The console (`record:import`, item A) does
not change.

## Decisions (user, 2026-11-20)

- **Batches, committed**: a background import writes its rows in batches, one transaction each
  (1,000 rows). The whole file is checked first; when a batch is still refused at import time
  (the records changed since the check), the import stops there: the batches before it stay
  written, and the job says how many records were created and updated, and from which line
  nothing was. With a key, importing the file again is an upsert and finishes the work.
- **When**: up to `app.import.max_rows` (5,000) the wizard works in the request, as today. Above
  it, the same wizard queues the check and the import. A new limit,
  `app.import.max_background_rows` (default 1,000,000), caps the background.
- **The check in the background too**: the dry run of a large file is a job of its own; the
  wizard offers **Import** only once it passed, as today.
- **Feedback**: a page per import, with its state and progress (refreshed while it runs), the
  list of the user's imports of the object, and a notification (the bell, and an email to whoever
  chose it) when a check or an import ends.

## The flow

Upload and columns are the wizard's, unchanged: the file is a draft in the session. At the
check step, a draft above `max_rows`:

1. becomes an **import job** (`core.record_import`): its file is moved out of the drafts, its
   settings (columns, key, matches) are copied, the session's draft is removed;
2. the check is queued (`CheckImport`), and the wizard redirects to the job's page,
   `/o/<object>/imports/<id>`;
3. the worker checks the file in batches, each rolled back, and records the progress, then the
   outcome: **checked** ("N records will be created, M updated") or **refused** (the reasons,
   at most 200 kept, and how many there were);
4. on a checked job, **Import** queues `RunImport`; the worker writes the batches, recording
   with each one, in its transaction, the rows done and the counts. Then **imported**, or
   **stopped** at a refused batch.

On the job's page, while it waits or runs: **Stop** (the worker stops before its next batch;
an import keeps the batches written). On a checked or refused job: **Change the columns** turns
it back into a draft (the file goes back to the drafts) and opens the columns step.

States: `checking`, `checked`, `refused`, `importing`, `imported`, `stopped`, `cancelled`,
`failed` (an error outside the rows, the worker's retries spent). The page refreshes itself
while the job is `checking` or `importing` (a meta refresh: no JavaScript needed, as the rest of
the wizard).

## Who

The job is its creator's: only they see its page and its place in the list (a reason may quote
the file's values). The worker acts as them (a security token for the job's duration, so the
permissions, `$user` of the workflows and the records' authors are theirs), in the job's
workspace, and checks again before running: create on the object, update with a key, read on
the targets of the relations mapped. A permission lost meanwhile fails the job.

## The importer

`RecordImporter::importRows()` keeps its behaviour (the wizard's small files, and through
`import()` the console). Inside, it is split so the background can run it batch by batch:

- `prepare($object, $header, $key, $matches, $filesDirectory)`: the checks on the header, the
  key and the matches, once; an `ImportPlan`.
- `keyProblems($plan, $rows)`: the missing and repeated keys of the whole file (a first pass;
  only the keys are kept in memory).
- `importBatch($plan, $rows, $dryRun, $beforeCommit)`: one transaction; rolled back on a dry run
  or on a refused row; `$beforeCommit` runs inside it, so the job's progress commits with its
  rows (a worker retried after a crash goes on after the last batch written, never twice).

`CsvFile` gains `header()` and `rows()` (a generator): the background, and the wizard's
columns step, never hold the whole file in memory. `read()` stays, on top of `rows()`.

A per-batch check sees the rows of its own batch only: a unique value repeated in two batches
(not the key, which the first pass checks) passes the check and stops the import. Said in the
guide.

## Files

The job's file lives in `<IMPORT_DRAFTS_DIR>/jobs/<id>.csv`. It is deleted when the job ends
(imported, stopped, cancelled, failed). A checked or refused job keeps it, for its import or its
columns; job files older than 7 days are deleted when a new job is created (the job then says
"upload the file again"). The worker must see the same directory as the web server (one host, or
a shared disk): said in the guide.

## Phases

1. `CsvFile::header()`, `rows()`; `RecordImporter` split, `importRows()` unchanged. Tests.
2. The job: migration, `ImportJob`, its DBAL store, `BackgroundImports` (queue, check, import,
   stop, back to a draft), the messages and handlers, `ActingUserInterface` (UserContract,
   implemented by User), the failure listener, the notifications. Integration tests.
3. The wizard: the check step above `max_rows`, the job's page, the list, Stop, Change the
   columns; translations (en, it). Functional tests.
4. Docs: import.md, architecture.md, console.md (no change to the command, a pointer),
   status.md; this plan as delivered.

## Not in scope

- Uploads above PHP's `upload_max_filesize` / `post_max_size` (no chunked upload): the guide says
  which settings to raise.
- The console in the background: `record:import` stays all or nothing, in one transaction.
- File fields in a web import (as before).

## As delivered

- `ImportPlan` (what `prepare()` returns) carries the header's and the matches' problems rather
  than throwing them, so the wizard's small files still get them listed together with the
  repeated keys, as before.
- The jobs' table is `core.record_import`; a job's id is a UUID v4, as a draft's.
- `base.html.twig` has a `meta` block, for the job's page to refresh itself.
- The upload step links to **Your imports**; the list shows the user's last 20 of the object.
- Not notified: a job stopped by its creator (they pressed Stop).
- Tests: `CsvFileTest` (header, rows streamed), `RecordImporterTest` (batches, keys across the
  file), `BackgroundImportsTest` (a retried import goes on after its batches, Stop, a permission
  lost, a message failed for good, the sample of reasons), `RecordImportJobControllerTest` (check
  then import, refused and back to the columns, stopped at a batch, the creator's only, the
  background's limit).
