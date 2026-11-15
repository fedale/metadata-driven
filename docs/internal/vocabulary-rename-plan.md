# Plan: renaming the vocabulary

Status: **agreed** (2026-11-15). Phase 1 merged into `main` (2026-11-15); phase 2 done on branch
`system_rename` (2026-11-15), waiting for the user's review.
The names are recorded in [../glossary.md](../glossary.md); this plan brings the code, the
database, the commands, the admin UI, the guides and the package format in line with it.

## Decisions (user)

1. **2026-11-14**: a glossary, one word per concept, and a rename of the words that mislead:
   "template" makes one think of a graphic template, while it is an installable extension.
2. **2026-11-15**: after comparing the application with Drupal, Directus and Twenty CRM (the
   closest: objects with real tables, metadata in tables, a schema per workspace, global users
   with memberships, views, roles per object, workflows), **object, field, record and view
   stay**, as in Twenty and Salesforce. The renames of 2026-11-14 to entity, form layout and grid
   layout are dropped.
3. **2026-11-15**, the open questions: old commands and URLs are removed at once, with no
   aliases; the repositories are renamed (phase 6); **tenant becomes workspace**, as in Twenty
   (phase 3), and `no_tenant_rbac` becomes `no_workspace_rbac` with it; the order of the phases
   is the one below.

| Old | New |
|---|---|
| composite form | form |
| template, template item, template source | package, package item, package source |
| landlord, operator | system, system admin |
| tenant, tenant membership | workspace, workspace membership |
| row (as a concept) | record; "row" only for a physical table row or a grid line |

## Principles

1. **One term per phase**, each on its own branch, reviewed and merged before the next (the
   working rule of status.md). Each phase renames the term everywhere at once: classes,
   database, routes, commands, translation keys, Twig, tests, Deptrac, guides, and removes its
   row from the glossary's "Not yet". A phase never leaves both names in the code.
2. **The database follows through migrations** (`ALTER TABLE ... RENAME`, `RENAME COLUMN`).
   `core` is shared by every workspace, so one migration covers them all; the data schemas are
   not touched.
3. **Not renamed**: Symfony's `templates/` directory and Twig's "template" (that is what the
   word means there); "multi-tenancy", the name of the technique (a workspace is the tenant of
   the installation); the API of `fedale/rbac-bundle`, a generic library that keeps its own
   word "tenant": the app translates at the boundary (`RbacTenantContext`).
4. **No aliases**: an old command, route or URL is removed in the phase that renames it.
5. "Row" needs no phase of its own: the guides use "record" for the concept, and each phase
   fixes the occurrences it meets.

## Phases

### Phase 1: composite form → form (done)

About 36 files in `src`, 10 in `tests`.

- Classes and services named `Composite*` → `Form*` (`FormDefinition` already has the right
  name); route names `composite_new`, `composite_edit` → `form_new`, `form_edit` (the URL
  `/f/<form>` stays).
- Translation keys `admin.composite.*` → `admin.form.*`, in `translations/` (both languages).
- Guides: forms.md (title "Forms"), admin.md, console.md, views.md, templates.md, i18n.md (its
  key table: "Section of a composite form").
- Done: also `CompositeRecord*` → `FormRecord*`, `CompositeNodeInput` → `FormNodeInput`,
  `CompositeValidationFailed` → `FormValidationFailed`, `CompositeFormFactory` →
  `FormDefinitionFormFactory`, `CompositeNodeType` → `FormNodeType`, the Twig function
  `composite_form_layout()` → `form_node_layout()`, the templates `record/composite.html.twig`,
  `record/_composite.html.twig` → `form/page.html.twig`, `form/_node.html.twig`, the HTML form
  name `composite[...]` → `form[...]`, the CSS classes `md-composite__*` → `md-form-section*`.

### Phase 2: landlord, operator → system, system admin (done)

About 46 files. **After `rbac_tenancy` is merged**: that branch touches the same code.

Done: also the session setting `app.landlord` → `app.system`, rewritten by the migration in every
row-level security policy that reads it (`Version20261115100000`, from `pg_policies`);
`ROLE_OPERATOR` → `ROLE_SYSTEM_ADMIN`; the request attribute `_operator_console` →
`_system_console`; `TenantSwitcher::landlord()` → `system()`; "the console of the installation"
→ "the system console" in the code and the guides.

- `Shared/Tenant`, `Shared/Infrastructure/Tenant`: "landlord" → "system" in classes, methods,
  comments (`OperatorConsole` → `SystemConsole`).
- `core.app_user.is_operator` → `is_system_admin` (migration).
- Command `tenant:operator [grant|revoke]` → `system:admin [grant|revoke]`.
- The console stays on `console.<base host>` and is called "the system console".
- Guides: multi-tenancy.md, access.md, console.md; the rbac-bundle's docs if they say
  "landlord".

### Phase 3: tenant → workspace

About 81 files in `src`, 18 in `tests`, 10 migrations to read (not to change), 4 Twig templates.
After phase 2: both touch `Shared/Tenant`.

- `Shared/Tenant` → `Shared/Workspace`; `Tenant`, `CurrentTenant`, `Tenants`, `TenantScope`,
  `TenantSwitcher`, `TenantFilter`, `TenantProvisioner`... → `Workspace*`; the Doctrine filter's
  name; the session state and the middleware.
- Tables `core.tenant`, `core.tenant_membership`, `core.tenant_invitation` → `core.workspace`,
  `core.workspace_membership`, `core.workspace_invitation`; the columns `tenant_id` →
  `workspace_id` in every `core` table, with their indexes, constraints and the function
  `core.set_tenant_id` → `core.set_workspace_id`; the session variable it reads.
- The RBAC schema of the sessions with no workspace, `no_tenant_rbac` → `no_workspace_rbac`,
  and the `search_path` that names it.
- Commands `tenant:create|delete|list|suspend|resume|run|files:move|member:*` →
  `workspace:*`; the option `--tenant` → `--workspace`.
- The console's pages `/console/tenants/...` → `/console/workspaces/...`; the hosts do not
  change (`acme.<base host>`).
- Translation keys and texts of the admin, the console and the sign-in pages.
- multi-tenancy.md → workspaces.md (title "Workspaces"), with the links to it; every guide says
  "workspace".

### Phase 4: package format v2

In the repository `metadata-driven-template-format`, renamed `metadata-driven-package-format` in
phase 6.

- `format: metadata-driven/v2`; the kind `Package` (was `Template`) and the manifest file
  `package.yaml` (was `template.yaml`); `Object`, `View`, `Form`, `Translations` stay.
- In a view, `source` (was `template`, the HTML of a form view or the YAML of a grid view): the
  word "template" leaves the format altogether.
- **v1 is still read**: a converter v1 → v2 in the format library, so the packages already
  published and the snapshots of the installations (`core.template_installation.snapshot`) keep
  working. The app writes only v2.
- A release of the library (v2 schemas next to v1), then the app requires it.

### Phase 5: template → package, in the app

About 102 files in `src`, 34 in `tests`.

- `Module/Template` → `Module/Package`; `Template*` classes → `Package*`; Deptrac layers.
- Tables `core.template_installation`, `core.template_item` (its column `template` → `package`),
  `core.template_source` → `core.package_*`.
- `core.metadata_view.template` → `source`, as `core.metadata_form.source`; `ViewDefinition`'s
  `$template` → `$source`.
- Commands `metadata:template:*` (11) → `metadata:package:*`; `tenant:template:repo` →
  `system:package:source` (it is the system's, not a tenant's); the option `--template` where
  present.
- `/admin/templates` → `/admin/packages`; translation keys `admin.template_admin.*` →
  `admin.package.*`.
- Env vars `TEMPLATE_CATALOG_URL`, `TEMPLATE_CATALOG_KEY` → `PACKAGE_CATALOG_URL`,
  `PACKAGE_CATALOG_KEY` (`.env`, the catalog's README, production notes).
- templates.md → packages.md, with the links to it.

### Phase 6: the catalog and the repositories

- The catalog (`metadata-driven-templates`): `templates/` → `packages/`, `fedale/geo-italy` and
  `fedale/registration` converted to v2, packed, signed and published again; its CI and README.
- The repositories are renamed: `metadata-driven-template-format` →
  `metadata-driven-package-format` (a new Packagist package `fedale/metadata-driven-package-format`,
  the old one marked abandoned in favour of it; the app requires the new one) and
  `metadata-driven-templates` → `metadata-driven-packages`.
- GitHub redirects a renamed repository, but **not GitHub Pages**: the catalog's index URL
  changes. A migration updates `core.package_installation.source` (the address each installation
  upgrades from) and `core.package_source.url` where they point at the old address; the
  default `PACKAGE_CATALOG_URL` and the production notes change; `composer link` paths and the
  setup notes of status.md follow.

## Risks

- Phase 3 touches the session, the security and every `core` table: test the sign-in, the
  invitations, the console and a command with `--workspace` by hand before merging.
- Phase 5 touches a module of its own plus the views: conflicts with branches open on templates
  at the same time.
- The v1 → v2 converter must be tested on the real snapshots of installed packages (a copy of a
  database with `fedale/geo-italy` and `fedale/registration` installed), not only on fixtures.
- Each phase greps `docs/` for the old term before it is reported done.
