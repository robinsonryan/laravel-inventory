<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Exceptions\CannotReserveException;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;
use RobinsonRyan\Inventory\Stock\Services\StockService;
use RobinsonRyan\Inventory\Stock\TagDefinitions\ReservationStatusDefinition;

beforeEach(function (): void {
    $this->stockService = App::make(StockService::class);
    $this->reservationService = App::make(ReservationService::class);
    $this->location = Location::factory()->warehouse()->create();
    $this->itemId = (string) Symfony\Component\Uid\Uuid::v7();

    // Add initial stock
    $this->stockService->addStock(new AddStockData(
        itemId: $this->itemId,
        locationId: $this->location->id,
        quantity: 100,
        cost: 10.00,
    ));
});

describe('Reservation Model', function (): void {
    it('creates a reservation', function (): void {
        $reservation = Reservation::factory()
            ->forItem($this->itemId)
            ->forLocation($this->location)
            ->withQuantity(25)
            ->forJob('work_order', 'WO-001')
            ->create();

        expect($reservation)->toBeInstanceOf(Reservation::class)
            ->and($reservation->item_id)->toBe($this->itemId)
            ->and($reservation->location_id)->toBe($this->location->id)
            ->and((float) $reservation->quantity)->toBe(25.0)
            ->and($reservation->job_type)->toBe('work_order')
            ->and($reservation->job_id)->toBe('WO-001');
    });

    it('has reservation status states', function (): void {
        $pending = Reservation::factory()->forItem($this->itemId)->pending()->create();
        expect($pending->status)->toBe('pending');

        $reserved = Reservation::factory()->forItem($this->itemId)->reserved()->create();
        expect($reserved->status)->toBe('reserved');

        $partial = Reservation::factory()->forItem($this->itemId)->partial()->create();
        expect($partial->status)->toBe('partial');

        $fulfilled = Reservation::factory()->forItem($this->itemId)->fulfilled()->create();
        expect($fulfilled->status)->toBe('fulfilled')
            ->and($fulfilled->fulfilled_at)->not->toBeNull();

        $cancelled = Reservation::factory()->forItem($this->itemId)->cancelled()->create();
        expect($cancelled->status)->toBe('cancelled')
            ->and($cancelled->cancelled_at)->not->toBeNull();

        $expired = Reservation::factory()->forItem($this->itemId)->expired()->create();
        expect($expired->status)->toBe('expired');
    });
});

describe('ReservationService - Create Reservations', function (): void {
    it('creates a reservation and updates stock', function (): void {
        $data = new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 25,
            locationId: $this->location->id,
        );

        $reservation = $this->reservationService->reserve($data);

        expect($reservation)->toBeInstanceOf(Reservation::class)
            ->and($reservation->status)->toBe('reserved')
            ->and((float) $reservation->quantity)->toBe(25.0)
            ->and($reservation->reserved_at)->not->toBeNull()
            ->and($reservation->expires_at)->not->toBeNull();

        // Check stock reserved quantity
        /** @var Stock */
        $stock = Stock::query()
            ->where('item_id', $this->itemId)
            ->where('location_id', $this->location->id)
            ->first();

        expect((float) $stock->reserved_quantity)->toBe(25.0);
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0);
    });

    it('reserves available stock only', function (): void {
        // First reservation
        $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 60,
            locationId: $this->location->id,
        ));

        // Second reservation should work
        $reservation2 = $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-002',
            quantity: 30,
            locationId: $this->location->id,
        ));

        expect($reservation2->status)->toBe('reserved');
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(10.0);
    });

    it('prevents over-reservation', function (): void {
        expect(fn () => $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 150,
            locationId: $this->location->id,
        )))->toThrow(InvalidArgumentException::class, 'Insufficient available stock');
    });

    it('binds the reservation to a stock record when no location is given', function (): void {
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-005',
            quantity: 25,
        ));

        /** @var Stock */
        $stock = Stock::query()->where('item_id', $this->itemId)->first();

        expect($reservation->stock_id)->toBe($stock->id)
            ->and($reservation->location_id)->toBe($this->location->id)
            ->and((float) $stock->reserved_quantity)->toBe(25.0);
    });

    it('reserves a fractional quantity', function (): void {
        // Covers the stock lookup's numeric comparison. Note it does not *guard*
        // the CAST in findAvailableStock(): PostgreSQL, which this suite runs on,
        // resolves the untyped binding to numeric by itself. SQLite is where the
        // cast matters, and no test here runs on SQLite.
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-006',
            quantity: 25.5,
            locationId: $this->location->id,
        ));

        /** @var Stock */
        $stock = Stock::query()->where('item_id', $this->itemId)->first();

        expect($reservation->stock_id)->toBe($stock->id)
            ->and((float) $stock->reserved_quantity)->toBe(25.5)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(74.5);
    });

    it('refuses to reserve a quantity no single location can cover', function (): void {
        // 100 units of a second item, split evenly over two locations. The total
        // covers 60; neither location does, and a reservation holds one record.
        $secondLocation = Location::factory()->warehouse()->create();
        $splitItemId = (string) Symfony\Component\Uid\Uuid::v7();

        foreach ([$this->location, $secondLocation] as $location) {
            $this->stockService->addStock(new AddStockData(
                itemId: $splitItemId,
                locationId: $location->id,
                quantity: 50,
            ));
        }

        expect($this->reservationService->canReserve($splitItemId, 60))->toBeTrue();

        expect(fn () => $this->reservationService->reserve(new ReservationData(
            itemId: $splitItemId,
            jobType: 'work_order',
            jobId: 'WO-007',
            quantity: 60,
        )))->toThrow(CannotReserveException::class);

        // Nothing half-created, and no stock left marked as spoken for.
        expect(Reservation::query()->where('job_id', 'WO-007')->count())->toBe(0)
            ->and($this->stockService->getAvailable($splitItemId))->toBe(100.0);
    });

    it('names the fullest location rather than claiming a shortage', function (): void {
        // The units are on the shelves; they are just in two places. Saying
        // "insufficient stock" here would send a host app off to reorder stock
        // it already owns.
        $secondLocation = Location::factory()->warehouse()->create();
        $splitItemId = (string) Symfony\Component\Uid\Uuid::v7();

        $this->stockService->addStock(new AddStockData(
            itemId: $splitItemId,
            locationId: $this->location->id,
            quantity: 50,
        ));

        $this->stockService->addStock(new AddStockData(
            itemId: $splitItemId,
            locationId: $secondLocation->id,
            quantity: 30,
        ));

        try {
            $this->reservationService->reserve(new ReservationData(
                itemId: $splitItemId,
                jobType: 'work_order',
                jobId: 'WO-010',
                quantity: 60,
            ));

            $this->fail('Expected the reservation to be refused.');
        } catch (CannotReserveException $refusal) {
            expect($refusal->isFragmented())->toBeTrue()
                ->and($refusal->itemId)->toBe($splitItemId)
                ->and($refusal->requestedQuantity)->toBe(60.0)
                ->and($refusal->availableQuantity)->toBe(80.0)
                ->and($refusal->largestLocationQuantity)->toBe(50.0)
                ->and($refusal->largestLocationId)->toBe($this->location->id)
                ->and($refusal->largestLocationName)->toBe($this->location->name)
                ->and($refusal->getMessage())
                ->toContain('are not available in a single location')
                ->toContain("Largest available: 50 at {$this->location->name}")
                ->not->toContain('Insufficient');
        }
    });

    it('still calls an outright shortage a shortage', function (): void {
        try {
            $this->reservationService->reserve(new ReservationData(
                itemId: $this->itemId,
                jobType: 'work_order',
                jobId: 'WO-011',
                quantity: 150,
                locationId: $this->location->id,
            ));

            $this->fail('Expected the reservation to be refused.');
        } catch (CannotReserveException $refusal) {
            expect($refusal->isFragmented())->toBeFalse()
                ->and($refusal->requestedQuantity)->toBe(150.0)
                ->and($refusal->availableQuantity)->toBe(100.0)
                ->and($refusal->largestLocationQuantity)->toBeNull()
                ->and($refusal->getMessage())->toContain('Insufficient available stock');
        }
    });
});

describe('ReservationService - Fulfill Reservations', function (): void {
    it('fulfills a reservation', function (): void {
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 25,
            locationId: $this->location->id,
        ));

        $movement = $this->reservationService->fulfill($reservation);

        $reservation->refresh();

        expect($movement)->toBeInstanceOf(StockMovement::class)
            ->and($movement->type)->toBe('consumption')
            ->and((float) $movement->quantity)->toBe(25.0);

        expect($reservation->status)->toBe('fulfilled')
            ->and($reservation->fulfilled_at)->not->toBeNull();

        // Check stock was consumed
        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(75.0);
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0);
    });

    it('fulfills a reservation bigger than the unreserved remainder', function (): void {
        // The hold itself must not stand in the way of consuming it. With 100 on
        // hand and 60 held, only 40 is unreserved — a fulfilment that measured
        // availability with its own hold still counted would refuse every
        // reservation larger than what is left over.
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-008',
            quantity: 60,
            locationId: $this->location->id,
        ));

        $movement = $this->reservationService->fulfill($reservation);

        $reservation->refresh();

        expect((float) $movement->quantity)->toBe(60.0)
            ->and($reservation->status)->toBe('fulfilled')
            ->and($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(40.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(40.0)
            ->and((float) reservationStockRow($this->itemId)->reserved_quantity)->toBe(0.0);
    });

    it('partially fulfills a reservation bigger than the unreserved remainder', function (): void {
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-009',
            quantity: 80,
            locationId: $this->location->id,
        ));

        // 20 unreserved; consuming 50 of the hold has to draw on the hold itself.
        $movement = $this->reservationService->partialFulfill($reservation, 50);

        $reservation->refresh();

        expect((float) $movement->quantity)->toBe(50.0)
            ->and($reservation->status)->toBe('partial')
            ->and((float) $reservation->quantity)->toBe(30.0)
            ->and($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(50.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(20.0)
            ->and((float) reservationStockRow($this->itemId)->reserved_quantity)->toBe(30.0);
    });

    it('partially fulfills a reservation', function (): void {
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 50,
            locationId: $this->location->id,
        ));

        $movement = $this->reservationService->partialFulfill($reservation, 20);

        $reservation->refresh();

        expect($movement)->toBeInstanceOf(StockMovement::class)
            ->and((float) $movement->quantity)->toBe(20.0);

        expect($reservation->status)->toBe('partial')
            ->and((float) $reservation->quantity)->toBe(30.0)
            ->and($reservation->fulfilled_at)->toBeNull();

        // Complete the fulfillment
        $this->reservationService->partialFulfill($reservation, 30);
        $reservation->refresh();

        expect($reservation->status)->toBe('fulfilled')
            ->and($reservation->fulfilled_at)->not->toBeNull();
    });
});

describe('ReservationService - Release Reservations', function (): void {
    it('releases a reservation', function (): void {
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 25,
            locationId: $this->location->id,
        ));

        $this->reservationService->release($reservation);

        $reservation->refresh();

        expect($reservation->status)->toBe('cancelled')
            ->and($reservation->cancelled_at)->not->toBeNull();

        // Stock should be available again
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);
    });
});

describe('ReservationService - Expire Reservations', function (): void {
    it('expires old reservations', function (): void {
        // Create an expired reservation manually
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 25,
            locationId: $this->location->id,
            expiryHours: 1,
        ));

        // Manually set expires_at to the past
        $reservation->expires_at = Carbon::now()->subHours(2);
        $reservation->save();

        $expiredCount = $this->reservationService->expireOld();

        expect($expiredCount)->toBe(1);

        $reservation->refresh();
        expect($reservation->status)->toBe('expired');

        // Stock should be available again
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);
    });
});

/**
 * A reservation returns its hold exactly once, whatever order the closing paths
 * are called in and whichever instance they are called with.
 *
 * Every case here is arithmetic, not exceptions: the failure these guard against
 * produced confident, wrong numbers — a negative reserved quantity, or more stock
 * reported available than was ever on hand — rather than an error anyone would see.
 */
describe('ReservationService - Closing a reservation returns its hold once', function (): void {
    it('does not return the hold again when an expired reservation is released', function (): void {
        $reservation = reserveForClosureTest($this, 'WO-050', 25);

        expireReservation($this->reservationService, $reservation);

        $this->reservationService->release($reservation);

        $reservation->refresh();

        expect((float) reservationStockRow($this->itemId)->reserved_quantity)->toBe(0.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0)
            ->and($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(100.0)
            ->and($reservation->status)->toBe('expired');
    });

    it('refuses to fulfill an expired reservation', function (): void {
        $reservation = reserveForClosureTest($this, 'WO-051', 25);

        expireReservation($this->reservationService, $reservation);

        expect(fn () => $this->reservationService->fulfill($reservation))
            ->toThrow(InvalidArgumentException::class);

        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(100.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0)
            ->and((float) reservationStockRow($this->itemId)->reserved_quantity)->toBe(0.0);
    });

    it('refuses to partially fulfill an expired reservation', function (): void {
        $reservation = reserveForClosureTest($this, 'WO-052', 25);

        expireReservation($this->reservationService, $reservation);

        expect(fn () => $this->reservationService->partialFulfill($reservation, 10))
            ->toThrow(InvalidArgumentException::class);

        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(100.0)
            ->and((float) reservationStockRow($this->itemId)->reserved_quantity)->toBe(0.0);
    });

    it('does not return the hold twice when the same reservation is released through two instances', function (): void {
        $reservation = reserveForClosureTest($this, 'WO-053', 25);
        $staleCopy = Reservation::query()->findOrFail($reservation->id);

        $this->reservationService->release($reservation);
        $this->reservationService->release($staleCopy);

        expect((float) reservationStockRow($this->itemId)->reserved_quantity)->toBe(0.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);
    });

    it('does not consume twice when the same reservation is fulfilled through two instances', function (): void {
        $reservation = reserveForClosureTest($this, 'WO-054', 25);
        $staleCopy = Reservation::query()->findOrFail($reservation->id);

        $this->reservationService->fulfill($reservation);

        expect(fn () => $this->reservationService->fulfill($staleCopy))
            ->toThrow(InvalidArgumentException::class);

        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(75.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0)
            ->and((float) reservationStockRow($this->itemId)->reserved_quantity)->toBe(0.0);
    });

    it('does not return the hold twice when the expiry sweep runs again', function (): void {
        $reservation = reserveForClosureTest($this, 'WO-055', 25);

        $reservation->expires_at = Carbon::now()->subHours(2);
        $reservation->save();

        expect($this->reservationService->expireOld())->toBe(1)
            ->and($this->reservationService->expireOld())->toBe(0)
            ->and((float) reservationStockRow($this->itemId)->reserved_quantity)->toBe(0.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);
    });

    it('does not return the hold again when a fulfilled reservation is released', function (): void {
        $reservation = reserveForClosureTest($this, 'WO-056', 25);
        $staleCopy = Reservation::query()->findOrFail($reservation->id);

        $this->reservationService->fulfill($reservation);
        $this->reservationService->release($staleCopy);

        expect((float) reservationStockRow($this->itemId)->reserved_quantity)->toBe(0.0)
            ->and($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(75.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0);
    });
});

describe('ReservationService - Query Methods', function (): void {
    it('gets reservations for a job', function (): void {
        $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 25,
            locationId: $this->location->id,
        ));

        $itemId2 = (string) Symfony\Component\Uid\Uuid::v7();
        $this->stockService->addStock(new AddStockData(
            itemId: $itemId2,
            locationId: $this->location->id,
            quantity: 50,
        ));

        $this->reservationService->reserve(new ReservationData(
            itemId: $itemId2,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 10,
            locationId: $this->location->id,
        ));

        $reservations = $this->reservationService->getForJob('work_order', 'WO-001');

        expect($reservations)->toHaveCount(2);
    });

    it('checks if item can be reserved', function (): void {
        expect($this->reservationService->canReserve($this->itemId, 50, $this->location->id))->toBeTrue();
        expect($this->reservationService->canReserve($this->itemId, 100, $this->location->id))->toBeTrue();
        expect($this->reservationService->canReserve($this->itemId, 150, $this->location->id))->toBeFalse();

        // After reserving some
        $this->reservationService->reserve(new ReservationData(
            itemId: $this->itemId,
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 60,
            locationId: $this->location->id,
        ));

        expect($this->reservationService->canReserve($this->itemId, 40, $this->location->id))->toBeTrue();
        expect($this->reservationService->canReserve($this->itemId, 50, $this->location->id))->toBeFalse();
    });
});

/**
 * The single stock record behind the shared test item.
 */
function reservationStockRow(string $itemId): Stock
{
    /** @var Stock */
    return Stock::query()->where('item_id', $itemId)->firstOrFail();
}

/**
 * Reserve against the shared test item and location.
 */
function reserveForClosureTest(object $test, string $jobId, float $quantity): Reservation
{
    return $test->reservationService->reserve(new ReservationData(
        itemId: $test->itemId,
        jobType: 'work_order',
        jobId: $jobId,
        quantity: $quantity,
        locationId: $test->location->id,
    ));
}

/**
 * Age a reservation past its expiry and run the sweep over it.
 */
function expireReservation(ReservationService $service, Reservation $reservation): void
{
    $reservation->expires_at = Carbon::now()->subHours(2);
    $reservation->save();

    $service->expireOld();
}

describe('ReservationStatusDefinition', function (): void {
    it('has correct slug', function (): void {
        expect(ReservationStatusDefinition::$slug)->toBe('reservation-status');
    });

    it('has correct name', function (): void {
        expect(ReservationStatusDefinition::$name)->toBe('Reservation Status');
    });

    it('is single select', function (): void {
        expect(ReservationStatusDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function (): void {
        expect(ReservationStatusDefinition::$global)->toBeTrue();
    });

    it('has expected default values', function (): void {
        expect(ReservationStatusDefinition::DEFAULT_VALUES)
            ->toContain('pending')
            ->toContain('reserved')
            ->toContain('partial')
            ->toContain('fulfilled')
            ->toContain('cancelled')
            ->toContain('expired');
    });
});
