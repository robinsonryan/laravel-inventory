<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Listeners;

use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Events\PartsConsumed;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;

final class ConsumeOnJobCompleted
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

        $movements = [];
        $jobType = get_class($job);
        $jobId = $job->getInventoryKey();

        $reservations = $this->reservationService->getForJob($jobType, $jobId);

        foreach ($reservations as $reservation) {
            if ($reservation->status === 'reserved' || $reservation->status === 'partial') {
                $movements[] = $this->reservationService->fulfill($reservation);
            }
        }

        if (count($movements) > 0) {
            Event::dispatch(new PartsConsumed(
                jobType: $jobType,
                jobId: $jobId,
                movements: $movements,
            ));
        }
    }
}
