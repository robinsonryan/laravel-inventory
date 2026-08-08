<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Contracts\ReservationContract;
use RobinsonRyan\Inventory\Exceptions\CannotReserveException;
use RobinsonRyan\Inventory\Stock\Data\RemoveStockData;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final readonly class ReservationService implements ReservationContract
{
    /**
     * The statuses that mean a reservation has already given its hold back.
     */
    private const CLOSED_STATUSES = ['fulfilled', 'cancelled', 'expired'];

    /**
     * What to say when a closed reservation is asked to give up stock again.
     */
    private const CLOSED_MESSAGES = [
        'fulfilled' => 'Reservation has already been fulfilled.',
        'cancelled' => 'Cannot fulfill a cancelled reservation.',
        'expired' => 'Cannot fulfill an expired reservation.',
    ];

    public function __construct(
        private StockService $stockService,
    ) {}

    /**
     * Create a reservation for an item.
     *
     * Always binds the reservation to a stock record and raises that record's
     * reserved quantity. Throws when nothing can back it — a reservation that
     * holds no stock reduces no availability and can never be fulfilled.
     *
     * @throws CannotReserveException when the item has too little available stock
     *                                in total, or when the stock exists but no
     *                                single location holds enough of it
     */
    public function reserve(ReservationData $data): Reservation
    {
        return DB::transaction(function () use ($data): Reservation {
            // A fast path, not the safety check: refusing here keeps a request
            // that cannot possibly succeed from taking `FOR UPDATE` locks that
            // other callers would then queue behind. The decision that actually
            // holds is the re-measure after the candidate search, under the lock.
            // Both raise the same exception, which makes this look redundant — if
            // one of the two ever has to go, it is this one, never that one.
            $available = $this->stockService->getAvailable($data->itemId, $data->locationId);

            if ($available < $data->quantity) {
                throw CannotReserveException::notEnoughStock(
                    $data->itemId,
                    $data->quantity,
                    $available,
                );
            }

            $expiryHours = $data->expiryHours ?? config('inventory.rules.reservation_expiry_hours', 72);
            $expiresAt = Carbon::now()->addHours($expiryHours);

            // Find the stock record to reserve from. A reservation holds exactly one
            // stock record (`reservations.stock_id`), so total availability is not
            // enough on its own — one record has to cover the whole quantity.
            $stock = $this->findAvailableStock($data->itemId, $data->quantity, $data->locationId);

            if (! $stock instanceof Stock) {
                // Measure availability again rather than reusing the figure from
                // the top of this method. Reaching here means either the stock is
                // spread too thin, or another connection took it while this one
                // was waiting for the row lock — and in the second case the number
                // read a moment ago describes a warehouse that no longer exists.
                // A refusal that quotes it would be exactly the confidently wrong
                // number the lock is here to prevent.
                throw $this->refuse($data, $this->stockService->getAvailable($data->itemId, $data->locationId));
            }

            /** @var Reservation */
            $reservation = $this->getReservationModel()::query()->create([
                'item_id' => $data->itemId,
                'stock_id' => $stock->id,
                'location_id' => $data->locationId ?? $stock->location_id,
                'job_type' => $data->jobType,
                'job_id' => $data->jobId,
                'line_id' => $data->lineId,
                'quantity' => $data->quantity,
                'reserved_at' => Carbon::now(),
                'expires_at' => $expiresAt,
                'notes' => $data->notes,
            ]);

            $reservation->status = 'reserved';

            // Update reserved quantity on stock
            $stock->increment('reserved_quantity', $data->quantity);

            return $reservation;
        });
    }

    /**
     * Release a reservation (cancel it without consuming).
     *
     * Silent on a reservation that has already closed: releasing a second time
     * would hand the same units back a second time.
     */
    public function release(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            if (! $this->syncFromStorage($reservation) || $this->closedState($reservation) !== null) {
                return;
            }

            $this->returnHold($reservation, (float) $reservation->quantity);

            $reservation->cancelled_at = Carbon::now();
            $reservation->status = 'cancelled';
            $reservation->save();
        });
    }

    /**
     * Fulfill a reservation (consume the stock).
     *
     * @throws InvalidArgumentException when the reservation has already closed
     *                                  or has no location to consume from
     */
    public function fulfill(Reservation $reservation): StockMovement
    {
        return DB::transaction(function () use ($reservation): StockMovement {
            $this->guardCanFulfill($reservation);

            $locationId = $this->fulfilmentLocation($reservation);
            $quantity = (float) $reservation->quantity;

            // Give the hold back before consuming it. Stock removal measures
            // availability as quantity minus reserved_quantity, so a hold left
            // standing counts against the very consumption it exists to
            // guarantee — any reservation larger than the unreserved remainder
            // could never be fulfilled. Both steps share this transaction, so a
            // removal that fails puts the hold back exactly as it was.
            $this->returnHold($reservation, $quantity);

            $movement = $this->stockService->removeStock(new RemoveStockData(
                itemId: $reservation->item_id,
                locationId: $locationId,
                quantity: $quantity,
                type: 'consumption',
                referenceType: $reservation->job_type,
                referenceId: $reservation->job_id,
                notes: "Fulfilled reservation {$reservation->id}",
            ));

            $reservation->fulfilled_at = Carbon::now();
            $reservation->status = 'fulfilled';
            $reservation->save();

            return $movement;
        });
    }

    /**
     * Partially fulfill a reservation.
     *
     * @throws InvalidArgumentException when the reservation has already closed,
     *                                  has no location to consume from, or the
     *                                  quantity exceeds what is still held
     */
    public function partialFulfill(Reservation $reservation, float $quantity): StockMovement
    {
        return DB::transaction(function () use ($reservation, $quantity): StockMovement {
            $this->guardCanFulfill($reservation);

            if ($quantity > (float) $reservation->quantity) {
                throw new InvalidArgumentException('Partial quantity exceeds reservation quantity.');
            }

            $locationId = $this->fulfilmentLocation($reservation);

            // Same ordering as a full fulfilment: the consumed portion of the
            // hold is returned first so it does not block its own removal.
            $this->returnHold($reservation, $quantity);

            $movement = $this->stockService->removeStock(new RemoveStockData(
                itemId: $reservation->item_id,
                locationId: $locationId,
                quantity: $quantity,
                type: 'consumption',
                referenceType: $reservation->job_type,
                referenceId: $reservation->job_id,
                notes: "Partially fulfilled reservation {$reservation->id}",
            ));

            $remainingQuantity = (float) $reservation->quantity - $quantity;
            $reservation->quantity = $remainingQuantity;

            if ($remainingQuantity <= 0) {
                $reservation->fulfilled_at = Carbon::now();
                $reservation->status = 'fulfilled';
            } else {
                $reservation->status = 'partial';
            }

            $reservation->save();

            return $movement;
        });
    }

    /**
     * Expire old reservations past their expiry date.
     *
     * The sweep locks every reservation it is about to close, in one statement,
     * before it touches a single stock row. That is what stops it racing another
     * connection closing the same reservation: the other one is either committed
     * before the lock is granted (and then fails the `whereNull` re-check, or the
     * `closedState()` guard below) or waits for the sweep to finish. Without that
     * lock the sweep would hand the same hold back a second time — it is the only
     * closing path that does not go through `syncFromStorage()`, so nothing else
     * would catch it. `tests/Concurrency/ReservationConcurrencyTest.php` pins this
     * specifically; deleting this one `lockForUpdate()` turns that test red.
     *
     * It does *not* follow that the sweep cannot deadlock, and an earlier version
     * of this docblock wrongly said it could not. What is true is narrower: every
     * path here takes reservation locks before stock locks, and `reserve()` takes
     * stock without ever wanting a reservation, so there is no reservation-stock
     * cycle for anyone to complete. Stock-stock cycles are still reachable. The
     * sweep takes stock rows in reservation-id order, while a fulfilment takes its
     * own reservation's stock row and then whichever row `RemoveStock` picks —
     * which at a lot-split location can be a different one, because `fulfill()`
     * builds its removal with no lot number and `RemoveStock` filters on item and
     * location alone. With two lot rows in one place, the sweep can hold the
     * second and want the first while a fulfilment holds the first and wants the
     * second. PostgreSQL detects that and aborts one of the two transactions with
     * a deadlock error: a loud, retryable failure rather than the silent miscount
     * this locking exists to prevent. Fixing the ordering properly needs lot-aware
     * record selection and is queued with the still-unlocked plain stock writes,
     * which have the same two-row problem in `TransferStock`.
     *
     * The cost is honest and worth knowing: a sweep with a large backlog holds
     * every one of those rows until it commits, and fulfilments of those exact
     * reservations wait. It is a batch job; run it off the request path. Hosts
     * should also set a `lock_timeout` on the connections that do serve requests,
     * so a request queued behind this sweep fails in a bounded time instead of
     * hanging. The package sets none deliberately — the right value comes out of
     * the host's latency budget, and a library that picks one for you is a library
     * you have to fight.
     *
     * @return int Number of reservations expired
     */
    public function expireOld(): int
    {
        return DB::transaction(function (): int {
            $candidates = $this->getReservationModel()::query()
                ->whereNull('fulfilled_at')
                ->whereNull('cancelled_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', Carbon::now())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $expired = 0;

            foreach ($candidates as $reservation) {
                // A reservation already marked expired still matches the query
                // above — expiry writes no timestamp. Skipping it here is what
                // stops a second sweep returning the same hold twice.
                if ($this->closedState($reservation) !== null) {
                    continue;
                }

                $this->returnHold($reservation, (float) $reservation->quantity);

                $reservation->status = 'expired';
                $reservation->save();

                $expired++;
            }

            return $expired;
        });
    }

    /**
     * Get all reservations for a job.
     *
     * @return Collection<int, Reservation>
     */
    public function getForJob(string $jobType, string $jobId): Collection
    {
        return $this->getReservationModel()::query()
            ->where('job_type', $jobType)
            ->where('job_id', $jobId)
            ->with(['item', 'stock', 'location'])
            ->get();
    }

    /**
     * Check if an item has sufficient available stock to reserve.
     *
     * Measures the total across every matching stock record. A reservation binds
     * to one record, so a true answer here does not guarantee `reserve()` will
     * succeed when the quantity is spread thinly across locations or lots.
     */
    public function canReserve(string $itemId, float $quantity, ?string $locationId = null): bool
    {
        $available = $this->stockService->getAvailable($itemId, $locationId);

        return $available >= $quantity;
    }

    /**
     * How a reservation was closed, or null while it is still holding stock.
     *
     * The one question every closing path asks, so that they cannot answer it
     * differently. Status carries as much weight as the two timestamps do:
     * expiry returns the hold and marks the reservation `expired` without
     * writing either timestamp, so a guard reading only `fulfilled_at` and
     * `cancelled_at` let an expired reservation be released or consumed a
     * second time — handing the same units back twice, silently and with
     * confident-looking numbers.
     */
    private function closedState(Reservation $reservation): ?string
    {
        if ($reservation->fulfilled_at !== null) {
            return 'fulfilled';
        }

        if ($reservation->cancelled_at !== null) {
            return 'cancelled';
        }

        $status = $reservation->status;

        return in_array($status, self::CLOSED_STATUSES, true) ? $status : null;
    }

    /**
     * Refuse to consume a reservation that is no longer holding stock.
     *
     * @throws InvalidArgumentException
     */
    private function guardCanFulfill(Reservation $reservation): void
    {
        if (! $this->syncFromStorage($reservation)) {
            throw new InvalidArgumentException('Reservation no longer exists.');
        }

        $closedState = $this->closedState($reservation);

        if ($closedState !== null) {
            throw new InvalidArgumentException(self::CLOSED_MESSAGES[$closedState]);
        }
    }

    /**
     * Re-read a reservation's stored state, reporting whether the row still exists.
     *
     * Callers hold instances across time: the expiry sweep closes reservations
     * on copies of its own, and a host app can easily be carrying a second
     * instance of the same row. Deciding from the instance handed in would
     * trust whatever it happened to be loaded with, so every closing path
     * re-reads first.
     *
     * The re-read locks the row, which is what makes it a decision rather than a
     * guess. Re-reading without a lock only widens the window: two callers
     * fulfilling the same reservation both read it open, both pass the guard, and
     * both consume — the units leave the shelf twice and the hold is handed back
     * twice, again with no error raised. `FOR UPDATE` makes the second caller wait
     * for the first to commit and then read what it wrote, so the guard sees the
     * reservation already closed and refuses.
     *
     * Every caller runs inside `DB::transaction`, which is what gives the lock a
     * lifetime; a fifth closing path that forgets the transaction would take a
     * lock and drop it again on the same statement. Note which paths those are:
     * `release()`, `fulfill()` and `partialFulfill()` come through here, and
     * `expireOld()` does not — it locks its whole candidate batch in its own
     * `SELECT … FOR UPDATE` instead, which is why deleting that call is not caught
     * by anything in this method.
     *
     * The lock is on the reservation only — the stock row it holds is taken later,
     * by the decrement in `returnHold()`. That order (reservation, then stock) is
     * the same in every path, and `reserve()` takes the stock row without ever
     * wanting a reservation, so there is no reservation-stock cycle. Two callers
     * can still wait on each other over two *stock* rows; `expireOld()`'s docblock
     * has the shape and why the outcome is a deadlock error rather than a silently
     * wrong number.
     */
    private function syncFromStorage(Reservation $reservation): bool
    {
        if (! $reservation->exists) {
            return false;
        }

        $stored = $this->getReservationModel()::query()
            ->whereKey($reservation->getKey())
            ->lockForUpdate()
            ->first();

        if (! $stored instanceof Reservation) {
            return false;
        }

        $reservation->setRawAttributes($stored->getAttributes(), true);
        $reservation->setRelations([]);

        return true;
    }

    /**
     * Hand a held quantity back to the stock record carrying it.
     */
    private function returnHold(Reservation $reservation, float $quantity): void
    {
        $reservation->stock?->decrement('reserved_quantity', $quantity);
    }

    /**
     * Where a reservation's stock is consumed from.
     *
     * @throws InvalidArgumentException when neither the reservation nor its
     *                                  stock record names a location
     */
    private function fulfilmentLocation(Reservation $reservation): string
    {
        $locationId = $reservation->location_id ?? $reservation->stock?->location_id;

        if ($locationId === null) {
            throw new InvalidArgumentException('Cannot fulfill reservation without a location.');
        }

        return $locationId;
    }

    /**
     * The refusal to raise when no single stock record can back the reservation.
     *
     * Usually that means the stock exists but sits in more than one place, and
     * then the refusal reports the fullest single location by name — that is what
     * tells a host app this is a consolidation problem and not a reordering one.
     * Two cases are shortages wearing a fragmentation costume, and both say so
     * instead: no stock record at all, and a total that no longer covers the
     * request because another connection reserved it first.
     *
     * @param  float  $available  measured across every matching record, after the
     *                            candidate search has finished
     */
    private function refuse(ReservationData $data, float $available): CannotReserveException
    {
        if ($available < $data->quantity) {
            return CannotReserveException::notEnoughStock($data->itemId, $data->quantity, $available);
        }

        $largest = $this->largestAvailableStock($data->itemId, $data->locationId);

        // No stock record at all means there is nothing to consolidate, so the
        // honest refusal is a shortage after all.
        if (! $largest instanceof Stock) {
            return CannotReserveException::notEnoughStock($data->itemId, $data->quantity, $available);
        }

        return CannotReserveException::notAvailableInOneLocation(
            $data->itemId,
            $data->quantity,
            $available,
            (float) $largest->available_quantity,
            $largest->location_id,
            $this->describeLocation($largest),
        );
    }

    /**
     * A human-readable name for the location a stock record sits in.
     *
     * Lot- and serial-tracked stock splits one location across several records,
     * and a reservation is held against a record rather than a location, so the
     * lot is named too where there is one. Saying "50 at Main Warehouse" when
     * the reservable amount is 50 of one lot out of 80 on the shelf would be
     * the same kind of confident wrong number this refusal exists to avoid.
     */
    private function describeLocation(Stock $stock): string
    {
        $name = $stock->location->name;

        return $stock->lot_number === null ? $name : "{$name} (lot {$stock->lot_number})";
    }

    /**
     * Find a single stock record with enough available quantity to cover a reservation.
     *
     * The comparison casts the bound quantity explicitly. PDO sends a PHP float as a
     * string, and `quantity - reserved_quantity` is an expression with no column
     * affinity, so on SQLite the comparison would otherwise be number-against-text —
     * which is always false, silently matching nothing. Do not drop the CAST.
     *
     * PostgreSQL resolves the untyped binding to numeric on its own, so this suite
     * — which runs on PostgreSQL — would stay green without the cast. The cast is
     * here for the host applications that run this package on SQLite, and nothing
     * automated guards it. Treat it as load-bearing anyway.
     *
     * The row is locked, and that is the whole reason `reserve()` cannot
     * over-commit. Selecting a candidate and then raising its `reserved_quantity`
     * is check-then-act: unlocked, two simultaneous reservations both read the
     * same unreserved remainder, both add their hold to it, and the warehouse ends
     * up promising more units than it has — no error anywhere, just a stock row
     * whose hold exceeds its quantity. `SELECT … FOR UPDATE` closes that window:
     * the second caller waits for the first to commit, then re-evaluates the
     * availability predicate against the row the first caller actually left
     * behind. It either still qualifies, or this returns null and the caller is
     * refused. (`FOR UPDATE` re-checks the predicate after the wait; that is
     * PostgreSQL's documented behaviour and what makes the wait meaningful rather
     * than merely a delay before the same stale answer.)
     *
     * `orderBy('id')` is not cosmetic any more, but it buys less than an earlier
     * version of this docblock claimed. It makes one caller's own scan
     * deterministic — which candidate gets taken when several qualify, and which
     * one a caller falls through to when it loses the race for the first. It does
     * not fix acquisition order *between* callers: the ordering applies within one
     * item's candidate search, and a host reserving several items inside one
     * transaction issues one of these queries per item, so it takes those stock
     * locks in call order. Two hosts reserving the same items in opposite order
     * can therefore still deadlock. PostgreSQL detects it and aborts one of them,
     * so the host sees an error it can retry rather than a number it cannot check.
     * A host issuing multi-item reservations should expect deadlock errors and
     * retry the whole transaction. Keep the `orderBy('id')`, and do not reorder by
     * availability — just do not mistake it for a cross-caller guarantee.
     *
     * On SQLite `lockForUpdate()` compiles to nothing at all — the grammar emits
     * an empty string. That is tolerable rather than correct: SQLite serialises
     * writers itself, so the interleaving cannot arise, but nothing in the SQL
     * says so. Any host on a third engine should confirm its own semantics.
     */
    private function findAvailableStock(string $itemId, float $quantity, ?string $locationId = null): ?Stock
    {
        $query = $this->getStockModel()::query()
            ->where('item_id', $itemId)
            ->whereRaw('quantity - reserved_quantity >= CAST(? AS DECIMAL(20,4))', [$quantity])
            ->orderBy('id')
            ->lockForUpdate();

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        /** @var Stock|null */
        return $query->first();
    }

    /**
     * The stock record holding the most unreserved quantity, for reporting only.
     */
    private function largestAvailableStock(string $itemId, ?string $locationId = null): ?Stock
    {
        $query = $this->getStockModel()::query()
            ->where('item_id', $itemId)
            ->orderByRaw('quantity - reserved_quantity DESC')
            ->with('location');

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        /** @var Stock|null */
        return $query->first();
    }

    /**
     * @return class-string<Reservation>
     */
    private function getReservationModel(): string
    {
        return config('inventory.models.reservation', Reservation::class);
    }

    /**
     * @return class-string<Stock>
     */
    private function getStockModel(): string
    {
        return config('inventory.models.stock', Stock::class);
    }
}
