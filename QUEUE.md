# Implementation Queue

> Deferred work, captured mid-session, picked up deliberately. Managed by `/queue`;
> convention: `$CLAUDE_HARNESS_DIR/notes/implementation-queue.md`. Hand-editing is fine.

## Queued

### 🔴 The test suite is red: 134 of 480 tests fail (all pre-existing)

- **Added**: 2026-08-07 · Track P / P3 quality-baseline gating
- **Tier**: FULL — this is package repair, not a gate fix, and several items are product decisions
- **Why deferred**: `lint:check`, `analyze` and `refactor:check` are green; `test` is not, and cannot be
  made green without design decisions that belong to the package owner. **`composer quality` exits
  non-zero.** Do not treat P3 as done.
- **Provenance**: this suite had never run — the package had no `vendor/` and no `composer.lock`, so
  every failure below predates the gating session. Verified: no failure references any symbol
  introduced during gating, and the two structural offenders (`ReservationService` already `final`,
  `items` never having a `category` column) were confirmed against `git show HEAD`.

Root causes, largest first:

1. **Tag values are never seeded (~55 failures).** Every `TagDefinition` here declares a
   `DEFAULT_VALUES` constant, and **nothing ever consumes it.** Taxon's
   `TagDefinition::values()` reads the tag's *children rows from the database*, and
   `isValidValue()` returns true only when that list is empty. So the first write of a status
   auto-creates it, and the *second, different* value is then rejected —
   `'submitted' is not a valid value for PurchaseOrderStatusDefinition`. **Highest-leverage
   fix by a wide margin**: give the package an install/seed path that walks each definition's
   `DEFAULT_VALUES` through taxon's `TagDefinition::firstOrCreateValue()`, and call it from
   `tests/TestCase.php`. Decisions needed: artisan command vs seeder vs provider boot, and
   whether seeding is per-tenant.
2. **Tags are queried as if they were columns (~40 failures).** `category` (items), `type`
   (stock_movements, locations), and `status` (reservations, purchase_orders, cycle_counts)
   are taxon tag attributes, not columns — but the six reports `select`/`where` them directly,
   producing `no such column: items.category`, `no such column: stock_movements.type`,
   `no such column: reservations.status`, `table cycle_counts has no column named status`.
   Decision needed: denormalise these onto the tables, or rewrite the reports to join
   `tags`/`taggables`. This is the package's central unresolved design question.
3. **`ReservationService` and `Reservation` are `final`, and the tests mock them (16 failures).**
   `pint.json` enforces `final_class`, so Mockery cannot double them:
   *"marked final and its methods cannot be replaced"*. Fix: extract an interface the listeners
   depend on, or convert those unit tests to integration tests against a real service.
4. **`receipt_lines.received_quantity` does not exist (5 failures).** Code and tests read/write
   the column; the migration never created it. Confirmed absent at HEAD.
5. **Tag assigned before the row is persisted (2 failures).**
   `NOT NULL constraint failed: taggables.taggable_id` — `setTagAs()` attaches a pivot row, so
   the owning model has to be saved first.
6. **Residual assertion failures (~10).** e.g. `Failed asserting that 100.0 is identical to 75.0`,
   `Cannot fulfill reservation without a location`, and an IIF exporter expecting output *not* to
   contain `ENDTRNS`. Work these last — several are probably knock-on from 1 and 2.

### `config('inventory.tenant.enabled')` and `.scoped` are documented but read by nothing

- **Added**: 2026-08-07 · Track P / P3 quality-baseline gating
- **Tier**: LIGHT
- **Context**: `config/inventory.php` advertises a tenancy switch and a per-table `scoped` map.
  Grep says no code in `src/` reads either key. `BelongsToTenant` always tries to resolve a tenant,
  and the tenant-scoped tables (`locations`, `stock`, `stock_movements`, `reservations`,
  `reorder_recommendations`) declare `tenant_id` NOT NULL — so a single-tenant host that sets
  `enabled => false` gets `NOT NULL constraint failed` on its first insert. Either honour the flag
  (and relax those columns) or delete the flag and document that tenancy is mandatory.
- **Gate note**: `tests/TestCase.php` previously set `enabled => false`, which is why 215 tests died
  on `locations.tenant_id`. It now binds `tests/FixedTenantResolver.php` and runs tenancy ON — the
  package's actual default — rather than routing around the schema.

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
- **Context**: it built MySQL-only `DATE_FORMAT()` SQL in a package whose own DDEV is PostgreSQL and
  whose suite runs SQLite; gating replaced it with a driver-aware expression
  (`to_char` / `strftime` / `DATE_FORMAT`, see `DemandAnalyzer::periodExpression()`). The single
  caller, `tests/Unit/Forecasting/DemandAnalyzerTest.php:45`, exercises only the `sqlite` branch —
  **the pgsql and mysql branches are unverified**, and the weekly/monthly variants are untested
  everywhere. Add cases per driver, or at minimum assert `periodExpression()`'s output per driver.

### Support Pest 5 / PHPUnit 13 / PHP 8.4+ in the constraint matrix
- **Added**: 2026-08-07 · harness health & efficiency session — apps are queued to upgrade to Pest 5 for Tia; consuming apps can't move until this package allows it
- **Tier**: SOLO
- **Why deferred**: harness-wide decision made first; per-package constraint widening is independent work
- **Context**: current: php ^8.2 (lowered from ^8.3 by Track P/D-C), pest ^3.0|^4.0. Widen composer constraints to include pest ^5 / phpunit ^13 / php 8.4+ and run the suite on the new matrix. Research + decisions: $CLAUDE_HARNESS_DIR/notes/harness-health-research-2026-08.md

### Raise `robinsonryan/taxon`'s php floor to ^8.2 (or accept the mismatch)
- **Added**: 2026-08-07 · Track P / P3 quality-baseline gating
- **Tier**: SOLO
- **Why deferred**: taxon is a separate package on its own Track P slot; changing another package's constraints mid-gate is out of scope here
- **Context**: this package now promises `php: ^8.2` (harness decision D-C) but requires `robinsonryan/taxon`, which still declares `php: ^8.3`. Composer resolves it today only because the local platform is 8.3 — a genuine 8.2 consumer would fail to install. Either lower taxon's floor to ^8.2 or record that laravel-inventory's ^8.2 promise is aspirational.

## Blocked

### DDEV project-name collision: `inventory`
- **Added**: 2026-08-07 · Track P / P3 quality-baseline gating
- **Tier**: SOLO
- **Blocked on**: Ryan's approval — `.ddev/config.yaml` is a protected file
- **Context**: this package's `.ddev/config.yaml` declares `name: inventory`, which collides with an unrelated Laravel app at `~/dev/sites/modern/inventory/main` that has owned the `inventory` DDEV registration for weeks. `ddev describe` here fails with "a project (web container) in running state already exists for inventory".
- **Proposed fix**: rename this package's project to `inventory-pkg` in `.ddev/config.yaml`. Every sibling package already names its DDEV project after itself, and `vendor-pkg` shows the precedent for a directory/name split.
- **Interim workaround (in place, untracked, deliberately NOT committed)**: `.ddev/config.projectname.yaml` containing `name: inventory-pkg`. DDEV merges `config.*.yaml` over `config.yaml`, so the package starts under its own name without touching the protected file. **If you are wondering why `ddev list` shows `inventory-pkg` while `.ddev/config.yaml` says `inventory`, that file is why.** Delete it once the rename lands.

## Archive
