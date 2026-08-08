# Test suite repair — plan and requirements ledger

Branch: `fix/test-suite-repair` · Baseline: 134 failed / 346 passed (857 assertions)
Target: 480 passed, `composer quality` green.

## Working constraint — read first

The pre-commit hook runs the **whole gate including tests** on any `*.php` change, so
**nothing can be committed until the entire suite is green.** This is deliberate and is
not to be bypassed: `--no-verify` is forbidden and `PACKAGE_SKIP_GATE=1` is human-only.

Consequences for how this work runs:

- All builders work in **one checkout** (`.claude/worktrees/repair`), not one worktree
  each — there are no intermediate commits to merge, so per-branch isolation buys nothing.
- Conflict safety comes from **disjoint file ownership** (table below), strictly observed.
- ~~Concurrent test runs are safe: `tests/TestCase.php` gives every process its own
  in-memory SQLite database. There is no shared test database to collide on.~~
  **No longer true.** The suite now runs on the DDEV `db` service's single `testing`
  database, and every process starts by dropping and rebuilding its schema. Two suite
  runs at once will destroy each other's tables. Run one at a time, or give the second
  its own database with `INVENTORY_TEST_DB_DATABASE`.
- Markdown-only commits skip the gate, so this document can be committed as we go.

Run tests from the **main checkout**, never by `cd`-ing into the worktree:

```bash
ddev exec --dir /var/www/html/.claude/worktrees/repair "vendor/bin/pest --filter=SomeTest"
```

## Decisions taken (owner: Ryan, 2026-08-08)

**D1 — "Usage" means stock that leaves and does not come back.** The low-stock report and
the demand analyser previously disagreed: one counted movements *labelled* `consumption`,
the other counted movements whose *shape* was outflow (left a location, arrived nowhere).
They now share one definition, and it is the shape-based one. Sales, job consumption,
scrap and shrinkage all count as depletion; transfers between own locations do not.
This removes a tag lookup from a hot aggregate subquery and makes the two callers agree.

**D2 — Tag values load during installation, automatically.** These are structural values
the package cannot function without, not optional sample data, so they must not be
skippable. A migration populates them; a re-runnable console command does the same work
and is the supported way to reload after new values are added. Rationale: taxon decides
validity from the database, and a *partially* populated list is worse than an empty one —
an empty list fail-opens, a one-entry list rejects everything else.

**D3 — Report rows carry both a display label and a stable value**, named
`<field>_label` and `<field>_value` (e.g. `category_label` / `category_value`,
`status_label` / `status_value`). The spreadsheet exporter emits only *declared* columns,
so it shows the label alone; the JSON feed emits the whole row, so it carries both.
Rationale: display wording will change; integrations must not break when it does.
**This is the convention for every tag-backed field added in future.**

**D4 — Listeners depend on a `ReservationContract`, not the concrete service.** The six
service-mocking tests target the contract. The ten tests that mocked the `Reservation`
*model* become real integration tests — you do not write a contract for a database record,
and fake-based tests are what let the reserved-quantity bug below go unnoticed.
`final` stays on both classes; `pint.json` is not to be touched.

**D5 (no decision needed — recorded so it is not re-litigated).** Tag values are **global,
not per tenant.** Every definition already declares `$global = true`, which forces
`tenant_id` to null on both the parent and value tags. Tests additionally run with
`taxon.tenant.enabled = false`. Seeding is therefore a single global pass.

## Root defects (not test problems)

**R1 — Reservations never reduce available stock.** `stock.reserved_quantity` is never
incremented on reserve or decremented on release/fulfil, so available quantity always
equals on-hand. 14 failures trace here. This is the most serious defect in the package:
its core promise is knowing what stock is spoken for. Fix before anything that measures
against it.

**R2 — `receipt_lines.received_quantity` is read and written by code and tests and no
migration ever created it.**

**R3 — Tag values are never seeded.** See D2. 63 failures.

**R4 — Reports query tag attributes as if they were table columns.** 12 lines across 5
report classes. Fixed per D3 by filtering through taxon and attaching values after the
fetch. See "Foundation F3".

**R5 — `in_progress` never matches stored `in-progress`.** Taxon slugifies on write;
`CompleteCounting` and `ReconciliationService` compare against the underscored spelling,
so a cycle count can never be completed.

**R6 — The accounting exporter emits a transaction terminator for an empty report.**

## Foundation — built and verified before any builder starts

Everything below is shared substrate. Builders code against these signatures verbatim.

| ID | What | Files (foundation owns exclusively) |
|---|---|---|
| F1 | Seeding path: walks each definition's `DEFAULT_VALUES` through `TagDefinition::firstOrCreateValue()`, pre-slugified. Migration + idempotent console command + a registry listing the six definitions. Called from `tests/TestCase.php`. | `src/Support/TagValueInstaller.php`, `src/Console/SyncTagValuesCommand.php`, `database/migrations/*_seed_inventory_tag_values.php`, `tests/TestCase.php`, `src/InventoryServiceProvider.php` |
| F2 | `CycleCountStatusDefinition::DEFAULT_VALUES` `in_progress` → `in-progress`; fix the two comparisons (R5). | `src/Reconciliation/TagDefinitions/CycleCountStatusDefinition.php`, `src/Reconciliation/Actions/CompleteCounting.php`, `src/Reconciliation/Services/ReconciliationService.php` |
| F3 | Tag query support (R4/D3): `filterIdsByTagValue()` producing an ID subquery usable from `DB::table()`, and `labelsAndValuesFor()` returning `[modelId => ['label' => …, 'value' => …]]` in one query. | `src/Support/TagQuery.php` |
| F4 | `ReservationContract` (D4) — the promise listeners depend on. Interface only; `ReservationService` declares `implements`. | `src/Contracts/ReservationContract.php`, `src/Stock/Services/ReservationService.php` (signature line only) |
| F5 | Migration adding `receipt_lines.received_quantity` (R2). | `database/migrations/*_create_receipt_lines_table.php` |
| F6 | Shared depletion scope (D1): one helper defining outflow as `from_location_id NOT NULL AND to_location_id IS NULL`, consumed by both the low-stock report and the demand analyser. | `src/Support/MovementScopes.php` |

**Slug trap:** `firstOrCreateValue()` is the one taxon method that does **not** slugify its
input. Every other read and write path does. Seed pre-slugified values or they will never
match.

## File ownership — no file appears twice

| Owner | `src/` | `tests/` |
|---|---|---|
| **Foundation** (solo, first) | as listed above | `tests/TestCase.php` |
| **B1 Reporting** | `src/Reporting/**` | `tests/Feature/Reporting/**` |
| **B2 Reservations & jobs** | `src/Stock/Services/ReservationService.php`, `src/Stock/Actions/**`, `src/Jobs/**` | `tests/Feature/Jobs/**`, `tests/Feature/Stock/ReservationTest.php`, `tests/Unit/Jobs/**` |
| **B3 Tag fallout** | `src/Catalog/**`, `src/Purchasing/**`, `src/Reconciliation/**`, `src/Forecasting/**` | `tests/Feature/Catalog/**`, `tests/Feature/Purchasing/**`, `tests/Feature/Reconciliation/**`, `tests/Feature/Forecasting/**`, `tests/Feature/Stock/{StockTest,LocationTest,MovementTest}.php`, `tests/Unit/Reconciliation/**` |

Frozen — **nobody touches**: `pint.json`, `phpstan.neon`, `rector.php`, `composer.json`,
`.githooks/**`, `phpunit.xml`, `CLAUDE.md`, anything under `vendor/`.

Shared but foundation-owned: `database/factories/**` — if a builder needs a factory
change, it goes through the orchestrator, not a direct edit.

## Requirements ledger

Status: ☐ not started · ◐ in progress · ☑ done and proved by a passing test

**All rows closed 2026-08-08.** Gate verified green independently of the builders:
527 passed, 1402 assertions, `composer quality` exit 0.

| ID | Requirement | Owner | Failures | Status |
|---|---|---|---|---|
| L1 | Every declared tag value exists in the database after install | Foundation | 63 | ☑ |
| L2 | Cycle counts can be completed (`in-progress` matches) | Foundation | 3 | ☑ |
| L3 | `receipt_lines.received_quantity` exists | Foundation | 5 | ☑ |
| L4 | Reports filter by tag through taxon, never by phantom column | B1 | 30 | ☑ |
| L5 | Report rows carry `_label` and `_value` per D3 | B1 | — | ☑ |
| L6 | Tag set only after the owning record is saved | B1 | 2 | ☑ |
| L7 | Reservation report test passes a record, not an id | B1 | 1 | ☑ |
| L8 | Accounting exporter emits nothing for an empty report | B1 | 1 | ☑ |
| L9 | Reserving stock reduces available quantity; releasing restores it | B2 | 10 | ☑ |
| L10 | Fulfilling a reservation resolves its location | B2 | 3 | ☑ |
| L11 | Listeners depend on `ReservationContract`; 6 tests use it | B2 | 6 | ☑ |
| L12 | 10 reservation-model tests assert real outcomes | B2 | 10 | ☑ |
| L13 | Remaining tag-value failures pass once seeded | B3 | ~40 | ☑ |
| L14 | Receiving test uses a fixed, not random, quantity | B3 | 1 | ☑ |
| L15 | Low-stock report and demand analyser agree on depletion (D1) | B1 + B3 via F6 | — | ☑ |

**Non-goals for this branch** — recorded so they are not mistaken for omissions:

- N1 Reporting reaches directly into five other modules' tables. Real architectural
  debt; **out of scope**, queue it.
- N2 The unread `inventory.tenant.enabled` / `.scoped` config keys. Already queued.
- N3 `--tenant` accepted and ignored by the recommendations command. Already queued.
- N4 Untested pgsql/mysql branches of the period expression. Already queued.
- N5 Widening the constraint matrix to Pest 5 / PHPUnit 13 / PHP 8.4. Already queued.
- N6 Tagging a release. Never automated; Ryan cuts tags.


## Outcome

**Completed.** Every ledger row above, proved by a passing test. Plus six defects that were
never test problems and are recorded in `CHANGELOG.md` and `CLAUDE.md`: the SQLite type-affinity
trap that made reservations silently fail, a reservation's own hold blocking its fulfilment,
closed reservations returning stock twice (including the expiry sweep double-returning when run
twice), the impossible second cycle count, forecasting fixtures displaced ~450 days, and
accounting exports carrying literal `\t`.

**Deviations from the plan, all deliberate:**

- **R1 was wrong.** The plan said reserved quantity was never maintained. It always was — the
  real defect was the availability lookup returning nothing on SQLite. Corrected before the
  builder acted on it.
- **`VarianceReport` had no phantom column** (its test did), and **`PurchaseOrderReport` did** —
  the reverse of what the plan and the package docs claimed.
- **`receipt_lines` gained two columns and then lost them again.** Added so the purchase-order
  report could compute received value; removed once Ryan established that a receipt line's
  quantity *is* the received quantity and that price belongs to the order line. The report now
  joins for cost. Receiving inspection is queued as the real feature.
- **Six reports needed changing, not five.**

**Not completed, deliberately — all queued, none silently dropped:** tenant scoping (nothing in
the package is scoped; two reviewers found it independently), concurrency locking on reservation
writes, reservations spanning several stock records, receiving inspection, lot-aware stock
selection, reporting's direct reads of other modules' tables, and the unsatisfiable first-party
version constraints.

**Review pass.** Three adversarial reviewers, one per module, each briefed with a load-bearing
invariant rather than "review this". Findings were verified by mutation, not by reading: the
assertion-integrity check held (every rewritten assertion provably corrected or strictly
stronger), and two blockers plus six majors were fixed by two remediation agents, each fix
carrying a regression test observed failing first.
