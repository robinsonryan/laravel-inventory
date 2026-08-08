# Inventory

A full inventory-management domain for Laravel host applications: a catalog (items,
kits, assemblies, transformations, substitutions), physical stock (locations,
quantities, an immutable movement ledger, reservations), purchasing (purchase orders,
receipts), reconciliation (cycle counts, variance adjustments), forecasting (demand
analysis, reorder recommendations) and reporting (six reports plus CSV/JSON/accounting
exporters). A Jobs integration layer reserves and consumes stock in response to the
host app's work-order events. Multi-tenant by default, headless — no routes, no
controllers, no views, one console command.

Composer name: `robinsonryan/laravel-inventory` — a **library**, not an application.

> **The composer name does not match the directory name.** The package is
> `robinsonryan/laravel-inventory` but lives in `~/dev/php/packages/robinsonryan/inventory`,
> and its DDEV project is `inventory`. Requiring `robinsonryan/inventory` gets you
> nothing; `cd`-ing to `.../laravel-inventory` gets you nothing. The sibling package
> `robinsonryan/vendor` has the same split in the other direction — its directory is
> `vendor-pkg`, so it can't be mistaken for composer's `vendor/`. Both path
> repositories in `composer.json` point at the *directory* names (`../taxon`,
> `../vendor-pkg`), and `.ddev/docker-compose.deps.yaml` mounts them into the
> container at `/var/www/taxon` and `/var/www/vendor-pkg`.

## Conventions

@import ./constitution.md
@import ./imports/package-conventions.md
@import ./imports/package-quality-gate.md
@import ./imports/testing-conventions.md
@import ./imports/php-conventions.md
@import ./imports/git-conventions.md

> Linking the `laravel-package` stack also drops the inherited Laravel app
> conventions into `.claude/imports/` — `authorization-conventions.md`,
> `frontend-conventions.md`, `pwa-conventions.md`, `ddev-worktrees.md`. They are
> **deliberately not imported above**: they describe Inertia `can` maps, app-shaped
> Vite wiring, and nested app worktrees, none of which exist in a package. Read
> them if a question genuinely calls for one; do not load them by default.
> This package has **no frontend half** — there is no `package.json` — so
> `frontend-conventions.md` stays out too.

> `.claude/` is a set of **harness symlinks** and is gitignored — a fresh clone has
> none of them and the `@import`s above resolve to nothing. If a convention file is
> missing, restore the link rather than guessing:
> `~/workspace/harness/link.sh project laravel-package $(pwd)`
>
> The exception is `.claude/specs/` — seven per-module specifications that **are
> tracked in git** (catalog, stock, purchasing, reconciliation, forecasting,
> reporting, jobs). They are the design intent for the modules under `src/`; read
> the relevant one before changing a module's shape.

## The suite is green — and how it got there shapes the code

**527 tests pass** (1402 assertions, ~57 s). `composer quality` exits 0 — `lint:check`, `analyze`,
`refactor:check` and `test`, no baselines, no ignore rules, no suppressions. Verified 2026-08-08 on
branch `fix/test-suite-repair`.

It was 134 failures on 480 tests, and the package had never once had its suite executed — no
`vendor/`, no `composer.lock`, so the first-ever run fatalled on load. Repairing it turned up six
defects that were never test problems, listed below. `QUEUE.md`'s archive has the full account.

**Four decisions Ryan took during the repair. Do not quietly re-open them.**

1. **"Usage" means stock that leaves and does not come back** — sales, job consumption, scrap; not
   transfers between your own locations. The low-stock report and the demand analyser previously
   disagreed (one read the movement's *label*, one read its *shape*). They now share
   `Support\MovementScopes::depleting()`. **Never inline that filter again** — one shared definition
   is the whole point.
2. **Tag values load during installation**, via a migration, with `inventory:sync-tag-values` as the
   re-runnable reload path. Not a seeder, not on boot: this is structural data the package cannot
   run without, so it must not be skippable.
3. **Tag-backed fields appear in reports as a pair** — `<field>_label` (human, "In Progress") and
   `<field>_value` (stable, `in-progress`). The **label** is the declared column, so the spreadsheet
   export shows it alone; the value rides in the row data and reaches the JSON feed. This is the
   convention for every tag-backed field added from now on.
4. **Listeners depend on `Contracts\ReservationContract`, not the concrete service.** Classes stay
   `final`; `pint.json` is not to be touched. Tests that need a `Reservation` *model* use real
   records — you do not write a contract for a database row.

### How tags actually work here — read before touching a report

`category` (items), `type` (locations, stock movements) and `status` (reservations, purchase orders,
cycle counts) are **taxon tags, not columns.** No migration creates them and none ever will.

Taxon can *filter* by tag but cannot project a tag value into a `SELECT` or `GROUP BY`. So the
reports do both halves separately, and `Support\TagQuery` is the only place that knows how:

- **Filtering** — `TagQuery::filterIdsByTagValue()` builds an id subquery from taxon's own scope.
- **Projecting** — `TagQuery::labelsAndValuesFor()` maps ids to label+value in one query, applied
  *after* the rows are fetched by `Reporting\Support\TagRowValues`.

Writing `select('items.category')` will fail with "no such column", every time. That is not a bug to
fix; it is the design.

**The slug trap.** Taxon slugifies on every read and write path *except* `firstOrCreateValue()`.
`in_progress` stores as `in-progress`. Comparing code against the underscored spelling is a bug that
has now been fixed three separate times (`CompleteCounting`, `ReconciliationService`, `RecordCount`)
— `in-progress` is the only value in all six vocabularies whose slug differs from its source
spelling, and it is now declared hyphenated. Do not reintroduce the other form.

## Defects already found and fixed — where they still shape the code

These are done. They are recorded because the shapes they left behind look arbitrary
until you know why.

- **A bound float compared against a computed expression silently matched nothing on SQLite.**
  `whereRaw('quantity - reserved_quantity >= ?')` bound the quantity as a string, and SQLite applies
  no type affinity to an *expression*, sorting every number below every string — so the availability
  lookup was unconditionally false, `reserve()` produced reservations attached to no stock row, and
  fulfilment later died for want of a location. PostgreSQL and MySQL coerce it and were never
  affected, which is why nothing looked wrong by inspection. Fixed with `CAST(? AS DECIMAL(20,4))`.
  **Keep that cast** — a test reserving a fractional quantity fails without it. Audit any new raw
  fragment that compares a bound number to an expression.
- **A reservation's own hold blocked its own fulfilment.** `fulfill()` consumed stock before
  returning the hold, and the removal's availability check subtracts `reserved_quantity` — so any
  reservation larger than the *unreserved* remainder could never be fulfilled. The hold is now
  returned first, inside the same transaction. Ordering is load-bearing; do not reorder it.
- **Closed reservations could hand stock back twice.** Expiry set the status but not the timestamp
  the guards read, so releasing an expired reservation drove `reserved_quantity` negative and
  inflated available stock — confidently wrong numbers rather than an error. All four closing paths
  now share one `closedState()` predicate and re-read the stored row. That duplication is exactly
  where the gap grew: if you add a fifth path, use the predicate.
- **Recording a second cycle count was impossible** — the slug trap above, in `RecordCount`. No test
  had ever recorded more than one count, which is why it survived.
- **The forecasting fixtures were flinging data ~450 days into the past.**
  `Carbon::setTestNow(Carbon::now()->subDays(...))` inside a loop compounds, because `now()` returns
  the already-mocked time. Hoist the base time out of the loop.
- **Exported accounting files carried literal `\t` instead of tabs** — the format declarations were
  single-quoted PHP strings, so every export would have been rejected on import.
- **`receipt_lines` briefly grew `received_quantity` and `unit_cost`, and they were removed again.**
  A receipt line is the receiving desk's record: what turned up, how much, where it went. Its
  `quantity` *is* the received quantity, and price belongs to the order line it references —
  receiving staff have no way to know it. `PurchaseOrderReport` joins for the cost. Do not
  reintroduce them; receiving *inspection* is a real feature and is queued as one.

- **Three factories lived in a namespace PSR-4 could never autoload.**
  `database/factories/Stock/{Stock,Location,Reservation}Factory.php` declared
  `RobinsonRyan\Inventory\Stock\Database\Factories`. Autoloading maps
  `RobinsonRyan\Inventory\Database\Factories\` → `database/factories/`, so those
  three resolved to nothing — a hard fatal at 94 call sites across the suite,
  invisible for as long as the suite had never run. All ten factories now sit under
  `RobinsonRyan\Inventory\Database\Factories\<Module>`; keep new ones there.
- **Every migration was unrunnable outside PostgreSQL 18.** All 20 create-table
  migrations defaulted their primary key to `DB::raw('uuidv7()')`, a PG18 built-in —
  so they failed on SQLite, on MySQL, and on this package's own PostgreSQL 17
  container. Migrations now call `SchemaSupport::uuidPrimary($table)`, which probes
  `pg_proc` once and applies the default only where the connection actually provides
  the function. The `HasUuid7` trait generates keys in PHP regardless, so the
  database default is only a safety net for non-Eloquent inserts. **Never write
  `uuidv7()` into a migration directly** — no migration references it today.
- **`DemandAnalyzer::getConsumptionByPeriod()` built MySQL-only `DATE_FORMAT()` SQL**
  in a PostgreSQL/SQLite package. Replaced by `DemandAnalyzer::periodExpression()`,
  a per-driver `match` (`to_char` / `strftime` / `DATE_FORMAT`). **Only the sqlite
  branch is exercised** — by a single caller,
  `tests/Unit/Forecasting/DemandAnalyzerTest.php:45`. The pgsql and mysql branches
  are unverified, and the weekly/monthly variants are untested on every driver.
  Treat that method as unproven.

`CHANGELOG.md` has the rest of the gating session's fixes (taxon `withTagIn()`
signature misuse, `ModelResolver`/`TableResolver` config validation, the
`ReportData::$rows` array-shape contract, the removed `CompleteCounting` constructor
argument).

## Deferred work

`QUEUE.md` is the register — don't duplicate it here, and add to it rather than
fixing out-of-scope things mid-task.

Three queued items are big enough that you should know them before planning any
work, because each one changes what a change to this package means:

- **Nothing is tenant-scoped.** `BelongsToTenant` stamps `tenant_id` on create and
  adds no query scope, so every report returns every tenant's rows and a job
  cancellation can release another tenant's reservations. The package describes
  itself as multi-tenant by default; it is not. Two reviewers found this
  independently. Don't build on the assumption that scoping exists.
- **Reservation writes are not concurrency-safe.** No row locking on the
  check-then-increment, so two simultaneous reserves over-commit the same stock on
  PostgreSQL and MySQL. Invisible on SQLite, which is what the suite runs on.
- **Reporting reads five other modules' tables directly**, which is why moving
  categories into taxon broke reports at all.

Also queued: the unsatisfiable `^2.0` / `^1.2` first-party constraints (nothing that
version has ever been released — this package installs only via the sibling path
repositories), the unkeepable Laravel 11 promise, the unread
`inventory.tenant.enabled` / `.scoped` config keys, the ignored
`inventory:generate-recommendations --tenant` option and that command never being
registered at all, the never-written `AccountingExporter` report-shape branching, the
untested `getConsumptionByPeriod()` drivers, reservations being unable to span
locations, receiving inspection, lot-aware stock selection, and widening the
constraint matrix to Pest 5 / PHPUnit 13 / PHP 8.4.

## The gate

`ddev composer quality` — `lint:check` → `analyze` → `refactor:check` → `test`.
Verify-only: it never rewrites files. Fix with `ddev composer lint` /
`ddev composer refactor` and re-stage.

`.githooks/pre-commit` runs **the whole gate, tests included** — packages are
small enough that the apps' exclude-the-tests compromise does not apply. It is
path-aware, so a docs-only commit skips it. It also refuses to run at all when DDEV
is down, docs-only or not ("no DDEV, no commit"). Full output goes to
`<git-dir>/pre-commit.log`.

That hook file is a **copy** of the harness's canonical one. Do not edit it here
— edit `$CLAUDE_HARNESS_DIR/core/stacks/laravel-package/hooks/pre-commit` and
re-run that directory's `install.sh`. `ddev start` wires `core.hooksPath` via a
post-start `exec-host` (`.ddev/config.githooks.yaml`), so a fresh clone is gated
without a manual step.

`harness package-check` sweeps every first-party package: the gate, a
`--prefer-lowest` run proving the declared version floor really resolves,
outdated and vulnerability scans, and in-constraint updates behind a re-run of
the gate. It never tags a release. Run it before any app re-resolves its
packages.

Full definition: `imports/package-quality-gate.md`. Skill: `/package-quality`.

## Testing

Pest 3/4 + Orchestra Testbench. **The suite runs on in-memory SQLite**, not on
DDEV's Postgres — `tests/TestCase.php` sets `database.default` to a `sqlite`
`:memory:` connection. That is a deviation from the stack default, and it is why the
`uuidv7()` bug above had to be fixed rather than worked around. DDEV's `db` service
is PostgreSQL 17 and is not what the tests touch.

`tests/TestCase.php` also fixes the shape of every test run:

- Providers loaded: taxon, vendor, inventory.
- Migrations come from `vendor/robinsonryan/{taxon,vendor}/database/migrations`
  resolved through the composer install — **not** by walking up the directory tree,
  because the sibling layout is unstable (`robinsonryan/vendor` lives in `vendor-pkg`).
  A missing dependency throws one legible `RuntimeException` instead of scattering
  "no such table" across the suite.
- **Tenancy is ON**, bound to `FixedTenantResolver` with a constant tenant
  (`TestCase::TENANT_ID`). Do not turn it off to make a test pass — the tenant-scoped
  tables declare `tenant_id NOT NULL`, so there is no schema in which disabling it
  works. That shortcut previously killed 215 tests.
- **Tag values are populated by a migration**, so every test starts with all six
  vocabularies fully loaded. `TestCase` needs no seeding hook, and a test that
  writes a status no longer creates it by accident.

**A green suite here does not mean the package works on PostgreSQL.** SQLite is a
different database, and it has already hidden one serious defect on its own: the
reservation lookup that silently matched nothing did so *only* on SQLite, and
PostgreSQL would have been fine. The reverse also holds — the concurrency gaps in
`QUEUE.md` are invisible here because SQLite has a single writer. Treat driver-
specific behaviour (raw SQL, type coercion, locking) as unproven until something
actually runs it on Postgres.

```bash
ddev composer test
ddev exec vendor/bin/pest --filter=SomeTest
```

There is no `ddev artisan` and no `ddev pest` here — those are app commands.

## Releases

**Never tag.** Automation may update, gate, commit and push a branch, then report
"ready to tag" with a suggested version. Ryan cuts every tag. A version number is
a claim about behavior that a green gate cannot substantiate. This package is
especially far from tag-worthy while the suite is red.

Behavior changes land in `CHANGELOG.md` in the commit that makes them.

## Reference package

`~/dev/php/packages/robinsonryan/hey-you/` is the reference implementation —
service provider shape, Testbench setup, tool configs, table prefixing. Read it
before inventing a variant.

## Quick reference

- **DDEV**: `ddev start`, `ddev ssh` (project name `inventory`)
- **Gate**: `ddev composer quality` (green — keep it that way; the hook runs the whole thing)
- **Tests**: `ddev composer test`
- **Style fix**: `ddev composer lint`
- **Rector fix**: `ddev composer refactor`
- Everything runs inside DDEV. Never use bare `composer` / `php` on the host.
