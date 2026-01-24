<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Listeners;

use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Events\InsufficientStock;
use RobinsonRyan\Inventory\Jobs\Events\PartsReserved;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;

final class ReserveOnJobCreated
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    public function handle(object $event): void
    {
        $job = $event->job ?? $event->workOrder ?? $event->model ?? null;

        if (! $job instanceof JobContract) {
            return;
        }

        $reservations = [];
        $jobType = get_class($job);
        $jobId = $job->getInventoryKey();

        foreach ($job->getItemsToReserve() as $item) {
            $quantity = (float) $item['quantity'];

            if (! $this->reservationService->canReserve($item['item_id'], $quantity)) {
                Event::dispatch(new InsufficientStock(
                    jobType: $jobType,
                    jobId: $jobId,
                    itemId: $item['item_id'],
                    requestedQuantity: $quantity,
                    availableQuantity: $this->getAvailableQuantity($item['item_id']),
                ));

                continue;
            }

            $reservations[] = $this->reservationService->reserve(new ReservationData(
                itemId: $item['item_id'],
                jobType: $jobType,
                jobId: $jobId,
                quantity: $quantity,
                lineId: $item['line_id'] ?? null,
                expiryHours: config('inventory.rules.reservation_expiry_hours'),
            ));
        }

        if (count($reservations) > 0) {
            Event::dispatch(new PartsReserved(
                jobType: $jobType,
                jobId: $jobId,
                reservations: $reservations,
            ));
        }
    }

    private function getAvailableQuantity(string $itemId): float
    {
        return app(\RobinsonRyan\Inventory\Stock\Services\StockService::class)
            ->getAvailable($itemId);
    }
}
