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

### Plain stock writes are still not safe under concurrency

- **Added**: 2026-08-08 · while locking the reservation writes
- **Tier**: LIGHT
- **Context**: the reservation paths are now locked, and nothing else is. `Stock\Actions\RemoveStock`
  reads a stock record, computes `quantity - reserved_quantity`, decides, and saves — the same
  check-then-act shape the reservation bug had, so two simultaneous removals can each pass the
  availability check and overdraw the shelf. `AddStock`, `TransferStock` and `AdjustStock` read and
  write the same records; `AdjustStock` is the worst of them because it writes an absolute quantity,
  so a concurrent movement is simply erased rather than merely miscounted. Purchasing receipts and
  cycle-count variance adjustments reach the same records through those actions.
- **Direction**: `Stock\Services\StockService` already wraps each action in `DB::transaction`, so
  the fix is the same one `ReservationService::findAvailableStock()` uses — lock the record on the
  way in, in primary-key order — plus a decision about `TransferStock`, which touches two records
  and therefore has a genuine ordering requirement rather than a nominal one.
- **Testable**: yes, now. `tests/Concurrency/` has the harness — a base class without the per-test
  transaction, and `Support\CompetingWriter`, which drives a second process holding a real lock and
  signals over a file handshake.
- **Also owns the deadlock ordering problem below.** Ryan's decision, 2026-08-08: the reservation
  paths' remaining lock-ordering gap is not its own queue item, because fixing it needs the same
  choices this one already owes for `TransferStock`.

**The stock deadlock: two paths take the same two records in opposite order**

Locking the reservation writes removed the silent over-promising. It did **not** give the package a
global lock order, and the docblocks that first shipped with that fix claimed it did. They have been
corrected rather than quietly left — `ReservationService::expireOld()`, `::findAvailableStock()` and
`::syncFromStorage()` now state the true position, and this section is the fix for what they admit.
What holds today is narrow and worth stating exactly: reservations are locked before stock in every
path, and `reserve()` takes stock without ever wanting a reservation, so there is **no
reservation-stock cycle**. Stock-stock cycles are a different matter.

*Why the cycle exists — first counterexample: the sweep against a fulfilment, at a shelf holding two
lots.* Main Warehouse holds one item in two lots, so two stock records: **S1** (lot A) and **S2**
(lot B). Two expired reservations charge them — **R-early** (the lower id) charges S2, **R-late**
charges S1. The expiry sweep locks both reservation rows in one statement, then walks them in id
order: R-early first, so it decrements **S2** and holds it; then R-late, so it wants **S1**.
Meanwhile a third reservation bound to S1 is being fulfilled on another connection. `fulfill()`
calls `returnHold()`, which decrements the record its reservation actually charged — **S1**, now
held. Then it calls `removeStock()`, and this is where it goes wrong: `fulfill()` builds its
`RemoveStockData` with no lot number (`src/Stock/Services/ReservationService.php:154`, and
`partialFulfill()` the same at `:194`), and
`RemoveStock` filters on item and location alone with a bare `->first()` and no ordering
(`src/Stock/Actions/RemoveStock.php:22-27`). So it can pick **S2** — a different record from the one
the reservation charged — and want it. Sweep holds S2 and wants S1; fulfilment holds S1 and wants
S2. That is the cycle. Note the fulfilment's own reservation is not expired, so the sweep is not
holding it: nothing about this is a reservation-lock problem.

*Second counterexample: a host reserving several items in one transaction.* `orderBy('id')` on the
candidate search orders candidates **within a single item's search**. It says nothing about the
order across the several searches a caller issues. Host A opens a transaction and calls
`reserve(WIDGET)` then `reserve(GADGET)`, taking WIDGET's stock record and then GADGET's. Host B, in
its own transaction, calls `reserve(GADGET)` then `reserve(WIDGET)`. Each ends up holding the record
the other is waiting for. This is exactly the scenario the original docblock cited as the thing
`orderBy('id')` had fixed. It had not.

*What it costs today.* PostgreSQL detects both cycles and aborts one of the two transactions with a
deadlock error (SQLSTATE `40P01`). Read that precisely in both directions. It is **not** a return of
the silent over-promising this concurrency work eliminated — no stock is lost, no hold is
double-counted, and the surviving transaction is correct; the aborted one rolls back whole. Equally
it is **not** harmless: a host doing multi-item or lot-split writes under load will see failed
transactions it did not ask for, and if it does not retry them, work simply does not happen. The
honest summary is that the failure mode has been converted from a wrong number nobody can see into a
loud error somebody has to handle — which is the trade this whole locking exercise was making, and
the reason this is queued rather than urgent.

*What a fix has to decide.* This is real work, not a patch:

- **Lot-aware record selection.** `fulfill()` and `partialFulfill()` should consume the record their
  reservation actually charged (`reservations.stock_id` is right there) rather than letting
  `RemoveStock` re-pick by item and location. That removes the first counterexample outright, and it
  is the same decision as the queued *Stock record selection ignores lots and expiry* item below.
  **These two should probably merge** — that item already owns `RemoveStock`'s unordered `->first()`
  and the lot/expiry ordering question, and fixing either one alone leaves the other's symptom in
  place. Whoever picks this up should make that call first, before touching code.
- **A global lock-acquisition order for stock records** that holds *across* items and across calls,
  not merely within one candidate search. The likely shape is a batch entry point that collects the
  whole working set, sorts it by primary key, and takes every lock before doing any work — which
  means reservations acquire a plural API, and `reserve()` becomes the single-element case of it.
- **`TransferStock` needs the same rule.** It touches two records by definition, and it is already in
  this item's scope. Whatever ordering rule the point above produces has to cover it, so design the
  rule once for both rather than fixing transfer with a local convention.
- **Retry: the package's job or the host's?** Right now it is the host's, documented in `CHANGELOG.md`
  and in the corrected docblocks, and the package imposes no `lock_timeout` anywhere either. The
  alternative is a bounded retry inside `StockService`'s transaction wrapper. Decide deliberately —
  retrying inside a transaction the host opened is not something a library can do safely without
  saying so loudly, and a `lock_timeout` chosen by a library is a latency budget chosen by a library.

*Where to start.* `tests/Concurrency/` is the harness: `Tests\ConcurrencyTestCase` drops the per-test
transaction, and `Tests\Support\CompetingWriter` drives a competing OS process that takes a real row
lock and signals over a file handshake. A deadlock test is buildable there with a **third** process,
so two competitors can hold the two records in opposite order while the test's own connection plays
the third party. Write the assertion carefully: a deadlock test must assert *which* transaction
survives and that the stock totals afterwards are still correct — asserting merely that "an error was
raised" would pass just as happily on a lock timeout, a connection drop, or the bug still being
there.

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
- **Overlaps the deadlock section in *Plain stock writes are still not safe under concurrency*
  above**, which needs the same change for a different reason: `RemoveStock` re-picking a record its
  reservation never charged is one half of the sweep-versus-fulfilment lock cycle. Consider merging
  the two items before starting either — that decision is called out there.

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

### ~~Reservation writes are not safe under concurrency~~ — RESOLVED 2026-08-08
- **Resolved by**: row locking, not a conditional update. `findAvailableStock()` takes
  `SELECT … FOR UPDATE` on the record it is about to charge, and `syncFromStorage()` and the expiry
  sweep take it on the reservation rows they are about to close. The conditional-update alternative
  (`UPDATE … WHERE quantity - reserved_quantity >= ?`, then read the affected row count) was rejected
  because it only covers `reserve()`: double *fulfilment* is a race over a reservation's state, not
  over a number, so there is no arithmetic to push the check into, and locking would have been
  needed there regardless. One mechanism for one problem.
- **Ordering, stated accurately**: reservations are locked before stock in every path, and
  `reserve()` never wants a reservation lock, so there is no reservation-stock cycle. That is the
  whole of the guarantee. Stock-stock cycles remain reachable — a sweep against a fulfilment at a
  lot-split location, and two hosts reserving the same several items in opposite order — because
  `orderBy('id')` only orders candidates within a single query, not across the several a caller
  issues. PostgreSQL aborts one side with a deadlock error, so the failure is loud and retryable
  rather than a wrong number. The docblocks originally shipped with this fix claimed more than
  that and have been corrected; the remaining ordering work is folded into the plain-stock-writes
  item above, which already owed the same decision for `TransferStock`.
- **A bonus PostgreSQL gives for free**: `FOR UPDATE` re-evaluates the availability predicate after
  the wait, and a `LIMIT 1` scan moves on to the next candidate when the row it waited for no longer
  qualifies. So a caller that loses a race for one record is served from another where one exists,
  rather than refused. Proven, not assumed — `tests/Concurrency/ReservationConcurrencyTest.php`
  asserts it.
- **Tests**: four, in a new `Concurrency` testsuite that runs *outside* the per-test transaction
  (`Tests\ConcurrencyTestCase`), against committed fixtures, with `Tests\Support\CompetingWriter`
  driving a second OS process that holds a real row lock over a file handshake. Each of the three
  `lockForUpdate()` calls was verified red **on its own**, not merely as a set: deleting the
  candidate-search lock over-commits the shelf and binds a reservation to a record another
  connection had emptied; deleting the re-read lock lets one reservation be consumed twice;
  deleting the sweep's lock hands the same hold back twice, leaving a reserved quantity of −60
  against a shelf of 100. The sweep's lock had no test of its own when this fix first landed —
  that was a review finding, and the fourth test is the answer to it.
- **Not covered**: everything that is not a reservation — see the plain-stock-writes item above.

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

