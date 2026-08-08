<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
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
use RobinsonRyan\Inventory\Stock\Models\Stock;
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

describe('ReserveOnJobCreated Listener', function (): void {
    it('creates reservations on job created event', function (): void {
        Event::fake([PartsReserved::class, InsufficientStock::class]);

        $job = createMockJob($this->itemId, 25, 'WO-001');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        $reservations = $this->reservationService->getForJob($job::class, 'WO-001');

        expect($reservations)->toHaveCount(1)
            ->and((float) $reservations->first()->quantity)->toBe(25.0)
            ->and($reservations->first()->status)->toBe('reserved');

        Event::assertDispatched(PartsReserved::class, fn ($event): bool => $event->jobId === 'WO-001' && count($event->reservations) === 1);
    });

    it('creates multiple reservations for multiple items', function (): void {
        Event::fake([PartsReserved::class]);

        $job = createMockJobWithMultipleItems([
            ['item_id' => $this->itemId, 'quantity' => 20, 'line_id' => 'line-1'],
            ['item_id' => $this->itemId2, 'quantity' => 10, 'line_id' => 'line-2'],
        ], 'WO-002');

        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        $reservations = $this->reservationService->getForJob($job::class, 'WO-002');

        expect($reservations)->toHaveCount(2);

        Event::assertDispatched(PartsReserved::class, fn ($event): bool => count($event->reservations) === 2);
    });

    it('handles insufficient stock gracefully', function (): void {
        Event::fake([PartsReserved::class, InsufficientStock::class]);

        $job = createMockJob($this->itemId, 150, 'WO-003');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        $reservations = $this->reservationService->getForJob($job::class, 'WO-003');

        expect($reservations)->toHaveCount(0);

        // A real shortage, and it says so: nothing to consolidate.
        Event::assertDispatched(InsufficientStock::class, fn ($event): bool => $event->jobId === 'WO-003'
            && $event->requestedQuantity === 150.0
            && $event->availableQuantity === 100.0
            && ! $event->isFragmented());

        Event::assertNotDispatched(PartsReserved::class);
    });

    it('dispatches InsufficientStock event with correct data', function (): void {
        Event::fake([InsufficientStock::class]);

        // Use up some stock first
        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: -80, // Remove 80, leaving 20
        ));

        $job = createMockJob($this->itemId, 50, 'WO-004');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        Event::assertDispatched(InsufficientStock::class, fn ($e): bool => $e->itemId === $this->itemId
            && $e->requestedQuantity === 50.0
            && $e->availableQuantity === 20.0);
    });

    it('reports fragmented stock as insufficient instead of throwing', function (): void {
        // Stock split evenly over two locations covers the request in total, but a
        // reservation holds exactly one stock record, so no single record can back
        // it. Fragmented stock is ordinary warehouse life — the host app has to get
        // the signal it already understands, not an uncaught exception.
        Event::fake([PartsReserved::class, InsufficientStock::class]);

        $secondLocation = Location::factory()->warehouse()->create();
        $splitItemId = (string) Symfony\Component\Uid\Uuid::v7();

        foreach ([$this->location, $secondLocation] as $location) {
            $this->stockService->addStock(new AddStockData(
                itemId: $splitItemId,
                locationId: $location->id,
                quantity: 50,
            ));
        }

        $job = createMockJobWithMultipleItems([
            ['item_id' => $this->itemId, 'quantity' => 10, 'line_id' => 'line-1'],
            ['item_id' => $splitItemId, 'quantity' => 60, 'line_id' => 'line-2'],
        ], 'WO-006');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        // The line that could not be backed reports the aggregate availability,
        // exactly as an outright shortage does — and marks itself as fragmented
        // so nobody reads "60 wanted, 100 available" as a reason to reorder.
        Event::assertDispatched(InsufficientStock::class, fn ($event): bool => $event->itemId === $splitItemId
            && $event->jobId === 'WO-006'
            && $event->requestedQuantity === 60.0
            && $event->availableQuantity === 100.0
            && $event->isFragmented()
            && $event->largestLocationQuantity === 50.0
            && $event->largestLocationName === $this->location->name);

        // The line that could be backed stays reserved and is still announced.
        $reservations = $this->reservationService->getForJob($job::class, 'WO-006');

        expect($reservations)->toHaveCount(1)
            ->and($reservations->first()->item_id)->toBe($this->itemId)
            ->and($reservations->first()->status)->toBe('reserved')
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(90.0)
            ->and($this->stockService->getAvailable($splitItemId))->toBe(100.0);

        Event::assertDispatched(PartsReserved::class, fn ($event): bool => count($event->reservations) === 1);
    });

    it('ignores events without JobContract', function (): void {
        Event::fake([PartsReserved::class]);

        $event = new class
        {
            public object $job;

            public function __construct()
            {
                $this->job = new class
                {
                    public string $id = 'WO-005';
                };
            }
        };

        $listener = App::make(ReserveOnJobCreated::class);
        $listener->handle($event);

        Event::assertNotDispatched(PartsReserved::class);
    });
});

describe('ConsumeOnJobCompleted Listener', function (): void {
    it('consumes stock on job completed event', function (): void {
        // First create a reservation
        $job = createMockJob($this->itemId, 25, 'WO-010');
        $createEvent = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $reserveListener = App::make(ReserveOnJobCreated::class);
        $reserveListener->handle($createEvent);

        Event::fake([PartsConsumed::class]);

        // Now complete the job
        $completeEvent = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $consumeListener = App::make(ConsumeOnJobCompleted::class);
        $consumeListener->handle($completeEvent);

        // Check stock was consumed
        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(75.0);

        // Check reservation is fulfilled
        $reservations = $this->reservationService->getForJob($job::class, 'WO-010');
        expect($reservations->first()->status)->toBe('fulfilled');

        Event::assertDispatched(PartsConsumed::class, fn ($event): bool => $event->jobId === 'WO-010' && count($event->movements) === 1);
    });

    it('does not dispatch event when no reservations exist', function (): void {
        Event::fake([PartsConsumed::class]);

        $job = createMockJob($this->itemId, 25, 'WO-011');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener = App::make(ConsumeOnJobCompleted::class);
        $listener->handle($event);

        Event::assertNotDispatched(PartsConsumed::class);
    });
});

describe('ReleaseOnJobCancelled Listener', function (): void {
    it('releases reservations on job cancelled event', function (): void {
        // First create a reservation
        $job = createMockJob($this->itemId, 25, 'WO-020');
        $createEvent = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $reserveListener = App::make(ReserveOnJobCreated::class);
        $reserveListener->handle($createEvent);

        // Verify stock is reserved
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0);

        Event::fake([PartsReleased::class]);

        // Now cancel the job
        $cancelEvent = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $releaseListener = App::make(ReleaseOnJobCancelled::class);
        $releaseListener->handle($cancelEvent);

        // Check stock is released
        expect($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);

        // Check reservation is cancelled
        $reservations = $this->reservationService->getForJob($job::class, 'WO-020');
        expect($reservations->first()->status)->toBe('cancelled');

        Event::assertDispatched(PartsReleased::class, fn ($event): bool => $event->jobId === 'WO-020' && count($event->reservations) === 1);
    });

    it('does not release fulfilled reservations', function (): void {
        // Create and fulfill a reservation
        $job = createMockJob($this->itemId, 25, 'WO-021');
        $createEvent = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $reserveListener = App::make(ReserveOnJobCreated::class);
        $reserveListener->handle($createEvent);

        // Fulfill it
        $reservations = $this->reservationService->getForJob($job::class, 'WO-021');
        $this->reservationService->fulfill($reservations->first());

        Event::fake([PartsReleased::class]);

        // Try to cancel
        $cancelEvent = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $releaseListener = App::make(ReleaseOnJobCancelled::class);
        $releaseListener->handle($cancelEvent);

        Event::assertNotDispatched(PartsReleased::class);
    });
});

/**
 * Which reservation statuses each listener acts on, proved against real records.
 *
 * These replace an equivalent set of unit tests that mocked the Reservation model
 * and asserted only that the listener had called `fulfill` or `release` on a fake.
 * Such a test passes whether or not fulfilling and releasing actually move stock —
 * which is exactly how a reservation defect stayed invisible. Every case below
 * asserts the stock position and the stored reservation, not the conversation.
 */
describe('Listener status handling', function (): void {
    it('fulfills all reserved reservations', function (): void {
        $job = createMockJobWithMultipleItems([
            ['item_id' => $this->itemId, 'quantity' => 25, 'line_id' => 'line-1'],
            ['item_id' => $this->itemId2, 'quantity' => 10, 'line_id' => 'line-2'],
        ], 'WO-030');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        Event::fake([PartsConsumed::class]);

        App::make(ConsumeOnJobCompleted::class)->handle(jobEvent($job));

        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(75.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0)
            ->and($this->stockService->getQuantity($this->itemId2, $this->location->id))->toBe(40.0)
            ->and($this->stockService->getAvailable($this->itemId2, $this->location->id))->toBe(40.0);

        $reservations = $this->reservationService->getForJob($job::class, 'WO-030');

        expect($reservations)->toHaveCount(2)
            ->and($reservations->pluck('status')->all())->toBe(['fulfilled', 'fulfilled'])
            ->and($reservations->every(fn ($r): bool => $r->fulfilled_at !== null))->toBeTrue();

        Event::assertDispatched(PartsConsumed::class, fn ($event): bool => count($event->movements) === 2);
    });

    it('fulfills partial reservations', function (): void {
        $job = createMockJob($this->itemId, 50, 'WO-031');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        $reservation = $this->reservationService->getForJob($job::class, 'WO-031')->first();
        $this->reservationService->partialFulfill($reservation, 20);
        $reservation->refresh();

        expect($reservation->status)->toBe('partial');

        Event::fake([PartsConsumed::class]);

        App::make(ConsumeOnJobCompleted::class)->handle(jobEvent($job));

        // 20 consumed by the partial fulfilment, the remaining 30 by the listener.
        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(50.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(50.0);

        $reservation->refresh();

        expect($reservation->status)->toBe('fulfilled')
            ->and($reservation->fulfilled_at)->not->toBeNull();

        Event::assertDispatched(PartsConsumed::class);
    });

    it('skips already fulfilled reservations', function (): void {
        $job = createMockJob($this->itemId, 25, 'WO-032');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        $reservation = $this->reservationService->getForJob($job::class, 'WO-032')->first();
        $this->reservationService->fulfill($reservation);

        Event::fake([PartsConsumed::class]);

        App::make(ConsumeOnJobCompleted::class)->handle(jobEvent($job));

        // Consumed exactly once — a second consumption would take the item to 50.
        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(75.0)
            ->and(consumptionMovementCount($this->itemId))->toBe(1);

        Event::assertNotDispatched(PartsConsumed::class);
    });

    it('skips cancelled reservations', function (): void {
        $job = createMockJob($this->itemId, 25, 'WO-033');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        $reservation = $this->reservationService->getForJob($job::class, 'WO-033')->first();
        $this->reservationService->release($reservation);

        Event::fake([PartsConsumed::class]);

        App::make(ConsumeOnJobCompleted::class)->handle(jobEvent($job));

        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(100.0)
            ->and(consumptionMovementCount($this->itemId))->toBe(0);

        Event::assertNotDispatched(PartsConsumed::class);
    });

    it('releases pending reservations', function (): void {
        $job = createMockJob($this->itemId, 25, 'WO-034');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        $reservation = $this->reservationService->getForJob($job::class, 'WO-034')->first();
        $reservation->status = 'pending';

        Event::fake([PartsReleased::class]);

        App::make(ReleaseOnJobCancelled::class)->handle(jobEvent($job));

        $reservation->refresh();

        expect($reservation->status)->toBe('cancelled')
            ->and($reservation->cancelled_at)->not->toBeNull()
            ->and((float) stockRow($this->itemId, $this->location->id)->reserved_quantity)->toBe(0.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);

        Event::assertDispatched(PartsReleased::class);
    });

    it('releases reserved reservations', function (): void {
        $job = createMockJob($this->itemId, 25, 'WO-035');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        expect((float) stockRow($this->itemId, $this->location->id)->reserved_quantity)->toBe(25.0);

        Event::fake([PartsReleased::class]);

        App::make(ReleaseOnJobCancelled::class)->handle(jobEvent($job));

        $reservation = $this->reservationService->getForJob($job::class, 'WO-035')->first();

        expect($reservation->status)->toBe('cancelled')
            ->and((float) stockRow($this->itemId, $this->location->id)->reserved_quantity)->toBe(0.0)
            ->and($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(100.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);

        Event::assertDispatched(PartsReleased::class);
    });

    it('releases partial reservations', function (): void {
        $job = createMockJob($this->itemId, 50, 'WO-036');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        $reservation = $this->reservationService->getForJob($job::class, 'WO-036')->first();
        $this->reservationService->partialFulfill($reservation, 20);

        Event::fake([PartsReleased::class]);

        App::make(ReleaseOnJobCancelled::class)->handle(jobEvent($job));

        $reservation->refresh();

        // The 20 already consumed stay consumed; the 30 still held come back.
        expect($reservation->status)->toBe('cancelled')
            ->and((float) stockRow($this->itemId, $this->location->id)->reserved_quantity)->toBe(0.0)
            ->and($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(80.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(80.0);

        Event::assertDispatched(PartsReleased::class);
    });

    it('skips fulfilled reservations', function (): void {
        $job = createMockJob($this->itemId, 25, 'WO-037');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        $reservation = $this->reservationService->getForJob($job::class, 'WO-037')->first();
        $this->reservationService->fulfill($reservation);

        Event::fake([PartsReleased::class]);

        App::make(ReleaseOnJobCancelled::class)->handle(jobEvent($job));

        $reservation->refresh();

        // Releasing a fulfilled reservation would hand back stock that is gone.
        expect($reservation->status)->toBe('fulfilled')
            ->and($reservation->cancelled_at)->toBeNull()
            ->and($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(75.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(75.0);

        Event::assertNotDispatched(PartsReleased::class);
    });

    it('skips already cancelled reservations', function (): void {
        $job = createMockJob($this->itemId, 25, 'WO-038');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        $reservation = $this->reservationService->getForJob($job::class, 'WO-038')->first();
        $this->reservationService->release($reservation);
        $reservation->refresh();
        $cancelledAt = $reservation->cancelled_at;

        Event::fake([PartsReleased::class]);

        App::make(ReleaseOnJobCancelled::class)->handle(jobEvent($job));

        $reservation->refresh();

        // A second release would decrement reserved_quantity below zero.
        expect($reservation->cancelled_at->equalTo($cancelledAt))->toBeTrue()
            ->and((float) stockRow($this->itemId, $this->location->id)->reserved_quantity)->toBe(0.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);

        Event::assertNotDispatched(PartsReleased::class);
    });

    it('skips expired reservations', function (): void {
        $job = createMockJob($this->itemId, 25, 'WO-039');

        App::make(ReserveOnJobCreated::class)->handle(jobEvent($job));

        $reservation = $this->reservationService->getForJob($job::class, 'WO-039')->first();
        $reservation->expires_at = Carbon::now()->subHour();
        $reservation->save();

        expect($this->reservationService->expireOld())->toBe(1);

        Event::fake([PartsReleased::class]);

        App::make(ReleaseOnJobCancelled::class)->handle(jobEvent($job));

        $reservation->refresh();

        // Expiry already returned the held quantity; cancelling would return it twice.
        expect($reservation->status)->toBe('expired')
            ->and($reservation->cancelled_at)->toBeNull()
            ->and((float) stockRow($this->itemId, $this->location->id)->reserved_quantity)->toBe(0.0)
            ->and($this->stockService->getAvailable($this->itemId, $this->location->id))->toBe(100.0);

        Event::assertNotDispatched(PartsReleased::class);
    });
});

/**
 * Wrap a job in the minimal event shape the listeners read.
 */
function jobEvent(JobContract $job): object
{
    return new class($job)
    {
        public function __construct(public JobContract $job) {}
    };
}

/**
 * The stock record for an item at a location.
 */
function stockRow(string $itemId, string $locationId): Stock
{
    /** @var Stock */
    return Stock::query()
        ->where('item_id', $itemId)
        ->where('location_id', $locationId)
        ->firstOrFail();
}

/**
 * How many consumption movements exist for an item.
 */
function consumptionMovementCount(string $itemId): int
{
    return StockMovement::query()
        ->where('item_id', $itemId)
        ->whereNotNull('from_location_id')
        ->whereNull('to_location_id')
        ->count();
}

/**
 * Create a mock job implementing JobContract.
 */
function createMockJob(string $itemId, float $quantity, string $jobId): JobContract
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
 * Create a mock job with multiple items.
 *
 * @param  array<int, array{item_id: string, quantity: float, line_id?: string}>  $items
 */
function createMockJobWithMultipleItems(array $items, string $jobId): JobContract
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
