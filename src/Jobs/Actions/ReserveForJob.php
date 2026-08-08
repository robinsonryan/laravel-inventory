<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Events\InsufficientStock;
use RobinsonRyan\Inventory\Jobs\Events\PartsReserved;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;

final readonly class ReserveForJob
{
    public function __construct(
        private ReservationService $reservationService,
    ) {}

    /**
     * Reserve items for a job.
     *
     * @return Collection<int, Reservation>
     */
    public function execute(JobContract $job): Collection
    {
        $reservations = collect();
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

            $reservation = $this->reservationService->reserve(new ReservationData(
                itemId: $item['item_id'],
                jobType: $jobType,
                jobId: $jobId,
                quantity: $quantity,
                lineId: $item['line_id'] ?? null,
                expiryHours: config('inventory.rules.reservation_expiry_hours'),
            ));

            $reservations->push($reservation);
        }

        if ($reservations->isNotEmpty()) {
            Event::dispatch(new PartsReserved(
                jobType: $jobType,
                jobId: $jobId,
                reservations: $reservations->all(),
            ));
        }

        return $reservations;
    }

    private function getAvailableQuantity(string $itemId): float
    {
        return app(\RobinsonRyan\Inventory\Stock\Services\StockService::class)
            ->getAvailable($itemId);
    }
}
