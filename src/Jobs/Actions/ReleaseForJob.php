<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Events\PartsReleased;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;

final class ReleaseForJob
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    /**
     * Release all reservations for a job (cancel without consuming).
     *
     * @return Collection<int, Reservation>
     */
    public function execute(JobContract $job): Collection
    {
        $releasedReservations = collect();
        $jobType = get_class($job);
        $jobId = $job->getInventoryKey();

        $reservations = $this->reservationService->getForJob($jobType, $jobId);

        foreach ($reservations as $reservation) {
            if (in_array($reservation->status, ['pending', 'reserved', 'partial'])) {
                $this->reservationService->release($reservation);
                $reservation->refresh();
                $releasedReservations->push($reservation);
            }
        }

        if ($releasedReservations->isNotEmpty()) {
            Event::dispatch(new PartsReleased(
                jobType: $jobType,
                jobId: $jobId,
                reservations: $releasedReservations->all(),
            ));
        }

        return $releasedReservations;
    }
}
