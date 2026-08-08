<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Inventory\Contracts\JobContract;
use RobinsonRyan\Inventory\Jobs\Events\PartsConsumed;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;

final readonly class ConsumeForJob
{
    public function __construct(
        private ReservationService $reservationService,
    ) {}

    /**
     * Consume reserved items for a job (fulfill all reservations).
     *
     * @return Collection<int, StockMovement>
     */
    public function execute(JobContract $job): Collection
    {
        $movements = collect();
        $jobType = $job::class;
        $jobId = $job->getInventoryKey();

        $reservations = $this->reservationService->getForJob($jobType, $jobId);

        foreach ($reservations as $reservation) {
            if ($reservation->status === 'reserved' || $reservation->status === 'partial') {
                $movement = $this->reservationService->fulfill($reservation);
                $movements->push($movement);
            }
        }

        if ($movements->isNotEmpty()) {
            Event::dispatch(new PartsConsumed(
                jobType: $jobType,
                jobId: $jobId,
                movements: $movements->all(),
            ));
        }

        return $movements;
    }
}
