# Implementation Queue

> Deferred work, captured mid-session, picked up deliberately. Managed by `/queue`;
> convention: `$CLAUDE_HARNESS_DIR/notes/implementation-queue.md`. Hand-editing is fine.

## Queued

### Tenant isolation, if a host ever needs it

- **Added**: 2026-08-08 · test-suite repair · found by two reviewers independently
- **Tier**: FULL if taken on — a data-isolation change touching every module
- **Status**: **deliberately deferred by Ryan, 2026-08-08.** Not a defect to be fixed on
  sight — read the "Tenancy" section of `CLAUDE.md` before touching this.

The package records tenant ownership and does not enforce isolation: `BelongsToTenant`
stamps `tenant_id` on create and adds no read filter, so every report returns every
tenant's rows and `ReservationService::getForJob()` matches on job class + id alone.

Deferred because there is no host application needing it, and because doing it later costs
about the same: the expensive irreversible half — the column on ten tables, and every write
filling it — is already done. What remains is additive.

If it is ever taken on, the shape:

- **Eloquent half, small**: a global scope in `BelongsToTenant`, plus a decision on what
  happens when no tenant resolves. ~20 lines.
- **Reporting half, the actual job**: all six reports build raw query-builder SQL against
  physical tables with joins and subqueries. A global scope cannot reach any of it; every
  table reference needs its own predicate, and a missed one leaks silently.
- **Tests, the largest chunk**: today every test runs as one fixed tenant, so the suite
  *cannot* detect a leak. Needs a second tenant in the fixtures plus isolation tests per
  model and per report — roughly 25–30 tests.
- Also unfinished: `JobContract::getInventoryTenantId()` and `shouldHoldReservations()` are
  declared and never called anywhere in `src/`.

Estimate: 2–3 focused days, most of it in the reporting code that the "Reporting reads five
other modules' tables directly" item below wants replaced anyway. Sequence them.

### Reservation writes are not safe under concurrency

- **Added**: 2026-08-08 · test-suite repair review
- **Tier**: LIGHT
- **Context**: `reserve()` reads availability then increments with no `lockForUpdate`, and
  `fulfill()`/`partialFulfill()` re-read the stored row inside their transaction without locking it.
  Two concurrent reserves against the same stock both pass the check and over-commit the hold; two
  concurrent fulfilments race. Real on PostgreSQL and MySQL — which is the deployment target. The
  suite now runs on PostgreSQL, so a concurrency test is finally *possible* here, but every test
  runs inside one `RefreshDatabase` transaction, so a second connection would need to be opened by
  hand. Direction: lock the stock row, or push the check into the update itself
  (`UPDATE … WHERE quantity - reserved_quantity >= ?`), which also reuses the bound-parameter cast
  lesson from the SQLite affinity bug.

### A reservation cannot span locations, so spread stock is refused

- **Added**: 2026-08-08 · Ryan's decision during the test-suite repair
- **Tier**: FULL — schema and interface change
- **Context**: a reservation holds exactly one stock record, but availability is summed across all
  of them. 50 units in the warehouse and 50 on a truck cannot back a reservation for 60, and the
  service now refuses with a message naming the fullest location. **Ryan chose this deliberately**
  over implementing the split, to keep a schema change off the repair branch. The real fix makes one
  logical reservation hold several allocations, and touches picking and fulfilment. Until then a
  warehouse with genuinely distributed stock will see refusals it should not.

### Receiving inspection: claimed quantity versus accepted quantity

- **Added**: 2026-08-08 · Ryan's decision during the test-suite repair
- **Tier**: LIGHT
- **Context**: `receipt_lines.received_quantity` and `.unit_cost` were removed as duplicates —
  a receipt line's `quantity` is by definition what was received, and price belongs to the order
  line. The one reading that would have made a second count real is **receiving inspection**: the
  packing slip claims 10, eight pass inspection, two are damaged. That is a genuine warehouse
  requirement and this package has none of it — no inspection step, no rejection reason vocabulary,
  nothing that would ever set two counts differently. Build it deliberately if wanted, with the
  reason field it needs; do not reintroduce a bare second column.

### Stock record selection ignores lots and expiry

- **Added**: 2026-08-08 · test-suite repair review
- **Tier**: LIGHT
- **Context**: `reserve()` now orders candidate stock by id (UUID7, so oldest-created first), but
  stock rows carry `lot_number` and `expiry_date` and first-created is not soonest-expiring —
  wrong for anything perishable. `RemoveStock` picks its record with `->first()` and no ordering at
  all, so with several lots in one location it can decrement a *different* record than the one
  holding the reservation. Aggregates stay correct; per-record quantities drift.

### `inventory:generate-recommendations` is never registered

- **Added**: 2026-08-08 · test-suite repair
- **Tier**: SOLO
- **Context**: the provider gained a `commands()` call for `inventory:sync-tag-values`, and
  `GenerateReorderRecommendationsCommand` was found to have never been registered at all — a host
  app cannot run it. One line, but pair it with the `--tenant` item below, which is the same command.

### `TagQuery` hand-writes taxon's table shape on the projection path

- **Added**: 2026-08-08 · test-suite repair review
- **Tier**: SOLO
- **Context**: the filter path delegates to taxon's own scope, but `labelsAndValuesFor()` writes the
  parent/child join and morph columns itself, and ignores the pivot scope columns taxon's scope
  honours. Taxon offers no way to project a tag value into a `SELECT`, so something had to be
  written — but it breaks silently if taxon's schema moves. Direction: upstream a projection helper
  into taxon, and have this call it.

### Reporting reads five other modules' tables directly

- **Added**: 2026-08-08 · test-suite repair · Ryan flagged the smell independently
- **Tier**: FULL
- **Context**: every report builds raw queries against catalog, stock, purchasing and reconciliation
  tables and their columns. That is why moving categories into taxon broke reports at all — an
  insulated reporting layer would not have noticed. Deliberately out of scope during the repair
  (renovating module boundaries mid-repair would have meant going red again to get green). Revisit
  before the package grows more reports.

### New items are under-stocked by the fixed 90-day averaging window

- **Added**: 2026-08-08 · test-suite repair review
- **Tier**: SOLO
- **Context**: `ReorderCalculator` divides consumption by the requested window (90 days by default),
  per the forecasting spec's formula. An item that has existed for 30 days and consumed 3/day gets
  an average of 1/day and a reorder point a third of what it should be. Spec-conformant and
  arguably wrong. A broken test fixture used to probe this accidentally; nothing tests it now.
  Direction: divide by the shorter of the window and the item's actual history.

### `inventory:generate-recommendations --tenant` is accepted, echoed, and ignored

- **Added**: 2026-08-07 · Track P / P3 quality-baseline gating
- **Tier**: SOLO
- **Context**: `GenerateReorderRecommendationsCommand` reads `--tenant`, prints
  *"Generating recommendations for tenant: X"*, then calls
  `GenerateRecommendations::execute()`, which takes `?array $itemIds` and has no tenant parameter.
  Every run generates for all tenants regardless. Either plumb the tenant filter through
  `ReorderCalculator::generateAllRecommendations()` or drop the option. A `TODO(QUEUE)` marks the
  spot.

### `AccountingExporter` never branches on report shape

- **Added**: 2026-08-07 · Track P / P3 quality-baseline gating
- **Tier**: SOLO
- **Context**: `exportIif()` carried the comment *"Determine if this is a stock valuation report for
  proper formatting"* followed by two `hasColumn()` calls whose results were assigned to variables
  and never read — the branching was never written. Rector flagged the dead assignments; since
  `hasColumn()` is a pure predicate, the calls and the now-orphaned predicate were removed rather
  than left as no-ops. The *feature* — formatting IIF output differently for valuation reports —
  remains unimplemented. One exporter test (`Expecting '…' not to contain 'ENDTRNS'`) is probably
  waiting on this.

### `getConsumptionByPeriod()` has no test coverage on any driver

- **Added**: 2026-08-07 · Track P / P3 quality-baseline gating
- **Tier**: SOLO
- **Context**: it built MySQL-only `DATE_FORMAT()` SQL in a package whose own DDEV is PostgreSQL;
  gating replaced it with a driver-aware expression (`to_char` / `strftime` / `DATE_FORMAT`, see
  `DemandAnalyzer::periodExpression()`). Moving the suite to PostgreSQL flipped which branch is
  proven: the single caller, `tests/Unit/Forecasting/DemandAnalyzerTest.php:45`, now exercises the
  `pgsql` branch, and **the sqlite and mysql branches are unverified**. The weekly and monthly
  variants remain untested on every driver. Add cases per driver, or at minimum assert
  `periodExpression()`'s output per driver.

### Support Pest 5 / PHPUnit 13 / PHP 8.4+ in the constraint matrix
- **Added**: 2026-08-07 · harness health & efficiency session — apps are queued to upgrade to Pest 5 for Tia; consuming apps can't move until this package allows it
- **Tier**: SOLO
- **Why deferred**: harness-wide decision made first; per-package constraint widening is independent work
- **Context**: current: php ^8.2 (lowered from ^8.3 by Track P/D-C), pest ^3.0|^4.0. Widen composer constraints to include pest ^5 / phpunit ^13 / php 8.4+ and run the suite on the new matrix. Research + decisions: $CLAUDE_HARNESS_DIR/notes/harness-health-research-2026-08.md

### 🔴 The declared first-party constraints resolve to nothing that exists

- **Added**: 2026-08-08 · test-suite repair session, prompted by Ryan asking whether a stale taxon would cause rework
- **Tier**: SOLO — a few lines in `composer.json`, but do it deliberately and re-run the gate
- **Why deferred**: the suite must be green first; changing constraints mid-repair would muddy which
  change caused which result
- **Blocks**: any release, and any install outside this machine

`composer.json` requires `robinsonryan/taxon: ^2.0|@dev` and `robinsonryan/vendor: ^1.2|@dev`.
**Neither major version has ever been released.** Taxon's highest tag is `v0.3.0`; vendor's is
`v0.1.1`. The `^2.0` and `^1.2` halves match nothing, ever — the package installs only because the
`|@dev` half combines with the two path repositories pointing at the sibling working directories.
A consumer without `../taxon` and `../vendor-pkg` on disk cannot resolve this package at all.

Correct the constraints to the versions that exist (`^0.3.0`, `^0.1.1`). Note both dependencies are
**0.x**, where semver permits breaking changes on a minor bump — a taxon 0.4 is a real event for
this package, not a routine upgrade. Decide at the same time whether `|@dev` should stay: it is what
makes local sibling development work, and also what has been masking this for as long as it has.

Verified 2026-08-08: taxon's checkout is clean, on `master`, exactly at `v0.3.0` and in sync with
origin — so this package is built against the newest taxon that exists. There is no upgrade pending
and no rework owed. `vendor-pkg` is 3 commits ahead of `v0.1.1`, but all three touch only
`.gitignore`, `QUEUE.md`, `composer.json` and `phpstan.neon` — no source, so no behavioural risk.

### The Laravel 11 promise cannot be kept

- **Added**: 2026-08-08 · same investigation
- **Tier**: SOLO
- **Context**: this package declares `illuminate/*: ^11.0|^12.0|^13.0`. `robinsonryan/taxon` requires
  `^12.0|^13.0`. There is no resolution in which a Laravel 11 host installs this package, so the
  `^11.0` half is advertising a platform that cannot work. Either drop `^11.0` here, or widen taxon
  to include it and prove it with a matrix run. Same family as the constraint item above; fix both
  in one pass.

## Blocked

## Archive

### ~~`config('inventory.tenant.enabled')` and `.scoped` are read by nothing~~ — RESOLVED 2026-08-08
- **Resolved by**: deleting both keys. Ryan's call: the package records tenant ownership and does
  not enforce isolation, so a flag claiming to switch tenancy off was advertising behaviour that
  never existed — setting it `false` produced NOT NULL violations, because ten tables declare
  `tenant_id NOT NULL`. The `scoped` map was wrong as well as unread: it listed `items` as global
  while the items table carries a tenant column and `Item` stamps it. Only `tenant.column` survives.
  `BelongsToTenant`'s docblock — which claimed to add a global scope it never added — now describes
  what it does. A tenant resolver is required; a single-tenant host binds one returning a constant.

### ~~The declared first-party constraints resolve to nothing that exists~~ — RESOLVED 2026-08-08
- **Resolved by**: `robinsonryan/taxon` `^2.0` → `^0.3.0`, `robinsonryan/vendor` `^1.2` → `^0.1.1`,
  matching the reversioning that took every first-party package below 1.0. The `|@dev` alternative
  stays — it is what makes sibling path-repository development resolve. `composer update --dry-run`
  confirms both resolve cleanly.

### ~~The Laravel 11 promise cannot be kept~~ — RESOLVED 2026-08-08
- **Resolved by**: raising `illuminate/*` to `^12.0|^13.0`. `robinsonryan/taxon` requires `^12.0`
  minimum, so the `^11.0` half could never have been satisfied.

### ~~The suite runs on SQLite, not the deployment target~~ — RESOLVED 2026-08-08
- **Resolved by**: moving to the real PostgreSQL 18 in DDEV's `db` service, matching `hey-you`
  (the reference package) and the stack default. It immediately caught a bug that made the
  `locations` table **uncreatable on PostgreSQL** — the primary key was declared with a fluent
  modifier that Laravel emits after foreign keys, and `locations` has a self-referencing key.
  Runtime 42 s → 67 s. Note the inversion: the `sqlite` and `mysql` branches of driver-aware code
  are now the unproven ones, and two concurrent suite runs collide on the shared `testing` database
  (`INVENTORY_TEST_DB_DATABASE` is the escape hatch).

### ~~The test suite is red: 134 of 480 tests fail~~ — RESOLVED 2026-08-08
- **Resolved by**: branch `fix/test-suite-repair`. 527 passing, 1402 assertions, `composer quality`
  exits 0 with no baselines and no suppressions.
- **Root causes, and what each turned out to be**:
  1. *Tag values were never seeded* (63) — the declared value lists were read by nothing. They now
     load during installation, with `inventory:sync-tag-values` as the re-runnable reload path.
  2. *Reports queried tags as columns* (30) — resolved by wrapping taxon rather than denormalising:
     filters go through taxon's own scope, values are attached after the fetch as
     `<field>_label` + `<field>_value`.
  3. *Sealed classes could not be doubled* (16) — listeners now depend on `ReservationContract`;
     the ten tests that doubled the `Reservation` model became real integration tests. `pint.json`
     untouched.
  4. *Schema and residue* (25) — a missing column, tags written before the row was saved, and a set
     of assertions that had never been able to pass.
- **What the repair uncovered that was never a test problem**: reserving stock silently failed on
  SQLite (a bound float compared against a computed expression is unconditionally false there); a
  reservation's own hold blocked its own fulfilment; expired reservations could hand stock back
  twice; recording a second cycle count was impossible; the forecasting fixtures were flinging data
  ~450 days into the past; and exported accounting files carried literal `\t` instead of tabs.
- **Deliberately not fixed here** — all queued above: tenant scoping, concurrency locking,
  reservations spanning locations, receiving inspection, lot-aware stock selection, and reporting's
  direct reads of other modules' tables.

### ~~Raise `robinsonryan/taxon`'s php floor to ^8.2~~ — RESOLVED (verify before closing)
- **Verified 2026-08-08**: `../taxon/composer.json` now declares `"php": "^8.2"`, matching this
  package's promise. The mismatch this item describes no longer exists. Left here rather than
  deleted so the queued entry above it is not read as still-live; delete on the next queue sweep.

### ~~DDEV project-name collision: `inventory`~~ — RESOLVED 2026-08-07
- **Resolved by**: deleting the unrelated legacy app's DDEV registration and renaming its project to `inventory-legacy` in its own committed `.ddev/config.yaml` (`~/dev/sites/modern/inventory/main`, commit `f2b9110`). Ryan: that app is legacy — the thing it was built for is no longer being done.
- **Outcome**: this package now runs as `inventory` under its own **unmodified** `.ddev/config.yaml`. No rename was needed here, and the untracked `.ddev/config.projectname.yaml` overlay has been deleted — no workaround file remains.

