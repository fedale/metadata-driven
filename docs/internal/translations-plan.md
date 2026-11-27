# Plan: the texts still in English

Status: **accepted** (2026-11-26); phase 0 done (gridview 1.11.2), phase 1 done (branch
`translate_import`). Picks up i18n.md, "Not yet": the error messages still in
English in every language. The rest of that list (the options of a service field of type
choice, exporting and importing the translations) is out of scope.

## What is left, and how big

| Texts | Where | Size |
|---|---|---|
| The grid's fixed labels ("Columns", the sort link, a group's empty text; "Export" in Italian) | gridview-bundle templates | 4 texts |
| The reasons of a refused CSV import | `Record/Application/Import`, `RecordImportController`, `core.record_import.reasons` | ~16 texts, plus the 14 `InvalidFieldValue` of the field types they carry |
| The errors of a workflow definition, its uses of an object or a field (archive), the definitions a rename rewrote | `Workflow/Application/Definition` and the lifecycle | ~120 texts |
| The problems the package format finds | `fedale/metadata-driven-package-format` (`Problem`) | ~70 texts, in the library |

Every one is raised as a `Message` (key, English text, parameters) and translated in
`translations/errors.it.yaml`, as the metadata's errors are since 2026-11-20 (status.md, "Error
messages in Italian"); `ErrorCatalogsTest` checks the keys and placeholders.

## Phases

**Phase 0: gridview's labels.** Translation keys in the templates, `Esporta` in Italian, a test
on the catalogs (same keys in every locale, every key a template uses exists). Released as
gridview 1.11.2, required here.

**Phase 1: the CSV import.** `ImportPlan::$problems`, `ImportBatch::$reasons` and
`ImportRefused::$reasons` become lists of `Message`; a row's reason is `Line %line%: %reason%`
around the field's own message. `InvalidFieldValue` carries a `Message` (the field types' 14
texts, shown by the record form and the API too). A violation of a validator constraint is
already in the language of the request; a background import runs in the job's `locale` (stored
since 2026-11-20). `core.record_import.reasons` stores each reason as `{key, text, parameters}`;
a reason stored before, a string, is shown as it is.

Done, with more than planned: the record's own errors went the same way, since they reach the
import's reasons and the record form through the same `RecordValidationFailed`. They were English
in every language: the required value, the unique value, the missing reference, the cascade's
parent, the junction rows, a form's row counts, a state changed by its transitions only. They
are messages now (`record.value.*`, `record.state.*`), except the required value of the
validator's `NotNull`, which the validator translates (`record.required` in the validators
catalog). The CSV reader's errors (`csv.*`, `CsvFileUnreadable`) too. `MessageJson` stores a
message as JSON and reads it back; `Message::restored()` builds one whose key is no literal.

**Phase 2: workflows.** The definition's errors (parser, validator, the visual editor's), the
uses an archive is refused for, the definitions a rename rewrote.

Done on 2026-11-27 (branch `translate_workflows`): the parser's, the validator's and the
manager's errors are messages (`workflow.definition.*`, `workflow.name_*`);
`InvalidWorkflowDefinition` and `InvalidWorkflowSource` locate them (`located()`: "path:
message"), for the editor, the YAML page and a package's refusal, which no longer quotes them in
English. The uses an archive is refused for and the definitions a rename rewrote are messages
too (`workflow.uses`, `workflow.rewritten`, with the version's status; the metadata module's own
`metadata.rewritten.*`). Left in English: what a run reports (`StepFailed`, `ManualRunRefused`,
the run limits), stored in the run's log as text; a phase of its own if wanted.

**Phase 3: the package format.** `Problem` gets a key and parameters next to its English text
(format 2.7.0, a minor: the text stays); the app translates them by key in `errors.it.yaml`, as
it does the field types' settings. The catalog's check keeps the English.

Done on 2026-11-27: the format has `Text` (key, English, parameters, as `Message` here) and
`Problem::$text`; `FieldTypeCatalog::problems()` gives a field's setting problems as texts,
`check()` stays. Here `ProblemMessages` turns a problem into a message under
`package_format.<key>`, for the install and upgrade pages, an uploaded or downloaded archive;
`ErrorCatalogsTest` reads the format's `new Text(...)` too. The schema violations (opis writes
them) and an unreadable version constraint keep their English; the console and the publisher
stay English, as everywhere.

## Decisions

1. Old reasons stored in `core.record_import` stay in English (no migration rewrites them).
   Recommended: they are the reasons of imports already finished.
2. The translations of the format's problems live in the app, not in the library: the library
   stays free of Symfony's translator, and its other user, the catalog, writes English.
3. One phase per branch, the user's review between phases, phase 0 released before phase 1.

## Phase 4: what a workflow reports as it runs

Asked for on 2026-11-27, after phase 3. `StepFailed` carries a message (`text()`); a run's error
and its steps' are messages (`workflow.run.*`), stored as jsonb (migration
`Version20261127100000`: an error written before becomes a JSON string, read as it was) and
read in the language of whoever opens the run, the runs list or the editor's test. A write a
"before" workflow refuses reaches the record form as a message; so does a delete a "restrict"
relation refuses (`RecordDeleteRestricted::text()`). A manual run's refusals and its form's
answers (`ManualRunRefused::text()`, `InvalidAnswer`) are messages too (`workflow.manual.*`,
`workflow.answer.*`). Left as written: a refuse step's own message, the outside's errors (an
HTTP client's, a database's) inside a message, the webhook's JSON answers, the console.

## The grid column renderers' options

Asked for on 2026-11-27. `CellRendererInterface::optionErrors()` (and the catalog's) give
`string|Message`: the three renderers' errors are messages (`metadata.grid_view.*`), shown by
the grid view's editor in the user's language. A renderer of a host application may go on
returning strings, shown as they are.
