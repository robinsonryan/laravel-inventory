<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
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
