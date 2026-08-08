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

## 🔴 The test suite is red and parked — read this before you touch any PHP

**134 of 480 tests fail** (346 pass, 857 assertions, ~32 s). Verified 2026-08-08 with
`ddev exec vendor/bin/pest`. `lint:check`, `analyze` and `refactor:check` are all
green — no baselines, no ignore rules — so `composer quality` fails on `test` alone.

**What that means for you, concretely: you cannot commit a PHP change here.** The
pre-commit hook runs the *whole* gate, tests included, whenever the staged set
touches `*.php`, `composer.json`, `phpstan.neon`, `pint.json`, `rector.php` or
`phpunit.xml`. That commit will be rejected, every time, and that is intentional —
the red suite is a deliberate parking marker, not gate drift. A docs-only commit
skips the gate and is the only kind of commit that lands here today.

Never route around it: `--no-verify` is forbidden, and `PACKAGE_SKIP_GATE=1` is a
human-only emergency valve — **agents must never set it.**

Every failure predates the gating session: the package had no `vendor/` and no
`composer.lock`, so the suite had **never been executed**. Its first-ever run
produced 480 failures and zero assertions — it fatalled on load. So the repair is a
genuine engineering job with product decisions at its centre, and it has to come
first. `QUEUE.md` holds the full item-by-item breakdown; the clusters below are the
map.

### Cluster 1 — nothing ever seeds the tag values (64 failures, the highest-leverage fix)

All six `TagDefinition` classes declare a `DEFAULT_VALUES` constant
(`ItemCategoryDefinition`, `LocationTypeDefinition`, `MovementTypeDefinition`,
`ReservationStatusDefinition`, `PurchaseOrderStatusDefinition`,
`CycleCountStatusDefinition`) and **nothing in `src/` or `database/` reads it.** The
only references are test assertions about the constant itself.

Taxon decides validity from the database, not the constant:
`TagDefinition::values()` plucks the tag's *child rows*, and `isValidValue()` returns
`true` only while that list is empty. So the first status written to a fresh database
auto-creates itself and passes; the second, *different* status is then measured
against a one-element list and rejected —
`'submitted' is not a valid value for PurchaseOrderStatusDefinition`.

Fix shape: an install/seed path that walks each definition's `DEFAULT_VALUES` through
taxon's `TagDefinition::firstOrCreateValue()`, called from `tests/TestCase.php`.
Ryan's decisions, not yours: artisan command vs. seeder vs. provider boot, and
whether seeding is per-tenant.

One trap while you're in here: `isValidValue()` slugifies with `Str::slug()`, so
`in_progress` round-trips as `in-progress`. That is why one cycle-count test dies on
`Cannot complete cycle count in status "in-progress". Count must be "in_progress".`

### Cluster 2 — tags are queried as columns (30 failures) — a design question, not a bug

This is the package's central unresolved design question and **must not be
patched over.** `category` (items), `type` (stock_movements, locations) and `status`
(reservations, purchase_orders, cycle_counts) are taxon *tag attributes*. They are
not columns and were never created by a migration. The reports under
`src/Reporting/Reports/` nevertheless `select` and `where` them directly:

- `items.category` — `StockValuationReport`, `LowStockReport` (15 failures)
- `stock_movements.type` — `MovementHistoryReport`, `LowStockReport`'s usage subquery (5)
- `reservations.status` — `ReservationReport` (5)
- `cycle_counts.status` — `VarianceReport` (5)

Two coherent resolutions, and picking between them is Ryan's call: denormalise these
onto the tables, or rewrite the six reports to join `tags`/`taggables`. Both are real
work with consequences for the host app's query surface.

### Cluster 3 — `final_class` vs. Mockery (16 failures) — a cross-package convention decision

`pint.json` enforces `final_class`. `tests/Unit/Jobs/ListenersTest.php` mocks
concrete classes, and Mockery cannot double a `final` one: *"marked final and its
methods cannot be replaced"* — 10 failures on the `Reservation` model, 6 on
`ReservationService`.

Note carefully: **the style rule did not cause these failures.** Both classes were
already `final` before gating (`ReservationService` is `final readonly`). The rule
and the testing style are simply in tension. The resolution — mock an interface, not
a class — is a convention decision spanning all seven first-party packages, not this
package's to make unilaterally. Do not "fix" it here by dropping `final_class` from
`pint.json`.

### Cluster 4 — schema and residue (24 failures)

- `receipt_lines.received_quantity` is read and written by code and tests, and the
  migration never created it (5).
- `NOT NULL constraint failed: taggables.taggable_id` — `setTagAs()` writes a pivot
  row, so the owning model has to be saved first (2).
- 3 × `Cannot fulfill reservation without a location`, 1 status-slug mismatch,
  1 `TypeError`, and 12 plain assertion failures (e.g. `100.0 is identical to 75.0`,
  an IIF exporter expecting output *not* to contain `ENDTRNS`). Work these last —
  several are probably knock-on from clusters 1 and 2.

## Defects already found and fixed — where they still shape the code

These are done. They are recorded because the shapes they left behind look arbitrary
until you know why.

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
fixing out-of-scope things mid-task. Currently queued beyond the test suite:
the advertised-but-unread `inventory.tenant.enabled` / `.scoped` config keys, the
`inventory:generate-recommendations --tenant` option that is accepted and ignored,
the never-written `AccountingExporter` report-shape branching, the untested
`getConsumptionByPeriod()` drivers, widening the constraint matrix to Pest 5 /
PHPUnit 13 / PHP 8.4, and taxon's `php: ^8.3` floor undercutting this package's
`^8.2` promise.

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
- **Gate**: `ddev composer quality` (currently red — see above)
- **Tests**: `ddev composer test`
- **Style fix**: `ddev composer lint`
- **Rector fix**: `ddev composer refactor`
- Everything runs inside DDEV. Never use bare `composer` / `php` on the host.
