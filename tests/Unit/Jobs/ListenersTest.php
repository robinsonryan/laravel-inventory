<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Listeners\ConsumeOnJobCompleted;
use RobinsonRyan\Inventory\Jobs\Listeners\ReleaseOnJobCancelled;
use RobinsonRyan\Inventory\Jobs\Listeners\ReserveOnJobCreated;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;

describe('ReserveOnJobCreated', function (): void {
    it('extracts job from event->job property', function (): void {
        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('canReserve')->andReturn(true);
        $mockService->shouldReceive('reserve')->andReturn(
            Mockery::mock(Reservation::class),
        );

        $listener = new ReserveOnJobCreated($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-001');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldHaveReceived('reserve')->once();
    });

    it('extracts job from event->workOrder property', function (): void {
        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('canReserve')->andReturn(true);
        $mockService->shouldReceive('reserve')->andReturn(
            Mockery::mock(Reservation::class),
        );

        $listener = new ReserveOnJobCreated($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-002');
        $event = new class($job)
        {
            public function __construct(public JobContract $workOrder) {}
        };

        $listener->handle($event);

        $mockService->shouldHaveReceived('reserve')->once();
    });

    it('extracts job from event->model property', function (): void {
        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('canReserve')->andReturn(true);
        $mockService->shouldReceive('reserve')->andReturn(
            Mockery::mock(Reservation::class),
        );

        $listener = new ReserveOnJobCreated($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-003');
        $event = new class($job)
        {
            public function __construct(public JobContract $model) {}
        };

        $listener->handle($event);

        $mockService->shouldHaveReceived('reserve')->once();
    });

    it('does nothing when event has no job property', function (): void {
        $mockService = Mockery::mock(ReservationService::class);

        $listener = new ReserveOnJobCreated($mockService);

        $event = new class {};

        $listener->handle($event);

        $mockService->shouldNotHaveReceived('reserve');
    });

    it('does nothing when job does not implement JobContract', function (): void {
        $mockService = Mockery::mock(ReservationService::class);

        $listener = new ReserveOnJobCreated($mockService);

        $event = new class
        {
            public object $job;

            public function __construct()
            {
                $this->job = new class
                {
                    public string $id = 'not-a-job';
                };
            }
        };

        $listener->handle($event);

        $mockService->shouldNotHaveReceived('reserve');
    });

    it('creates reservation with correct data', function (): void {
        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('canReserve')
            ->with('item-123', 25.0)
            ->andReturn(true);

        $mockService->shouldReceive('reserve')
            ->withArgs(fn (ReservationData $data): bool => $data->itemId === 'item-123'
                && $data->quantity === 25.0
                && $data->lineId === 'line-001')
            ->andReturn(Mockery::mock(Reservation::class));

        $listener = new ReserveOnJobCreated($mockService);

        $job = createUnitTestMockJobWithLine('item-123', 25, 'WO-004', 'line-001');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldHaveReceived('reserve')->once();
    });
});

describe('ConsumeOnJobCompleted', function (): void {
    it('fulfills all reserved reservations', function (): void {
        $reservation1 = Mockery::mock(Reservation::class);
        $reservation1->status = 'reserved';

        $reservation2 = Mockery::mock(Reservation::class);
        $reservation2->status = 'reserved';

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation1, $reservation2]));
        $mockService->shouldReceive('fulfill')
            ->twice()
            ->andReturn(Mockery::mock(RobinsonRyan\Inventory\Stock\Models\StockMovement::class));

        $listener = new ConsumeOnJobCompleted($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-010');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldHaveReceived('fulfill')->twice();
    });

    it('fulfills partial reservations', function (): void {
        $reservation = Mockery::mock(Reservation::class);
        $reservation->status = 'partial';

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation]));
        $mockService->shouldReceive('fulfill')
            ->once()
            ->andReturn(Mockery::mock(RobinsonRyan\Inventory\Stock\Models\StockMovement::class));

        $listener = new ConsumeOnJobCompleted($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-011');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldHaveReceived('fulfill')->once();
    });

    it('skips already fulfilled reservations', function (): void {
        $reservation = Mockery::mock(Reservation::class);
        $reservation->status = 'fulfilled';

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation]));

        $listener = new ConsumeOnJobCompleted($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-012');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldNotHaveReceived('fulfill');
    });

    it('skips cancelled reservations', function (): void {
        $reservation = Mockery::mock(Reservation::class);
        $reservation->status = 'cancelled';

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation]));

        $listener = new ConsumeOnJobCompleted($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-013');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldNotHaveReceived('fulfill');
    });
});

describe('ReleaseOnJobCancelled', function (): void {
    it('releases pending reservations', function (): void {
        $reservation = Mockery::mock(Reservation::class);
        $reservation->status = 'pending';
        $reservation->shouldReceive('refresh');

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation]));
        $mockService->shouldReceive('release')
            ->once();

        $listener = new ReleaseOnJobCancelled($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-020');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldHaveReceived('release')->once();
    });

    it('releases reserved reservations', function (): void {
        $reservation = Mockery::mock(Reservation::class);
        $reservation->status = 'reserved';
        $reservation->shouldReceive('refresh');

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation]));
        $mockService->shouldReceive('release')
            ->once();

        $listener = new ReleaseOnJobCancelled($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-021');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldHaveReceived('release')->once();
    });

    it('releases partial reservations', function (): void {
        $reservation = Mockery::mock(Reservation::class);
        $reservation->status = 'partial';
        $reservation->shouldReceive('refresh');

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation]));
        $mockService->shouldReceive('release')
            ->once();

        $listener = new ReleaseOnJobCancelled($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-022');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldHaveReceived('release')->once();
    });

    it('skips fulfilled reservations', function (): void {
        $reservation = Mockery::mock(Reservation::class);
        $reservation->status = 'fulfilled';

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation]));

        $listener = new ReleaseOnJobCancelled($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-023');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldNotHaveReceived('release');
    });

    it('skips already cancelled reservations', function (): void {
        $reservation = Mockery::mock(Reservation::class);
        $reservation->status = 'cancelled';

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation]));

        $listener = new ReleaseOnJobCancelled($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-024');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldNotHaveReceived('release');
    });

    it('skips expired reservations', function (): void {
        $reservation = Mockery::mock(Reservation::class);
        $reservation->status = 'expired';

        $mockService = Mockery::mock(ReservationService::class);
        $mockService->shouldReceive('getForJob')
            ->andReturn(collect([$reservation]));

        $listener = new ReleaseOnJobCancelled($mockService);

        $job = createUnitTestMockJob('item-1', 10, 'WO-025');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        $mockService->shouldNotHaveReceived('release');
    });
});

/**
 * Create a mock job for unit tests.
 */
function createUnitTestMockJob(string $itemId, float $quantity, string $jobId): JobContract
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
 * Create a mock job with line_id for unit tests.
 */
function createUnitTestMockJobWithLine(string $itemId, float $quantity, string $jobId, string $lineId): JobContract
{
    return new class($itemId, $quantity, $jobId, $lineId) implements JobContract
    {
        public function __construct(
            private readonly string $itemId,
            private readonly float $quantity,
            private readonly string $jobId,
            private readonly string $lineId,
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
                    'line_id' => $this->lineId,
                ],
            ];
        }

        public function shouldHoldReservations(): bool
        {
            return true;
        }
    };
}
