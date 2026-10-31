# Changelog

All notable changes to `robinsonryan/laravel-inventory` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.2] - 2026-10-31

### Added

- `NextPartSku` makes the `P-######` numbering public, so a surface that creates a part with no
  vendor item takes the same sequence and the same step past typed skus. `CreatePartFromVendorItem`
  uses it

## [0.4.1] - 2026-10-31

### Changed

- **`RejectMatch` no longer writes a placeholder proposal.** A vendor item left with nothing open or
  confirmed queues through its newest rejected row, so `PartMatch::scopeNeedsReview()` can hand back
  a row with `status` `vetoed` and the rejected `item_id`. Such a row has nothing to confirm: a
  surface should ask for a part, not offer Confirm, which is a person reinstating the rejection
- `ProposeMatch` gives each pairing its own row and no longer fills an open new-part suggestion when
  a part is proposed, so a proposal after a rejection keeps its own `created_at` and `method`, and a
  new-part suggestion is not overwritten
- `ConfirmMatch` always answers a proposal with no part by rule, even when a person confirmed

### Fixed

- A veto the rule made is no longer treated as a person's rejection: once the match that answered a
  new-part suggestion is rejected, the suggestion can be made again instead of being discarded.
  `PartMatch::isVetoedByRule()` says which it was
- A vendor item no longer queues twice for a new-part suggestion and a part suggestion in either
  order: the new-part suggestion waits behind the open part and comes forward if the part is
  rejected. Rival parts still queue one by one
- `ProposeClassification` and `ConfirmClassification` refuse a tag that does not belong to the axis,
  so a tag from outside the parts tree can no longer become a part's category
  (`ClassificationAxis::assertHolds()`, `PartFitmentDefinition::contains()`)
- Confirming a category name two branches share raises `CannotClassifyException::ambiguousCategory()`
  instead of creating a third category, for a person as well as the rule.
  `isAmbiguousCategory()` tells a surface to ask which and resend the choice as a tag, and
  `PartCategoryDefinition::named()` returns the candidates
- A classification's retained proposal name no longer overrides an explicit pick of a different tag
  with the same name: `PartClassification::isFor()` compares tags whenever both sides have one
- `$item->fitment = x` and `$item->partCategory = x` now raise `CannotClassifyException`, as does any
  other route through `setAttribute()`, including on an unsaved item. Assigning fitment used to wipe
  the other makes and leave their confirmed rows standing. Reading both attributes is unchanged

## [0.4.0] - 2026-10-31

### Added

- **Part matching.** New `RobinsonRyan\Inventory\Matching` module linking a vendor's part number
  to AFWD's own part. `part_matches` holds proposed, confirmed and vetoed links, and two partial
  unique indexes make the database refuse a second confirmed match for a vendor item, or a second
  open proposal for one pairing. `ProposeMatch`, `ConfirmMatch` and `RejectMatch` own the state:
  no actor means by rule, a rejection deletes nothing, a rejected pairing stays rejected across
  re-posts, only a person can reinstate it or swap a confirmed part, and an inactive part is
  refused. `PartMatch::scopeNeedsReview()` is the review queue. Refusals raise
  `CannotConfirmMatchException`
- `CreatePartFromVendorItem` makes a part numbered `P-######` from the new `part_sku_seq` Postgres
  sequence, stepping past any sku already taken
- **Fitment and part category.** `PartFitmentDefinition` (`part-fitment`: Ford, GM, Nissan,
  multi-valued) and `PartCategoryDefinition` (`part-category`, a tree through taxon's `parent_id`),
  separate from the flat `item-category`. `part_classifications` carries their proposed, confirmed
  and vetoed state and is the history of what a part used to be; only a confirmed row becomes a
  tag. `ProposeClassification`, `ConfirmClassification` and `RejectClassification` own it: a new
  category never confirms by rule, a confirmed category replaces the last one, and the rule cannot
  replace a person's choice. Refusals raise `CannotClassifyException`
- `Item` gains `partCategory`, `fitment`, `partCategoryTag()`, `fitmentValues()`, `fitsAllMakes()`
  and `scopeWithFitment()`
- `ManageCategories`: create, rename, move and merge. A merge moves parts and classification rows,
  moves children under the survivor, merges children whose names collide, and returns how many
  parts moved, all in one transaction. Refusals raise `CannotManageCategoryException`
- `PartCategoryInstaller::seed()` and `config('inventory.part_categories')`, the starting tree. The
  host seeds it from its own migration; nothing here does
- **`part_price_history`**, a view with one row per stock line, excluding credit memos. `item_id`
  comes from the confirmed match only, so rejecting a match takes the lines off the part and leaves
  them on the vendor item. `PartPriceHistory` is its read-only model
- `TableResolver::vendor()`, validating a `robinsonryan/vendor` table name for raw SQL

### Changed

- **BREAKING: `robinsonryan/vendor` is `^0.3.0`, and `robinsonryan/taxon` is `^0.6.0`.** Vendor 0.3
  brings `robinsonryan/hey-you`, which a consumer has to list as a `vcs` repository too, and
  requires taxon 0.6 in any case. A consuming application's test case needs
  `HeyYouServiceProvider` registered, because creating a vendor now writes a party
- `Item::$tagAttributes` gains `partCategory` and `fitment`. `category` is unchanged

## [0.3.0] - 2026-09-15

### Changed

- **BREAKING: PostgreSQL 18+ generates every primary key; the package no longer mints them in PHP.**
  `HasUuid7` drops its `newUniqueId()` / `uniqueIds()` / `getIncrementing(): false` shape for the
  canonical initializer (`$incrementing = true`, `$keyType = 'string'`), so Eloquent reads the
  `uuidv7()` column default back through `INSERT ... RETURNING`. `TagValueInstaller` no longer
  stamps ids on the tag rows it inserts either; Taxon's own column default covers them. The create
  migrations already carried the default, so no upgrade migration is needed here — but a host on a
  Postgres without `uuidv7()` now fails on its first insert instead of silently getting PHP keys
- **BREAKING: `robinsonryan/taxon` is `^0.5.1|^0.6.0` and `robinsonryan/vendor` is `^0.2.0`**, both
  consumed by tag from GitHub (`vcs` repositories) instead of local `path` repositories. The DDEV
  mount that served the path repositories (`.ddev/docker-compose.deps.yaml`) is gone

### Added

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
- **A reservation refused for fragmented stock no longer escapes the Jobs integration as an
  uncaught exception, and no longer claims a shortage that does not exist.** A reservation is held
  against a single stock record, so a quantity spread over two locations cannot back one — but the
  Jobs layer checked availability in aggregate and then called `reserve()`, which needs one record.
  `ReserveOnJobCreated`, `ReserveForJob` and `UpdateJobReservations` now catch the refusal and
  dispatch `InsufficientStock` for that line, leaving lines already reserved in place. The refusal
  message names the fullest location instead of reporting a shortage — "60 units of item … are not
  available in a single location. Largest available: 50 at Main Warehouse." Splitting a reservation
  across locations remains out of scope; see `QUEUE.md`.
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

### Fixed

- **Two people reserving the same units at the same moment were both told yes.**
  `ReservationService::reserve()` chose a stock record and then raised its `reserved_quantity` with
  nothing holding the record in between, so two simultaneous callers read the same unreserved
  remainder and each added their hold to it: a shelf of 100 ended up with 120 held against it, and
  no error was raised anywhere. The same gap let two callers fulfil one reservation — both passed
  the "still holding stock" guard, both consumed, and 60 units left a shelf of 100 twice, leaving a
  quantity of −20. The candidate search now locks the record it is about to charge
  (`SELECT … FOR UPDATE`), and every closing path locks the reservation as it re-reads it. The
  second caller waits, sees what the first actually did, and is refused — or, when another record
  can still cover the request, is quietly served from that one instead. Reservation locks are always
  taken before stock locks, so there is no reservation-stock cycle for two callers to wait around.
  Covered by `tests/Concurrency/ReservationConcurrencyTest.php`, which drives a second process
  against a committed fixture; each of the three locks was verified by deleting it on its own and
  watching a test go red. On SQLite, where `lockForUpdate()` compiles to nothing, safety rests on
  that engine's single writer rather than on this SQL.
- **A reservation refused because someone else got there first quoted the availability it saw
  before waiting.** The refusal now measures again after the candidate search, and reports a
  shortage rather than a fragmented-stock message when the total no longer covers the request.
- **The `locations` table could not be created on PostgreSQL.** `SchemaSupport::uuidPrimary()`
  declared the primary key with the fluent `->primary()` column modifier, which Laravel converts to
  a command only at compile time — after every foreign key the migration declared. `locations`
  declares a self-referencing `parent_id` foreign key in the same `Schema::create()`, and PostgreSQL
  refused it ("there is no unique constraint matching given keys for referenced table"). Now calls
  `$table->primary($column)` explicitly so the key is emitted first. SQLite never surfaced this.
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

### Known issues

- **Only reservations are concurrency-safe.** `StockService`'s add, remove, transfer and adjust
  paths still read a stock record, decide, and write without locking it, so two simultaneous
  removals can each pass the availability check and overdraw the shelf. Purchasing receipts and
  cycle-count adjustments have the same shape. `canReserve()` remains advisory by design: a host
  that asks it and then calls `reserve()` can still lose the stock in between — `reserve()` is the
  only safe gate.
- **Host apps should retry on deadlock, and should set their own `lock_timeout`.** The reservation
  locking removes the silent miscount, not every way two writers can collide. There is no
  reservation-stock cycle — reservations are always locked first, and `reserve()` never wants a
  reservation lock — but stock-stock cycles are still reachable in two places: `expireOld()` racing
  a fulfilment at a location split across lots, and two hosts reserving the same several items in
  opposite order inside one transaction. PostgreSQL detects both and aborts one transaction with a
  deadlock error, so the outcome is a loud, retryable failure rather than a number that has quietly
  stopped being true. Wrap multi-item and lot-split writes in a retry. Separately, the package
  imposes no lock wait limit anywhere: a request queued behind `expireOld()` waits indefinitely.
  Set a `lock_timeout` on the connections that serve requests, sized to the host's own latency
  budget — the package deliberately does not pick one for you.

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
