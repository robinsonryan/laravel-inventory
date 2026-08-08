<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Exceptions\CannotReserveException;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;
use RobinsonRyan\Inventory\Tests\Support\CompetingWriter;
use Symfony\Component\Uid\Uuid;

/**
 * Two warehouse terminals, one shelf.
 *
 * These tests describe the failure that has no error message: two people reserve
 * or consume the same units at the same moment, both are told yes, and the stock
 * figures come out wrong — a hold larger than the shelf, or units removed twice.
 * Nothing throws. The numbers simply stop meaning anything.
 *
 * They are the only tests in the package that run outside the per-test
 * transaction (see ConcurrencyTestCase) and the only ones that open a second
 * connection, because a race is invisible from inside a single uncommitted
 * transaction. Everything written here is committed for real and torn down by
 * hand in `afterEach`.
 */

/**
 * The connection details and table names the competing process needs.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function competingWriterPayload(array $overrides): array
{
    /** @var array<string, mixed> $connection */
    $connection = DB::connection()->getConfig();

    return array_merge([
        'host' => $connection['host'],
        'port' => $connection['port'],
        'database' => $connection['database'],
        'username' => $connection['username'],
        'password' => $connection['password'],
        'stock_table' => config('inventory.tables.stock', 'stock'),
        'reservations_table' => config('inventory.tables.reservations', 'reservations'),
        // Long enough that this process reaches its blocking statement while the
        // competitor still holds the row, short enough not to pad the suite.
        'hold_ms' => 1_500,
    ], $overrides);
}

beforeEach(function (): void {
    $this->reservations = App::make(ReservationService::class);
    $this->itemId = (string) Uuid::v7();
    $this->locationIds = [];
});

afterEach(function (): void {
    // Nothing rolls back here, so every row this test committed has to go. The
    // item id is unique per test, which is what makes the sweep precise.
    $reservationIds = Reservation::query()
        ->where('item_id', $this->itemId)
        ->pluck('id')
        ->all();

    DB::table(config('taxon.tables.taggables', 'taggables'))
        ->whereIn('taggable_id', $reservationIds)
        ->delete();

    Reservation::query()->where('item_id', $this->itemId)->delete();
    StockMovement::query()->where('item_id', $this->itemId)->delete();
    Stock::query()->where('item_id', $this->itemId)->delete();
    Location::query()->whereIn('id', $this->locationIds)->delete();
});

/**
 * A location this test is responsible for cleaning up.
 */
function scratchLocation(object $test): Location
{
    $location = Location::factory()->create();

    $test->locationIds[] = $location->id;

    return $location;
}

it('refuses the second of two simultaneous reservations rather than promising stock twice', function (): void {
    $location = scratchLocation($this);

    $stock = Stock::factory()
        ->forItem($this->itemId)
        ->forLocation($location)
        ->withQuantity(100)
        ->withReserved(0)
        ->create();

    // Another terminal has already claimed 60 of the 100 and has not finished
    // its transaction. Its hold is invisible to anyone reading without a lock.
    $competitor = CompetingWriter::start(competingWriterPayload([
        'mode' => 'reserve',
        'stock_id' => $stock->id,
        'quantity' => 60,
    ]));

    $competitor->awaitLock();

    // The competitor still holds the row, so the attempt below is genuinely
    // contended. See CompetingWriter::hasCommitted().
    expect($competitor->hasCommitted())->toBeFalse();

    $attempt = fn (): Reservation => $this->reservations->reserve(new ReservationData(
        itemId: $this->itemId,
        jobType: 'work_order',
        jobId: 'WO-CONCURRENT-1',
        quantity: 60,
        locationId: $location->id,
    ));

    expect($attempt)->toThrow(CannotReserveException::class);

    $competitor->finish();

    $stock->refresh();

    expect((float) $stock->reserved_quantity)->toBe(60.0)
        ->and((float) $stock->reserved_quantity)->toBeLessThanOrEqual((float) $stock->quantity)
        ->and(Reservation::query()->where('item_id', $this->itemId)->count())->toBe(0);
});

it('falls through to the next stock record when a competitor empties the first', function (): void {
    $first = scratchLocation($this);
    $second = scratchLocation($this);

    $records = collect([$first, $second])
        ->map(fn (Location $location): Stock => Stock::factory()
            ->forItem($this->itemId)
            ->forLocation($location)
            ->withQuantity(100)
            ->withReserved(0)
            ->create())
        // reserve() walks candidates in primary-key order, so decide which record
        // the competitor takes by reading that order rather than assuming it.
        ->sortBy('id')
        ->values();

    $taken = $records->first();
    $remaining = $records->last();

    $competitor = CompetingWriter::start(competingWriterPayload([
        'mode' => 'reserve',
        'stock_id' => $taken->id,
        'quantity' => 100,
    ]));

    $competitor->awaitLock();

    expect($competitor->hasCommitted())->toBeFalse();

    $reservation = $this->reservations->reserve(new ReservationData(
        itemId: $this->itemId,
        jobType: 'work_order',
        jobId: 'WO-CONCURRENT-2',
        quantity: 60,
    ));

    $competitor->finish();

    $taken->refresh();
    $remaining->refresh();

    expect($reservation->stock_id)->toBe($remaining->id)
        ->and((float) $taken->reserved_quantity)->toBe(100.0)
        ->and((float) $remaining->reserved_quantity)->toBe(60.0);
});

it('refuses to consume a reservation another connection is already consuming', function (): void {
    $location = scratchLocation($this);

    $stock = Stock::factory()
        ->forItem($this->itemId)
        ->forLocation($location)
        ->withQuantity(100)
        ->withReserved(60)
        ->create();

    $reservation = Reservation::factory()
        ->forStock($stock)
        ->withQuantity(60)
        ->create();

    // Another terminal is part-way through fulfilling this exact reservation:
    // the row is closed and the units are off the shelf, uncommitted.
    $competitor = CompetingWriter::start(competingWriterPayload([
        'mode' => 'fulfill',
        'stock_id' => $stock->id,
        'reservation_id' => $reservation->id,
        'quantity' => 60,
    ]));

    $competitor->awaitLock();

    expect($competitor->hasCommitted())->toBeFalse();

    expect(fn (): StockMovement => $this->reservations->fulfill($reservation))
        ->toThrow(InvalidArgumentException::class, 'Reservation has already been fulfilled.');

    $competitor->finish();

    $stock->refresh();

    // Sixty units left the shelf once, not twice, and the hold came back once.
    expect((float) $stock->quantity)->toBe(40.0)
        ->and((float) $stock->reserved_quantity)->toBe(0.0)
        ->and(StockMovement::query()->where('item_id', $this->itemId)->count())->toBe(0);
});

it('leaves an expiring hold alone when another connection is already releasing it', function (): void {
    $location = scratchLocation($this);

    $stock = Stock::factory()
        ->forItem($this->itemId)
        ->forLocation($location)
        ->withQuantity(100)
        ->withReserved(60)
        ->create();

    // Past its expiry and still holding stock: exactly what the sweep looks for.
    $reservation = Reservation::factory()
        ->forStock($stock)
        ->withQuantity(60)
        ->expiresIn(-1)
        ->reserved()
        ->create();

    // Another connection is part-way through cancelling this same reservation:
    // the row is closed and the hold given back, uncommitted.
    $competitor = CompetingWriter::start(competingWriterPayload([
        'mode' => 'release',
        'stock_id' => $stock->id,
        'reservation_id' => $reservation->id,
        'quantity' => 60,
    ]));

    $competitor->awaitLock();

    expect($competitor->hasCommitted())->toBeFalse();

    // The sweep has its own lock, and it is the only thing standing here: the
    // sweep never goes through the locked re-read in syncFromStorage(), so
    // without it both this and the release read the reservation as open, both
    // pass closedState(), and the same sixty units are handed back twice.
    $expired = $this->reservations->expireOld();

    $competitor->finish();

    $stock->refresh();

    // The hold came back once. Handed back twice it lands at −60: a shelf of 100
    // with a negative hold against it, and nothing raised.
    expect((float) $stock->reserved_quantity)->toBe(0.0)
        ->and($expired)->toBe(0)
        ->and($reservation->fresh()?->status)->not->toBe('expired');
});
