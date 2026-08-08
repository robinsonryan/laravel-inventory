<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Contracts\ReservationContract;
use RobinsonRyan\Inventory\Jobs\Listeners\ReserveOnJobCreated;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Models\Reservation;

/**
 * These cover only what the listener itself decides: which event property carries
 * the job, whether the job is one it handles, and the reservation request it builds.
 * Everything the reservation service then does with that request is proved against
 * real records in tests/Feature/Jobs/ReservationIntegrationTest.php — a listener
 * that "called reserve on a fake" tells us nothing about whether stock moved.
 */
describe('ReserveOnJobCreated', function (): void {
    it('extracts job from event->job property', function (): void {
        $reservations = Mockery::mock(ReservationContract::class);
        $reservations->shouldReceive('canReserve')->andReturn(true);
        $reservations->shouldReceive('reserve')->once()->andReturn(new Reservation);

        $listener = new ReserveOnJobCreated($reservations);

        $job = createUnitTestMockJob('item-1', 10, 'WO-001');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);
    });

    it('extracts job from event->workOrder property', function (): void {
        $reservations = Mockery::mock(ReservationContract::class);
        $reservations->shouldReceive('canReserve')->andReturn(true);
        $reservations->shouldReceive('reserve')->once()->andReturn(new Reservation);

        $listener = new ReserveOnJobCreated($reservations);

        $job = createUnitTestMockJob('item-1', 10, 'WO-002');
        $event = new class($job)
        {
            public function __construct(public JobContract $workOrder) {}
        };

        $listener->handle($event);
    });

    it('extracts job from event->model property', function (): void {
        $reservations = Mockery::mock(ReservationContract::class);
        $reservations->shouldReceive('canReserve')->andReturn(true);
        $reservations->shouldReceive('reserve')->once()->andReturn(new Reservation);

        $listener = new ReserveOnJobCreated($reservations);

        $job = createUnitTestMockJob('item-1', 10, 'WO-003');
        $event = new class($job)
        {
            public function __construct(public JobContract $model) {}
        };

        $listener->handle($event);
    });

    it('does nothing when event has no job property', function (): void {
        $reservations = Mockery::mock(ReservationContract::class);
        $reservations->shouldNotReceive('reserve');

        $listener = new ReserveOnJobCreated($reservations);

        $listener->handle(new class {});
    });

    it('does nothing when job does not implement JobContract', function (): void {
        $reservations = Mockery::mock(ReservationContract::class);
        $reservations->shouldNotReceive('reserve');

        $listener = new ReserveOnJobCreated($reservations);

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
    });

    it('creates reservation with correct data', function (): void {
        $captured = null;

        $reservations = Mockery::mock(ReservationContract::class);
        $reservations->shouldReceive('canReserve')
            ->with('item-123', 25.0)
            ->andReturn(true);

        $reservations->shouldReceive('reserve')
            ->once()
            ->with(Mockery::capture($captured))
            ->andReturn(new Reservation);

        $listener = new ReserveOnJobCreated($reservations);

        $job = createUnitTestMockJobWithLine('item-123', 25, 'WO-004', 'line-001');
        $event = new class($job)
        {
            public function __construct(public JobContract $job) {}
        };

        $listener->handle($event);

        expect($captured)->toBeInstanceOf(ReservationData::class)
            ->and($captured->itemId)->toBe('item-123')
            ->and($captured->quantity)->toBe(25.0)
            ->and($captured->lineId)->toBe('line-001')
            ->and($captured->jobId)->toBe('WO-004')
            ->and($captured->jobType)->toBe($job::class);
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
