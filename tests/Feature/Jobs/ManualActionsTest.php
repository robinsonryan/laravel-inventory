<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Actions\ConsumeForJob;
use RobinsonRyan\Inventory\Jobs\Actions\ReleaseForJob;
use RobinsonRyan\Inventory\Jobs\Actions\ReserveForJob;
use RobinsonRyan\Inventory\Jobs\Actions\UpdateJobReservations;
use RobinsonRyan\Inventory\Jobs\Events\InsufficientStock;
use RobinsonRyan\Inventory\Jobs\Events\PartsConsumed;
use RobinsonRyan\Inventory\Jobs\Events\PartsReleased;
use RobinsonRyan\Inventory\Jobs\Events\PartsReserved;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;
use RobinsonRyan\Inventory\Stock\Services\StockService;

beforeEach(function (): void {
    $this->stockService = App::make(StockService::class);
    $this->reservationService = App::make(ReservationService::class);
    $this->location = Location::factory()->warehouse()->create();
    $this->itemId = (string) Symfony\Component\Uid\Uuid::v7();
    $this->itemId2 = (string) Symfony\Component\Uid\Uuid::v7();

    // Add initial stock
    $this->stockService->addStock(new AddStockData(
        itemId: $this->itemId,
        locationId: $this->location->id,
        quantity: 100,
        cost: 10.00,
    ));

    $this->stockService->addStock(new AddStockData(
        itemId: $this->itemId2,
        locationId: $this->location->id,
        quantity: 50,
        cost: 5.00,
    ));
});

describe('ReserveForJob Action', function (): void {
    it('manually reserves for job', function (): void {
        Event::fake([PartsReserved::class]);

        $job = createMockJobForAction($this->itemId, 25, 'WO-100');

        $action = App::make(ReserveForJob::class);
        $reservations = $action->execute($job);

        expect($reservations)->toHaveCount(1)
            ->and($reservations->first())->toBeInstanceOf(Reservation::class)
            ->and((float) $reservations->first()->quantity)->toBe(25.0)
            ->and($reservations->first()->status)->toBe('reserved');

        // Stock should be reserved
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0);

        Event::assertDispatched(PartsReserved::class);
    });

    it('returns empty collection for insufficient stock', function (): void {
        Event::fake([PartsReserved::class, InsufficientStock::class]);

        $job = createMockJobForAction($this->itemId, 150, 'WO-101');

        $action = App::make(ReserveForJob::class);
        $reservations = $action->execute($job);

        expect($reservations)->toHaveCount(0);

        Event::assertNotDispatched(PartsReserved::class);
        Event::assertDispatched(InsufficientStock::class);
    });

    it('reports fragmented stock as insufficient instead of throwing', function (): void {
        Event::fake([PartsReserved::class, InsufficientStock::class]);

        $splitItemId = splitStockAcrossTwoLocations($this, 50);

        $job = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 10, 'line_id' => 'line-1'],
            ['item_id' => $splitItemId, 'quantity' => 60, 'line_id' => 'line-2'],
        ], 'WO-103');

        $reservations = App::make(ReserveForJob::class)->execute($job);

        expect($reservations)->toHaveCount(1)
            ->and($reservations->first()->item_id)->toBe($this->itemId)
            ->and($this->stockService->getAvailable($splitItemId))->toBe(100.0);

        Event::assertDispatched(InsufficientStock::class, fn ($event): bool => $event->itemId === $splitItemId
            && $event->requestedQuantity === 60.0
            && $event->availableQuantity === 100.0
            && $event->isFragmented()
            && $event->largestLocationQuantity === 50.0);

        Event::assertDispatched(PartsReserved::class, fn ($event): bool => count($event->reservations) === 1);
    });

    it('reserves multiple items for job', function (): void {
        Event::fake([PartsReserved::class]);

        $job = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
            ['item_id' => $this->itemId2, 'quantity' => 15, 'line_id' => 'line-2'],
        ], 'WO-102');

        $action = App::make(ReserveForJob::class);
        $reservations = $action->execute($job);

        expect($reservations)->toHaveCount(2);
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(80.0);
        expect($this->stockService->getAvailable($this->itemId2, $this->location->id))->toBe(35.0);
    });
});

describe('ConsumeForJob Action', function (): void {
    it('manually consumes for job', function (): void {
        // First reserve
        $job = createMockJobForAction($this->itemId, 25, 'WO-110');
        $reserveAction = App::make(ReserveForJob::class);
        $reserveAction->execute($job);

        Event::fake([PartsConsumed::class]);

        // Now consume
        $consumeAction = App::make(ConsumeForJob::class);
        $movements = $consumeAction->execute($job);

        expect($movements)->toHaveCount(1)
            ->and($movements->first())->toBeInstanceOf(StockMovement::class)
            ->and((float) $movements->first()->quantity)->toBe(25.0)
            ->and($movements->first()->type)->toBe('consumption');

        // Stock should be consumed
        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(75.0);

        Event::assertDispatched(PartsConsumed::class);
    });

    it('returns empty collection when no reservations exist', function (): void {
        Event::fake([PartsConsumed::class]);

        $job = createMockJobForAction($this->itemId, 25, 'WO-111');

        $consumeAction = App::make(ConsumeForJob::class);
        $movements = $consumeAction->execute($job);

        expect($movements)->toHaveCount(0);
        Event::assertNotDispatched(PartsConsumed::class);
    });
});

describe('ReleaseForJob Action', function (): void {
    it('manually releases for job', function (): void {
        // First reserve
        $job = createMockJobForAction($this->itemId, 25, 'WO-120');
        $reserveAction = App::make(ReserveForJob::class);
        $reserveAction->execute($job);

        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0);

        Event::fake([PartsReleased::class]);

        // Now release
        $releaseAction = App::make(ReleaseForJob::class);
        $released = $releaseAction->execute($job);

        expect($released)->toHaveCount(1)
            ->and($released->first())->toBeInstanceOf(Reservation::class)
            ->and($released->first()->status)->toBe('cancelled');

        // Stock should be available again
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);

        Event::assertDispatched(PartsReleased::class);
    });

    it('returns empty collection when no active reservations exist', function (): void {
        Event::fake([PartsReleased::class]);

        $job = createMockJobForAction($this->itemId, 25, 'WO-121');

        $releaseAction = App::make(ReleaseForJob::class);
        $released = $releaseAction->execute($job);

        expect($released)->toHaveCount(0);
        Event::assertNotDispatched(PartsReleased::class);
    });
});

describe('UpdateJobReservations Action', function (): void {
    it('updates reservations when job items change', function (): void {
        // Create initial reservation
        $job = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
        ], 'WO-130');

        $reserveAction = App::make(ReserveForJob::class);
        $reserveAction->execute($job);

        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(80.0);

        Event::fake([PartsReserved::class, PartsReleased::class]);

        // Update job with different quantity
        $updatedJob = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 30, 'line_id' => 'line-1'],
        ], 'WO-130');

        $updateAction = App::make(UpdateJobReservations::class);
        $updateAction->execute($updatedJob);

        // Should have released old and created new
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(70.0);

        Event::assertDispatched(PartsReleased::class);
        Event::assertDispatched(PartsReserved::class);
    });

    it('releases reservations for removed items', function (): void {
        // Create initial reservations for two items
        $job = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
            ['item_id' => $this->itemId2, 'quantity' => 10, 'line_id' => 'line-2'],
        ], 'WO-131');

        $reserveAction = App::make(ReserveForJob::class);
        $reserveAction->execute($job);

        Event::fake([PartsReleased::class]);

        // Update job with only one item
        $updatedJob = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
        ], 'WO-131');

        $updateAction = App::make(UpdateJobReservations::class);
        $updateAction->execute($updatedJob);

        // Item2 reservation should be released
        expect($this->stockService->getAvailable($this->itemId2, $this->location->id))->toBe(50.0);

        Event::assertDispatched(PartsReleased::class, fn ($event): bool => count($event->reservations) === 1);
    });

    it('creates new reservations for added items', function (): void {
        // Create initial reservation
        $job = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
        ], 'WO-132');

        $reserveAction = App::make(ReserveForJob::class);
        $reserveAction->execute($job);

        Event::fake([PartsReserved::class, PartsReleased::class]);

        // Add another item
        $updatedJob = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
            ['item_id' => $this->itemId2, 'quantity' => 15, 'line_id' => 'line-2'],
        ], 'WO-132');

        $updateAction = App::make(UpdateJobReservations::class);
        $updateAction->execute($updatedJob);

        expect($this->stockService->getAvailable($this->itemId2, $this->location->id))->toBe(35.0);

        Event::assertDispatched(PartsReserved::class, fn ($event): bool => count($event->reservations) === 1);
        Event::assertNotDispatched(PartsReleased::class);
    });

    it('keeps unchanged reservations', function (): void {
        // Create initial reservation
        $job = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
        ], 'WO-133');

        $reserveAction = App::make(ReserveForJob::class);
        $initialReservations = $reserveAction->execute($job);
        $initialId = $initialReservations->first()->id;

        Event::fake([PartsReserved::class, PartsReleased::class]);

        // Same job, no changes
        $updateAction = App::make(UpdateJobReservations::class);
        $updateAction->execute($job);

        // Reservation should still exist with same ID
        $reservations = $this->reservationService->getForJob($job::class, 'WO-133');
        expect($reservations)->toHaveCount(1)
            ->and($reservations->first()->id)->toBe($initialId);

        Event::assertNotDispatched(PartsReserved::class);
        Event::assertNotDispatched(PartsReleased::class);
    });

    it('dispatches InsufficientStock for new items without stock', function (): void {
        // Create initial reservation using most of the stock
        $job = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 90, 'line_id' => 'line-1'],
        ], 'WO-134');

        $reserveAction = App::make(ReserveForJob::class);
        $reserveAction->execute($job);

        Event::fake([PartsReserved::class, InsufficientStock::class]);

        // Try to add another item that requires more stock than available
        $updatedJob = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 90, 'line_id' => 'line-1'],
            ['item_id' => $this->itemId2, 'quantity' => 100, 'line_id' => 'line-2'], // Only 50 available
        ], 'WO-134');

        $updateAction = App::make(UpdateJobReservations::class);
        $updateAction->execute($updatedJob);

        Event::assertDispatched(InsufficientStock::class, fn ($event): bool => $event->itemId === $this->itemId2
            && $event->requestedQuantity === 100.0
            && $event->availableQuantity === 50.0);
    });

    it('reports fragmented stock as insufficient instead of throwing', function (): void {
        $job = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
        ], 'WO-135');

        App::make(ReserveForJob::class)->execute($job);

        Event::fake([PartsReserved::class, InsufficientStock::class]);

        $splitItemId = splitStockAcrossTwoLocations($this, 50);

        $updatedJob = createMockJobWithItemsForAction([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
            ['item_id' => $splitItemId, 'quantity' => 60, 'line_id' => 'line-2'],
        ], 'WO-135');

        App::make(UpdateJobReservations::class)->execute($updatedJob);

        expect($this->stockService->getAvailable($splitItemId))->toBe(100.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(80.0);

        Event::assertDispatched(InsufficientStock::class, fn ($event): bool => $event->itemId === $splitItemId
            && $event->requestedQuantity === 60.0
            && $event->availableQuantity === 100.0
            && $event->isFragmented()
            && $event->largestLocationQuantity === 50.0);

        Event::assertNotDispatched(PartsReserved::class);
    });
});

/**
 * Stock a fresh item evenly over two locations, so no single record covers the total.
 */
function splitStockAcrossTwoLocations(object $test, float $perLocation): string
{
    $itemId = (string) Symfony\Component\Uid\Uuid::v7();

    foreach ([$test->location, Location::factory()->warehouse()->create()] as $location) {
        $test->stockService->addStock(new AddStockData(
            itemId: $itemId,
            locationId: $location->id,
            quantity: $perLocation,
        ));
    }

    return $itemId;
}

/**
 * Create a mock job implementing JobContract for action tests.
 */
function createMockJobForAction(string $itemId, float $quantity, string $jobId): JobContract
{
    return new class($itemId, $quantity, $jobId) implements JobContract
    {
        public function __construct(
            private readonly string $itemId,
            private readonly float $quantity,
            private readonly string $jobId,
        ) {}

        public function getInventoryKey(): string
        {
            return $this->jobId;
        }

        public function getInventoryTenantId(): ?string
        {
            return null;
        }

        public function getItemsToReserve(): iterable
        {
            return [
                [
                    'item_id' => $this->itemId,
                    'quantity' => $this->quantity,
                ],
            ];
        }

        public function shouldHoldReservations(): bool
        {
            return true;
        }
    };
}

/**
 * Create a mock job with multiple items for action tests.
 *
 * @param  array<int, array{item_id: string, quantity: float, line_id?: string}>  $items
 */
function createMockJobWithItemsForAction(array $items, string $jobId): JobContract
{
    return new class($items, $jobId) implements JobContract
    {
        public function __construct(
            private readonly array $items,
            private readonly string $jobId,
        ) {}

        public function getInventoryKey(): string
        {
            return $this->jobId;
        }

        public function getInventoryTenantId(): ?string
        {
            return null;
        }

        public function getItemsToReserve(): iterable
        {
            return $this->items;
        }

        public function shouldHoldReservations(): bool
        {
            return true;
        }
    };
}
