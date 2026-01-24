<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Actions;

use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Events\InsufficientStock;
use RobinsonRyan\Inventory\Jobs\Events\PartsReleased;
use RobinsonRyan\Inventory\Jobs\Events\PartsReserved;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;

final class UpdateJobReservations
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    /**
     * Update reservations when job line items change.
     *
     * This compares current reservations with the job's items and:
     * - Releases reservations for items no longer on the job
     * - Adjusts quantities for changed items
     * - Creates new reservations for added items
     */
    public function execute(JobContract $job): void
    {
        $jobType = get_class($job);
        $jobId = $job->getInventoryKey();

        // Get current reservations indexed by line_id (or item_id if no line_id)
        $currentReservations = $this->reservationService->getForJob($jobType, $jobId)
            ->filter(fn ($r) => in_array($r->status, ['pending', 'reserved', 'partial']))
            ->keyBy(fn ($r) => $r->line_id ?? $r->item_id);

        // Get desired items indexed by line_id (or item_id if no line_id)
        $desiredItems = collect($job->getItemsToReserve())
            ->keyBy(fn ($item) => $item['line_id'] ?? $item['item_id']);

        $newReservations = [];
        $releasedReservations = [];

        // Release reservations that are no longer needed
        foreach ($currentReservations as $key => $reservation) {
            if (! $desiredItems->has($key)) {
                $this->reservationService->release($reservation);
                $reservation->refresh();
                $releasedReservations[] = $reservation;
            }
        }

        // Create or update reservations
        foreach ($desiredItems as $key => $item) {
            $existingReservation = $currentReservations->get($key);
            $requestedQuantity = (float) $item['quantity'];

            if ($existingReservation === null) {
                // New item - create reservation
                if (! $this->reservationService->canReserve($item['item_id'], $requestedQuantity)) {
                    Event::dispatch(new InsufficientStock(
                        jobType: $jobType,
                        jobId: $jobId,
                        itemId: $item['item_id'],
                        requestedQuantity: $requestedQuantity,
                        availableQuantity: $this->getAvailableQuantity($item['item_id']),
                    ));

                    continue;
                }

                $newReservations[] = $this->reservationService->reserve(new ReservationData(
                    itemId: $item['item_id'],
                    jobType: $jobType,
                    jobId: $jobId,
                    quantity: $requestedQuantity,
                    lineId: $item['line_id'] ?? null,
                    expiryHours: config('inventory.rules.reservation_expiry_hours'),
                ));
            } elseif ((float) $existingReservation->quantity !== $requestedQuantity) {
                // Quantity changed - release old and create new
                $this->reservationService->release($existingReservation);
                $existingReservation->refresh();
                $releasedReservations[] = $existingReservation;

                if (! $this->reservationService->canReserve($item['item_id'], $requestedQuantity)) {
                    Event::dispatch(new InsufficientStock(
                        jobType: $jobType,
                        jobId: $jobId,
                        itemId: $item['item_id'],
                        requestedQuantity: $requestedQuantity,
                        availableQuantity: $this->getAvailableQuantity($item['item_id']),
                    ));

                    continue;
                }

                $newReservations[] = $this->reservationService->reserve(new ReservationData(
                    itemId: $item['item_id'],
                    jobType: $jobType,
                    jobId: $jobId,
                    quantity: $requestedQuantity,
                    lineId: $item['line_id'] ?? null,
                    expiryHours: config('inventory.rules.reservation_expiry_hours'),
                ));
            }
            // If quantity unchanged, keep existing reservation
        }

        // Dispatch events
        if (count($releasedReservations) > 0) {
            Event::dispatch(new PartsReleased(
                jobType: $jobType,
                jobId: $jobId,
                reservations: $releasedReservations,
            ));
        }

        if (count($newReservations) > 0) {
            Event::dispatch(new PartsReserved(
                jobType: $jobType,
                jobId: $jobId,
                reservations: $newReservations,
            ));
        }
    }

    private function getAvailableQuantity(string $itemId): float
    {
        return app(\RobinsonRyan\Inventory\Stock\Services\StockService::class)
            ->getAvailable($itemId);
    }
}
