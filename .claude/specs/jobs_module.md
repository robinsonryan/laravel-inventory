# Jobs Integration Module Specification

## Overview

The Jobs module provides integration between the inventory system and work orders / jobs in the consuming application. It handles automatic reservation when jobs are created and consumption when jobs complete.

## Contracts

The JobContract is defined in `src/Contracts/JobContract.php` (already in shared contracts).

## Event Listeners

### ReserveOnJobCreated

Listens for job creation events and creates reservations.

```php
namespace RobinsonRyan\Inventory\Jobs\Listeners;

final class ReserveOnJobCreated
{
    public function __construct(
        private ReservationService $reservationService
    ) {}

    public function handle($event): void
    {
        $job = $event->job;

        if (! $job instanceof JobContract) {
            return;
        }

        foreach ($job->getItemsToReserve() as $item) {
            $this->reservationService->reserve(new ReservationData(
                itemId: $item['item_id'],
                jobType: get_class($job),
                jobId: $job->getInventoryKey(),
                quantity: $item['quantity'],
                lineId: $item['line_id'] ?? null,
                expiryHours: config('inventory.rules.reservation_expiry_hours'),
            ));
        }
    }
}
```

### ConsumeOnJobCompleted

Listens for job completion and fulfills reservations (consumes stock).

```php
namespace RobinsonRyan\Inventory\Jobs\Listeners;

final class ConsumeOnJobCompleted
{
    public function __construct(
        private ReservationService $reservationService
    ) {}

    public function handle($event): void
    {
        $job = $event->job;

        if (! $job instanceof JobContract) {
            return;
        }

        $reservations = $this->reservationService->getForJob(
            get_class($job),
            $job->getInventoryKey()
        );

        foreach ($reservations as $reservation) {
            if ($reservation->status === 'reserved') {
                $this->reservationService->fulfill($reservation);
            }
        }
    }
}
```

### ReleaseOnJobCancelled

Listens for job cancellation and releases reservations.

```php
namespace RobinsonRyan\Inventory\Jobs\Listeners;

final class ReleaseOnJobCancelled
{
    public function __construct(
        private ReservationService $reservationService
    ) {}

    public function handle($event): void
    {
        $job = $event->job;

        if (! $job instanceof JobContract) {
            return;
        }

        $reservations = $this->reservationService->getForJob(
            get_class($job),
            $job->getInventoryKey()
        );

        foreach ($reservations as $reservation) {
            if (in_array($reservation->status, ['pending', 'reserved'])) {
                $this->reservationService->release($reservation);
            }
        }
    }
}
```

## Events (Dispatched by Inventory)

### PartsReserved

Fired when parts are successfully reserved for a job.

```php
namespace RobinsonRyan\Inventory\Jobs\Events;

final class PartsReserved
{
    public function __construct(
        public readonly string $jobType,
        public readonly string $jobId,
        public readonly array $reservations,
    ) {}
}
```

### PartsConsumed

Fired when parts are consumed (job completed).

```php
namespace RobinsonRyan\Inventory\Jobs\Events;

final class PartsConsumed
{
    public function __construct(
        public readonly string $jobType,
        public readonly string $jobId,
        public readonly array $movements,
    ) {}
}
```

### PartsReleased

Fired when reservations are released (job cancelled).

```php
namespace RobinsonRyan\Inventory\Jobs\Events;

final class PartsReleased
{
    public function __construct(
        public readonly string $jobType,
        public readonly string $jobId,
        public readonly array $reservations,
    ) {}
}
```

### InsufficientStock

Fired when a reservation cannot be fulfilled due to insufficient stock.

```php
namespace RobinsonRyan\Inventory\Jobs\Events;

final class InsufficientStock
{
    public function __construct(
        public readonly string $jobType,
        public readonly string $jobId,
        public readonly string $itemId,
        public readonly float $requestedQuantity,
        public readonly float $availableQuantity,
    ) {}
}
```

## Service Provider Integration

The InventoryServiceProvider registers event listeners based on config:

```php
// In InventoryServiceProvider::registerEventListeners()

$events = config('inventory.jobs.events');

if ($events['created'] ?? null) {
    Event::listen($events['created'], ReserveOnJobCreated::class);
}

if ($events['completed'] ?? null) {
    Event::listen($events['completed'], ConsumeOnJobCompleted::class);
}

if ($events['cancelled'] ?? null) {
    Event::listen($events['cancelled'], ReleaseOnJobCancelled::class);
}
```

## Actions

### ReserveForJob

Manually reserve items for a job (when not using events).

```php
namespace RobinsonRyan\Inventory\Jobs\Actions;

final class ReserveForJob
{
    public function execute(JobContract $job): Collection; // Returns reservations
}
```

### ConsumeForJob

Manually consume reserved items for a job.

```php
namespace RobinsonRyan\Inventory\Jobs\Actions;

final class ConsumeForJob
{
    public function execute(JobContract $job): Collection; // Returns movements
}
```

### ReleaseForJob

Manually release reservations for a job.

```php
namespace RobinsonRyan\Inventory\Jobs\Actions;

final class ReleaseForJob
{
    public function execute(JobContract $job): Collection; // Returns released reservations
}
```

### UpdateJobReservations

Update reservations when job line items change.

```php
namespace RobinsonRyan\Inventory\Jobs\Actions;

final class UpdateJobReservations
{
    public function execute(JobContract $job): void;
}
```

## Tests

### Feature Tests

- `tests/Feature/Jobs/ReservationIntegrationTest.php`
  - it creates reservations on job created event
  - it consumes stock on job completed event
  - it releases reservations on job cancelled event
  - it handles insufficient stock gracefully
  - it dispatches InsufficientStock event

- `tests/Feature/Jobs/ManualActionsTest.php`
  - it manually reserves for job
  - it manually consumes for job
  - it manually releases for job
  - it updates reservations when job items change

### Unit Tests

- `tests/Unit/Jobs/ListenersTest.php`

## Directory Structure

```
src/Jobs/
├── Listeners/
│   ├── ReserveOnJobCreated.php
│   ├── ConsumeOnJobCompleted.php
│   └── ReleaseOnJobCancelled.php
├── Events/
│   ├── PartsReserved.php
│   ├── PartsConsumed.php
│   ├── PartsReleased.php
│   └── InsufficientStock.php
└── Actions/
    ├── ReserveForJob.php
    ├── ConsumeForJob.php
    ├── ReleaseForJob.php
    └── UpdateJobReservations.php

tests/Feature/Jobs/
├── ReservationIntegrationTest.php
└── ManualActionsTest.php

tests/Unit/Jobs/
└── ListenersTest.php
```

## Configuration

In `config/inventory.php`:

```php
'jobs' => [
    'model' => null, // e.g., App\Models\WorkOrder::class
    'events' => [
        'created' => null,    // e.g., App\Events\WorkOrderCreated::class
        'completed' => null,  // e.g., App\Events\WorkOrderCompleted::class
        'cancelled' => null,  // e.g., App\Events\WorkOrderCancelled::class
    ],
],
```

## Usage in Consuming Application

### 1. Implement JobContract

```php
// app/Models/WorkOrder.php
use RobinsonRyan\Inventory\Contracts\JobContract;

final class WorkOrder extends Model implements JobContract
{
    public function getInventoryKey(): string
    {
        return $this->id;
    }

    public function getInventoryTenantId(): ?string
    {
        return $this->tenant_id;
    }

    public function getItemsToReserve(): iterable
    {
        return $this->lineItems
            ->filter(fn ($line) => $line->item_id !== null)
            ->map(fn ($line) => [
                'item_id' => $line->item_id,
                'quantity' => $line->quantity,
                'line_id' => $line->id,
            ]);
    }

    public function shouldHoldReservations(): bool
    {
        return in_array($this->status, ['pending', 'scheduled', 'in_progress']);
    }
}
```

### 2. Configure Events

```php
// config/inventory.php
'jobs' => [
    'model' => \App\Models\WorkOrder::class,
    'events' => [
        'created' => \App\Events\WorkOrderCreated::class,
        'completed' => \App\Events\WorkOrderCompleted::class,
        'cancelled' => \App\Events\WorkOrderCancelled::class,
    ],
],
```

### 3. Dispatch Events in Application

```php
// When work order is created
event(new WorkOrderCreated($workOrder));

// When work order is completed
event(new WorkOrderCompleted($workOrder));

// When work order is cancelled
event(new WorkOrderCancelled($workOrder));
```
