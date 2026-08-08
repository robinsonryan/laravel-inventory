# Changelog

All notable changes to `robinsonryan/laravel-inventory` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-08-08

### Added

- `composer quality` and the canonical script set (`test`, `test:coverage`, `lint`,
  `lint:check`, `analyze`, `refactor`, `refactor:check`) — the package previously had no
  `scripts` block at all.
- `path` repositories for `robinsonryan/taxon` and `robinsonryan/vendor`, plus
  `.ddev/docker-compose.deps.yaml` mounting both siblings into the container. Without these the
  `@dev` requirements were unresolvable and the package could not be installed.
- `RobinsonRyan\Inventory\Support\ModelResolver` — validates `config('inventory.models.*')` before
  it reaches `belongsTo()`/`hasMany()`, falling back to the package default instead of passing an
  arbitrary string through.
- `RobinsonRyan\Inventory\Support\TableResolver` — validates `config('inventory.tables.*')` as a
  SQL identifier before the reporting queries interpolate it into `DB::raw()`.
- `RobinsonRyan\Inventory\Support\SchemaSupport` — applies the `uuidv7()` column default only where
  the connection actually provides that function.
- `ReportService::getReportClass()` and `ReportService::supportsReport()`, mirroring the exporter
  side. `registerReport()` previously wrote to a map that nothing could ever read.

### Fixed

- **`Stock::factory()`, `Location::factory()` and `Reservation::factory()` were unusable.** The
  three factories under `database/factories/Stock/` declared
  `RobinsonRyan\Inventory\Stock\Database\Factories`, which PSR-4 can never autoload from that path.
  Corrected to `RobinsonRyan\Inventory\Database\Factories\Stock`, matching every other module.
- **`PurchaseOrderService::getOpenForVendor()` and `ReconciliationService::getOpenCounts()` threw at
  runtime.** Both passed an array of statuses to taxon's `withTagIn()`, whose signature is
  `(string $category, string $value)`. Now use `withAnyTagIn()`, which takes an array.
- **`ReconciliationService::getCountsRequiringApproval()` passed `'pending'` into `withTag()`'s
  `?Scope` parameter.** Now uses `withTagIn($category, 'pending')`.
- **`DemandAnalyzer::getConsumptionByPeriod()` emitted MySQL-only `DATE_FORMAT()` SQL** in a package
  that runs on PostgreSQL and tests on SQLite. Replaced with a driver-aware expression
  (`to_char` on pgsql, `strftime` on sqlite, `DATE_FORMAT` elsewhere). Only the sqlite branch is
  covered by a test — see `QUEUE.md`.
- **Every migration was unrunnable outside PostgreSQL 18.** All 20 table migrations defaulted their
  primary key to `DB::raw('uuidv7()')`, a PG18 built-in — so they failed on SQLite, on MySQL, and on
  the package's own PostgreSQL 17 container. The default is now applied only when the connection
  exposes the function; the `HasUuid7` trait already generates keys in PHP regardless.
- Tag attributes (`status`, `type`) were documented `@property-read` on five models despite being
  writable through taxon's `setAttribute()`.
- `CreateItem`/`UpdateItem` guarded `$data->category !== null` on a non-nullable property.
- `AccountingExporter::extractDate()`/`buildMemo()` re-tested `!== null` after `isset()`.
- `ReorderCalculator` and `ReconciliationService` used `?->` on the left of `??`.
- `DemandAnalyzer::calculateAverageDailyUsage()`/`calculateTrend()` accepted `?int $days` and then
  passed it to `subDays()` and divided by it; `null` now falls back to the documented default.
- Decimal-cast columns (`quantity_cancelled`, `quantity_received`, `counted_quantity`, `variance`)
  were assigned `float` where the model contract declares `string`.
- `Carbon\Carbon::now()` was assigned to properties typed `Illuminate\Support\Carbon`.

### Changed

- **BREAKING (contract now enforced): `ReportData::$rows` really is a collection of arrays.**
  `ReportData` has always declared `Collection<int, array<string, mixed>>`, but all six reports
  passed raw `stdClass` rows and every exporter cast defensively with `(array) $row`. The reports
  now normalise once, at the query boundary. Code reading `$data->rows->first()->item_sku` must move
  to `$data->rows->first()['item_sku']`; the exporters are unaffected and still accept either shape.
- **BREAKING: `CompleteCounting::__construct()` no longer takes a `ReconciliationService`.** The
  dependency was injected and never read. Resolve the action from the container as before.
- `Receipt` no longer uses `HasFactory`; no `ReceiptFactory` exists and `Receipt::factory()` has
  always been fatal.
- `AccountingExporter::hasColumn()` removed — it was called twice into discarded variables and
  never used. See `QUEUE.md` for the formatting feature it was meant to drive.
- Minimum PHP is now `^8.2` (was `^8.3`), per harness decision D-C. Native typed class constants
  (`public const array …`) were untyped to match.

### Known issues

- **The test suite does not pass: 134 of 480 tests fail.** All failures predate this work — the
  package had never been installed, so the suite had never run. `composer quality` therefore exits
  non-zero. `QUEUE.md` has the full breakdown; the single highest-leverage fix is seeding each
  `TagDefinition`'s `DEFAULT_VALUES`, which nothing currently does.
