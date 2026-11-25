# Plan: the `time`, `color`, `country`, `language` and `currency` field types

Status: **decided**, not started. Written on 2026-11-25; the user took the four recommended
options the same day.

Five field types for values `fedale/gridview-bundle` already knows how to edit and show: each one
has a form control (`ControlType::Time`, `Color`, `Country`, `Language`, `Currency`) and a grid
column (`TimeColumn`, `ColorColumn`, `CountryColumn`, `LanguageColumn`, `CurrencyColumn`). So,
unlike `tags`, almost nothing is built in gridview first: the work is in metadata-driven, its
package format, and one filter in gridview (decision 1).

Two families:

- **`time`** and **`color`**: a value with a format of its own (`14:30`, `#1f6feb`).
- **`country`**, **`language`** and **`currency`**: an ISO code (`IT`, `it`, `EUR`) from a list
  `symfony/intl` knows, shown by its name in the user's language (`Italia`, `italiano`,
  `euro (EUR)`).

## Why now, and why these five

They're the candidates left by the controls plan
([controls-alignment-plan.md](controls-alignment-plan.md), "Not in scope") that need no new
design: the value is plain text or a time, the column is plain, and gridview has both sides.
`money` (where the currency lives) and `html` (how it's sanitized) need decisions of their own and
stay out. `locale` and `timezone` would fit the second family and can follow the same pattern
later.

## Decisions

All four decided by the user on 2026-11-25, as recommended.

1. **The `time` filter.** Gridview's date filter (`FilterDateType`, `DateCriteriaBuilder`) only
   takes dates: there's no time-of-day range. Options:
   - **(Recommended)** A `time` mode in gridview's date filter (`<input type="time">`, the criteria
     built from `H:i`), released before phase 1. The grid then filters "from 09:00 to 12:30".
   - No filter on a `time` column in the first version.
2. **What the grid and the exports show for a code.** Gridview's exporters write the cell as
   rendered (`CellText`), so a country column exports `🇮🇹 Italia`, not `IT`, and a CSV export no
   longer reads back through the import. Options:
   - **(Recommended)** The grid shows the name (a country with its flag); the CSV import and the
     seeds accept the code **or** the name in the import's language, ignoring case. Export and
     import stay a round trip, and a spreadsheet stays readable.
   - The grid and the exports show the code only.
3. **Restricting the list.** A country field for "the countries we ship to":
   - **(Recommended)** Only `preferred` (codes listed first in the form) and `default`. A closed
     subset is a `select` field.
   - Also an `only` setting, a subset of the codes; the same refusal of the values outside it as a
     select's.
4. **The format of a colour.** The browser's colour input only knows `#rrggbb`:
   - **(Recommended)** `#rrggbb`, stored in lower case; `#rgb` is accepted and expanded. No
     transparency, no colour names.
   - Any CSS colour (`rgb()`, names, alpha): the browser's picker can't edit them.

## The types

All five store `null` for an empty input, may be `required` and `unique`, and change none of
their settings in a way that touches the column (no setting is `fixed` but `seconds`).

### `time`

| Setting | Kind | Default | Description |
|---|---|---|---|
| `seconds` | boolean | `false` | The input asks for seconds too. Fixed: it shapes the column |

- Column: `time(0)` (DBAL `time_immutable`). Without `seconds` the seconds are zero. (Since
  the column is the same either way, `seconds` could be left free to change: decide in phase 1.)
- `denormalize()`: `14:30`, `14:30:15`, or a `DateTimeInterface` (its time). Anything else is
  refused: "This value is not a valid time.".
- In the record arrays: a `DateTimeImmutable` dated 1970-01-01, like the time a date-time holds.
  The API and the workflows write it `H:i` (`H:i:s` with `seconds`).
- Widget `WidgetKind::Time`, gridview's `time` control with `widget: single_text` and
  `input: datetime_immutable`, `with_seconds` from the setting.
- Grid: `TimeColumn`, sortable, the filter of decision 1. `RecordGridFactory::text()` must format
  it as a time (it formats any `DateTimeInterface` as a date today).

### `color`

No settings.

- Column: `varchar(7)`.
- `denormalize()` and constraint: decision 4; "This value is not a valid colour (#rrggbb)."
- Widget `WidgetKind::Color`, gridview's `color` control (`<input type="color">`). An empty value
  is a problem for that input, which always holds a colour: the form needs a "no colour" checkbox
  or the field can't be emptied once set. **Check this in phase 1** (gridview may need a
  `nullable` option on the control, which would make it a gridview change first).
- Grid: `ColorColumn` (a swatch and the code), text filter, sortable on the code.

### `country`, `language`, `currency`

| Setting | Kind | Default | Description |
|---|---|---|---|
| `preferred` | string list | `[]` | Codes listed first in the form, in this order |
| `default` | string | null | The code new records start with |

| Type | Code | Column | Constraint | Names from |
|---|---|---|---|---|
| `country` | ISO 3166-1 alpha-2, upper case (`IT`) | `varchar(2)` | `Assert\Country` | `Countries` |
| `language` | ISO 639 code as `symfony/intl` lists them, lower case (`it`, `fur`) | `varchar(3)` | `Assert\Language` | `Languages` |
| `currency` | ISO 4217, upper case (`EUR`) | `varchar(3)` | `Assert\Currency` | `Currencies` |

- `validateSettings()`: every code of `preferred` and the `default` is a known code, none twice.
- `denormalize()`: trims and applies the code's case. A name isn't accepted here: it's a code
  type, and decision 2 puts names in the import only.
- Widget `WidgetKind::Country` / `Language` / `Currency`, gridview's controls
  (`CountryType`…, `preferred_choices` from `preferred`, a placeholder when not required). The
  names follow the locale of the request.
- Grid: `CountryColumn` / `LanguageColumn` / `CurrencyColumn`, the name in the user's language.
  Their filter is a **choice filter** (a multiple relation filter with static choices, as the
  tags filter: the codes, labelled by name), not the text filter gridview infers: "Ital" must
  find Italy, not the code. Sorting is on the code, so it doesn't follow the names; that's
  documented, not fixed.
- Search: searchable (`isSearchable()`) but not searched by default: the search matches the code,
  not the name.
- Conditions: not a deciding field in the first version (`conditionValues()` stays as is).

`symfony/intl` becomes a direct requirement of metadata-driven (today it's only installed through
another package).

## Everything else a type touches

As for `tags`, each type is listed in:

- `WidgetKind` (the five cases exist in gridview's `ControlType`, so `WidgetKindTest` passes),
  `RecordFormFactory` (`control()` and `gridviewControl()`), `RecordGridFactory::column()`.
- The admin: `TYPE_ORDER`, the settings editor (`StringList` already exists for `preferred`),
  `field_types.it.yaml`, `errors.it.yaml`.
- The package format (`fedale/metadata-driven-package-format`): the five types and their
  settings in `field-types.v1.json`, "Since 2.6" in the README; release 2.6.0, the
  `composer.json` constraint here raised to it. `FieldTypeCatalogMatchesTheAppTest` forces this
  in the same phase as each type.
- The CSV import and the seeds: a time as `14:30`, a colour as `#1f6feb`, a code (decision 2:
  or a name). The seed export writes codes.
- Table adoption (`ColumnProposer`): a `time` column proposes `time`; a `char(2)` / `varchar(2)`
  named `country…` proposes `country`, a `char(3)` named `currency…` `currency`, a column named
  `lang` / `language…` `language`, a `varchar(7)` named `colo(u)r` `color`; text stays the other
  option.
- Docs: `field-types.md` (the summary table and a section each), `views.md`, `import.md`,
  `packages.md`, `database-tables.md`.

## Phases (one branch each, docs in the same branch)

0. **Gridview: a time mode in the date filter** (only with decision 1, first option). Its tests,
   `docs/` and `agents.md`; release 1.10.0.
1. **`time` and `color`.** The types, form, grid, admin, translations, docs; the package format
   entries (2.6.0 is released at the end of phase 2, with all five). The colour's empty value
   (see above) is settled here.
2. **`country`, `language`, `currency`.** `symfony/intl` required; the types, form, grid with the
   choice filter, admin, translations, docs; package format 2.6.0 released and required.
3. **Import, seeds and adoption.** Codes and (decision 2) names in the CSV import and the seeds,
   the seed export, `ColumnProposer`.

### Tests

- Unit: each type's settings and `denormalize()` (a time with and without seconds, `#ABC` →
  `#aabbcc`, `it` → `IT`, an unknown code, a duplicated preferred code).
- Integration, against PostgreSQL: each column's type; the filters (a time range, a country among
  several); sorting a time.
- Functional: create and edit a record with each type through the form; the grid's cells (a
  country's name in Italian with `/it`), its filter and its export; the colour emptied.
- Package: install and export a package with the five types and a seed.

## Not in scope

- `locale`, `timezone`, `money`, `html`, `percent`, `range`: their own decisions.
- A colour with transparency, a palette of suggested colours.
- Conditions decided by these fields, and sorting codes by their names.
