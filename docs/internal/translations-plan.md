# Plan: the texts still in English

Status: **accepted** (2026-11-26), phase 0 done (gridview 1.11.2). Picks up i18n.md, "Not yet": the error messages still in
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

**Phase 2: workflows.** The definition's errors (parser, validator, the visual editor's), the
uses an archive is refused for, the definitions a rename rewrote.

**Phase 3: the package format.** `Problem` gets a key and parameters next to its English text
(format 2.7.0, a minor: the text stays); the app translates them by key in `errors.it.yaml`, as
it does the field types' settings. The catalog's check keeps the English.

## Decisions

1. Old reasons stored in `core.record_import` stay in English (no migration rewrites them).
   Recommended: they are the reasons of imports already finished.
2. The translations of the format's problems live in the app, not in the library: the library
   stays free of Symfony's translator, and its other user, the catalog, writes English.
3. One phase per branch, the user's review between phases, phase 0 released before phase 1.
