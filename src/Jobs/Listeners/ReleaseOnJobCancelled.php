<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Listeners;

use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Events\PartsReleased;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;

final readonly class ReleaseOnJobCancelled
{
    public function __construct(
        private ReservationService $reservationService,
    ) {}

    public function handle(object $event): void
    {
        $job = $event->job ?? $event->workOrder ?? $event->model ?? null;

        if (! $job instanceof JobContract) {
            return;
        }

        $releasedReservations = [];
        $jobType = $job::class;
        $jobId = $job->getInventoryKey();

        $reservations = $this->reservationService->getForJob($jobType, $jobId);

        foreach ($reservations as $reservation) {
            if (in_array($reservation->status, ['pending', 'reserved', 'partial'])) {
                $this->reservationService->release($reservation);
                $reservation->refresh();
                $releasedReservations[] = $reservation;
            }
        }

        if (count($releasedReservations) > 0) {
            Event::dispatch(new PartsReleased(
                jobType: $jobType,
                jobId: $jobId,
                reservations: $releasedReservations,
            ));
        }
    }
}
