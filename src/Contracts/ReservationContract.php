<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Contracts;

use Illuminate\Support\Collection;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

/**
 * The reservation behaviour the Jobs listeners depend on.
 *
 * Deliberately narrow: it names exactly what a host application's work-order
 * events drive, and nothing else. The reservation service satisfies it, and a
 * host application may bind its own implementation in place.
 */
interface ReservationContract
{
    /**
     * Create a reservation for an item.
     */
    public function reserve(ReservationData $data): Reservation;

    /**
     * Release a reservation without consuming the stock it held.
     */
    public function release(Reservation $reservation): void;

    /**
     * Consume the stock a reservation held, returning the movement that records it.
     */
    public function fulfill(Reservation $reservation): StockMovement;

    /**
     * Every reservation held for one job.
     *
     * @return Collection<int, Reservation>
     */
    public function getForJob(string $jobType, string $jobId): Collection;

    /**
     * Whether an item has enough available stock to reserve the given quantity.
     */
    public function canReserve(string $itemId, float $quantity, ?string $locationId = null): bool;
}
