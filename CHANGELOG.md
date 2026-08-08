# Changelog

All notable changes to `robinsonryan/laravel-inventory` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- `RobinsonRyan\Inventory\Exceptions\CannotReserveException` — the refusal `reserve()` raises,
  carrying the item, the quantity asked for, the quantity available across all locations, and (when
  the stock exists but is spread out) the fullest single location by id, name and quantity. It
  extends `InvalidArgumentException`, so code catching the old type is unaffected.
- `InsufficientStock` now carries `largestLocationQuantity`, `largestLocationId`,
  `largestLocationName` and an `isFragmented()` test. Two situations reach a host app through this
  event and they call for opposite responses: no stock anywhere (reorder) versus enough stock in
  more than one place (consolidate). `availableQuantity` still means the sum across all locations.

### Changed

- **The DDEV database moved from PostgreSQL 17 to 18**, matching every other Postgres project in
  this fleet (taxon, hey-you, permixion, four-corners, yikes-hub, ryanbot). 17 was the reason the
  `uuidv7()` column defaults could not apply.
- **The test suite runs against real PostgreSQL** (the DDEV `db` service, on its own `testing`
  database created by a `.ddev/config.yaml` post-start hook) instead of in-memory SQLite, matching
  the stack convention. `INVENTORY_TEST_DB_HOST` / `_PORT` / `_DATABASE` / `_USERNAME` / `_PASSWORD`
  override the connection. Migration directories are now registered with the migrator rather than
  loaded through Testbench's `loadMigrationsFrom()`, which rebuilt the whole schema around every
  test.

### Fixed

- **The `locations` table could not be created on PostgreSQL.** `SchemaSupport::uuidPrimary()`
  declared the primary key with the fluent `->primary()` column modifier, which Laravel converts to
  a command only at compile time — after every foreign key the migration declared. `locations`
  declares a self-referencing `parent_id` foreign key in the same `Schema::create()`, and PostgreSQL
  refused it ("there is no unique constraint matching given keys for referenced table"). Now calls
  `$table->primary($column)` explicitly so the key is emitted first. SQLite never surfaced this.
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
  that runs on PostgreSQL. Replaced with a driver-aware expression (`to_char` on pgsql, `strftime`
  on sqlite, `DATE_FORMAT` elsewhere). Only the pgsql branch is covered by a test — see `QUEUE.md`.
- **The DDEV database was PostgreSQL 17 while every migration was written for 18.** All 20 table
  migrations default their primary key to `DB::raw('uuidv7()')`, a PostgreSQL 18 built-in, and every
  other Postgres project in this fleet runs 18. DDEV is now on 18 and the default is applied for
  real — that branch had never executed. The default is applied only when the connection
  exposes the function; the `HasUuid7` trait already generates keys in PHP regardless.
- **A reservation's own hold blocked its own fulfilment.** `fulfill()` consumed the stock before
  returning the hold, and the availability check inside stock removal subtracts
  `reserved_quantity` — so any reservation larger than the *unreserved* remainder could never be
  fulfilled. With 100 on hand, reserving 60 and fulfilling it failed with "Insufficient stock.
  Requested: 60.0000, Available: 40.0000", and a job consuming its own reserved parts failed with
  it. The hold is now returned first, inside the same transaction, so a failed removal restores it.
  `partialFulfill()` had the same ordering and the same fix.
- **Expired reservations escaped the guards and their stock was handed back twice.** The expiry
  sweep returns the held quantity and sets the status to `expired`, but writes no timestamp, and
  `release()`/`fulfill()` tested only `fulfilled_at` and `cancelled_at`. Releasing an expired
  reservation drove `reserved_quantity` to −25 and reported 125 available against 100 on hand;
  fulfilling one consumed stock that had already been returned. The same sweep run twice returned
  the same hold twice. All four closing paths now share one "is this reservation still holding
  stock" check, which reads the stored row and weighs status alongside both timestamps.
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
- **A reservation refused for fragmented stock no longer escapes the Jobs integration as an
  uncaught exception, and no longer claims a shortage that does not exist.** A reservation is held
  against a single stock record, so a quantity spread over two locations cannot back one — but the
  Jobs layer checked availability in aggregate and then called `reserve()`, which needs one record.
  `ReserveOnJobCreated`, `ReserveForJob` and `UpdateJobReservations` now catch the refusal and
  dispatch `InsufficientStock` for that line, leaving lines already reserved in place. The refusal
  message names the fullest location instead of reporting a shortage — "60 units of item … are not
  available in a single location. Largest available: 50 at Main Warehouse." Splitting a reservation
  across locations remains out of scope; see `QUEUE.md`.
- `Receipt` no longer uses `HasFactory`; no `ReceiptFactory` exists and `Receipt::factory()` has
  always been fatal.
- `AccountingExporter::hasColumn()` removed — it was called twice into discarded variables and
  never used. See `QUEUE.md` for the formatting feature it was meant to drive.
- Minimum PHP is now `^8.2` (was `^8.3`), per harness decision D-C. Native typed class constants
  (`public const array …`) were untyped to match.

- **`receipt_lines` no longer carries `received_quantity` or `unit_cost`.** A receipt line is the
  receiving desk's record of a delivery — what turned up, how much, and where it went — so its
  `quantity` *is* the received quantity, and a second count restated the same fact. The unit cost
  restated a third: price is agreed on the purchase order line, which the receipt line already
  references, and receiving staff have no way to know it. `PurchaseOrderReport` now values a
  delivery by joining the counted quantity to `purchase_order_lines.unit_cost`, so received value
  reports real figures instead of the zeros the unwritten columns produced. The
  ordered-versus-received reconciliation continues to live on the order line
  (`quantity` vs `quantity_received`). Receiving inspection — where an accepted count legitimately
  differs from a claimed one — is a feature, not these columns; see `QUEUE.md`.

- **BREAKING: `config('inventory.tenant.enabled')` and `config('inventory.tenant.scoped')` are
  gone.** Nothing in `src/` ever read either one, and the `scoped` map was wrong besides — it
  listed `items` as global while the items table carries a tenant column and the model stamps it.
  Setting `enabled => false` never disabled anything; it produced NOT NULL violations, because ten
  tables declare `tenant_id NOT NULL`. Only `tenant.column` remains. A host application must bind a
  `TenantResolver`; a single-tenant host binds one returning a constant.
- **The package's position on tenancy is now stated rather than implied: it records tenant
  ownership and does not enforce tenant isolation.** `BelongsToTenant`'s docblock claimed to add a
  global scope for tenant filtering and never added one. It now says what it does. Reads are
  unfiltered, and the reporting module queries tables directly. Hosts needing isolation have
  `scopeForTenant()`, their own global scopes, or a database per tenant. This is a deliberate
  decision, with the reasoning recorded in `CLAUDE.md` and the implementation shape in `QUEUE.md`.
- **First-party requirements corrected to versions that exist**: `robinsonryan/taxon` `^2.0` →
  `^0.3.0` and `robinsonryan/vendor` `^1.2` → `^0.1.1`, following the reversioning that took every
  first-party package below 1.0. Neither major had ever been released, so both requirements matched
  nothing and the package resolved only through the sibling path repositories.
- **`illuminate/*` raised to `^12.0|^13.0`** (was `^11.0|^12.0|^13.0`). `robinsonryan/taxon`
  requires `^12.0` minimum, so Laravel 11 support was a promise nothing could keep.

### Known issues

- **Reservation writes are not concurrency-safe.** `reserve()` checks availability and then
  increments without locking the stock row, and `fulfill()` re-reads inside its transaction without
  `lockForUpdate`. Two simultaneous reservations can over-commit the same stock on PostgreSQL or
  MySQL. Invisible on SQLite, which is what the suite runs on.
