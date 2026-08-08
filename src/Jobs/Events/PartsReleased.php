<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Events;

final readonly class PartsReleased
{
    /**
     * @param  array<int, \RobinsonRyan\Inventory\Stock\Models\Reservation>  $reservations
     */
    public function __construct(
        public string $jobType,
        public string $jobId,
        public array $reservations,
    ) {}
}
