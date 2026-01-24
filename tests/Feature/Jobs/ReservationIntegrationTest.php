<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Events\InsufficientStock;
use RobinsonRyan\Inventory\Jobs\Events\PartsConsumed;
use RobinsonRyan\Inventory\Jobs\Events\PartsReleased;
use RobinsonRyan\Inventory\Jobs\Events\PartsReserved;
use RobinsonRyan\Inventory\Jobs\Listeners\ConsumeOnJobCompleted;
use RobinsonRyan\Inventory\Jobs\Listeners\ReleaseOnJobCancelled;
use RobinsonRyan\Inventory\Jobs\Listeners\ReserveOnJobCreated;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;
use RobinsonRyan\Inventory\Stock\Services\StockService;

beforeEach(function () {
    $this->stockService = App::make(StockService::class);
    $this->reservationService = App::make(ReservationService::class);
    $this->location = Location::factory()->warehouse()->create();
    $this->itemId = (string) \Symfony\Component\Uid\Uuid::v7();
    $this->itemId2 = (string) \Symfony\Component\Uid\Uuid::v7();

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

describe('ReserveOnJobCreated Listener', function () {
    it('creates reservations on job created event', function () {
        Event::fake([PartsReserved::class, InsufficientStock::class]);

        $job = createMockJob($this->itemId, 25, 'WO-001');
        $event = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        $reservations = $this->reservationService->getForJob(get_class($job), 'WO-001');

        expect($reservations)->toHaveCount(1)
            ->and((float) $reservations->first()->quantity)->toBe(25.0)
            ->and($reservations->first()->status)->toBe('reserved');

        Event::assertDispatched(PartsReserved::class, function ($event) {
            return $event->jobId === 'WO-001' && count($event->reservations) === 1;
        });
    });

    it('creates multiple reservations for multiple items', function () {
        Event::fake([PartsReserved::class]);

        $job = createMockJobWithMultipleItems([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
            ['item_id' => $this->itemId2, 'quantity' => 10, 'line_id' => 'line-2'],
        ], 'WO-002');

        $event = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        $reservations = $this->reservationService->getForJob(get_class($job), 'WO-002');

        expect($reservations)->toHaveCount(2);

        Event::assertDispatched(PartsReserved::class, function ($event) {
            return count($event->reservations) === 2;
        });
    });

    it('handles insufficient stock gracefully', function () {
        Event::fake([PartsReserved::class, InsufficientStock::class]);

        $job = createMockJob($this->itemId, 150, 'WO-003');
        $event = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        $reservations = $this->reservationService->getForJob(get_class($job), 'WO-003');

        expect($reservations)->toHaveCount(0);

        Event::assertDispatched(InsufficientStock::class, function ($event) use ($job) {
            return $event->jobId === 'WO-003'
                && $event->requestedQuantity === 150.0
                && $event->availableQuantity === 100.0;
        });

        Event::assertNotDispatched(PartsReserved::class);
    });

    it('dispatches InsufficientStock event with correct data', function () {
        Event::fake([InsufficientStock::class]);

        // Use up some stock first
        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: -80, // Remove 80, leaving 20
        ));

        $job = createMockJob($this->itemId, 50, 'WO-004');
        $event = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        Event::assertDispatched(InsufficientStock::class, function ($e) {
            return $e->itemId === $this->itemId
                && $e->requestedQuantity === 50.0
                && $e->availableQuantity === 20.0;
        });
    });

    it('ignores events without JobContract', function () {
        Event::fake([PartsReserved::class]);

        $event = new class {
            public object $job;

            public function __construct()
            {
                $this->job = new class {
                    public string $id = 'WO-005';
                };
            }
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        Event::assertNotDispatched(PartsReserved::class);
    });
});

describe('ConsumeOnJobCompleted Listener', function () {
    it('consumes stock on job completed event', function () {
        // First create a reservation
        $job = createMockJob($this->itemId, 25, 'WO-010');
        $createEvent = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $reserveListener = App::make(ReserveOnJobCreated::class);
        $reserveListener->handle($createEvent);

        Event::fake([PartsConsumed::class]);

        // Now complete the job
        $completeEvent = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $consumeListener = App::make(ConsumeOnJobCompleted::class);
        $consumeListener->handle($completeEvent);

        // Check stock was consumed
        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(75.0);

        // Check reservation is fulfilled
        $reservations = $this->reservationService->getForJob(get_class($job), 'WO-010');
        expect($reservations->first()->status)->toBe('fulfilled');

        Event::assertDispatched(PartsConsumed::class, function ($event) {
            return $event->jobId === 'WO-010' && count($event->movements) === 1;
        });
    });

    it('does not dispatch event when no reservations exist', function () {
        Event::fake([PartsConsumed::class]);

        $job = createMockJob($this->itemId, 25, 'WO-011');
        $event = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ConsumeOnJobCompleted::class);
        $listener->handle($event);

        Event::assertNotDispatched(PartsConsumed::class);
    });
});

describe('ReleaseOnJobCancelled Listener', function () {
    it('releases reservations on job cancelled event', function () {
        // First create a reservation
        $job = createMockJob($this->itemId, 25, 'WO-020');
        $createEvent = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $reserveListener = App::make(ReserveOnJobCreated::class);
        $reserveListener->handle($createEvent);

        // Verify stock is reserved
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0);

        Event::fake([PartsReleased::class]);

        // Now cancel the job
        $cancelEvent = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $releaseListener = App::make(ReleaseOnJobCancelled::class);
        $releaseListener->handle($cancelEvent);

        // Check stock is released
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);

        // Check reservation is cancelled
        $reservations = $this->reservationService->getForJob(get_class($job), 'WO-020');
        expect($reservations->first()->status)->toBe('cancelled');

        Event::assertDispatched(PartsReleased::class, function ($event) {
            return $event->jobId === 'WO-020' && count($event->reservations) === 1;
        });
    });

    it('does not release fulfilled reservations', function () {
        // Create and fulfill a reservation
        $job = createMockJob($this->itemId, 25, 'WO-021');
        $createEvent = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $reserveListener = App::make(ReserveOnJobCreated::class);
        $reserveListener->handle($createEvent);

        // Fulfill it
        $reservations = $this->reservationService->getForJob(get_class($job), 'WO-021');
        $this->reservationService->fulfill($reservations->first());

        Event::fake([PartsReleased::class]);

        // Try to cancel
        $cancelEvent = new class($job) {
            public function __construct(public JobContract $job) {}
        };

        $releaseListener = App::make(ReleaseOnJobCancelled::class);
        $releaseListener->handle($cancelEvent);

        Event::assertNotDispatched(PartsReleased::class);
    });
});

/**
 * Create a mock job implementing JobContract.
 */
function createMockJob(string $itemId, float $quantity, string $jobId): JobContract
{
    return new class($itemId, $quantity, $jobId) implements JobContract {
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
 * Create a mock job with multiple items.
 *
 * @param  array<int, array{item_id: string, quantity: float, line_id?: string}>  $items
 */
function createMockJobWithMultipleItems(array $items, string $jobId): JobContract
{
    return new class($items, $jobId) implements JobContract {
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
