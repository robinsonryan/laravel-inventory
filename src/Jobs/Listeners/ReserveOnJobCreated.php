<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Listeners;

use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Contracts\ReservationContract;
use RobinsonRyan\Inventory\Exceptions\CannotReserveException;
use RobinsonRyan\Inventory\Jobs\Events\InsufficientStock;
use RobinsonRyan\Inventory\Jobs\Events\PartsReserved;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;

final readonly class ReserveOnJobCreated
{
    public function __construct(
        private ReservationContract $reservationService,
    ) {}

    public function handle(object $event): void
    {
        $job = $event->job ?? $event->workOrder ?? $event->model ?? null;

        if (! $job instanceof JobContract) {
            return;
        }

        $reservations = [];
        $jobType = $job::class;
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

            try {
                $reservations[] = $this->reservationService->reserve(new ReservationData(
                    itemId: $item['item_id'],
                    jobType: $jobType,
                    jobId: $jobId,
                    quantity: $quantity,
                    lineId: $item['line_id'] ?? null,
                    expiryHours: config('inventory.rules.reservation_expiry_hours'),
                ));
            } catch (CannotReserveException $refusal) {
                // Enough stock in total, spread over more than one location, is
                // ordinary warehouse life, and a reservation is held against a
                // single stock record. The host app hears about it through the
                // event it already understands — which says whether this is a
                // shortage to reorder or stock to consolidate. Lines reserved
                // before this one stay reserved and are still announced below;
                // cancelling the job releases them, as it does for a line the
                // availability check rejected.
                Event::dispatch(InsufficientStock::fromRefusal($jobType, $jobId, $refusal));
            }
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
