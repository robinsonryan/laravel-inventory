<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Events;

final readonly class PartsConsumed
{
    /**
     * @param  array<int, \RobinsonRyan\Inventory\Stock\Models\StockMovement>  $movements
     */
    public function __construct(
        public string $jobType,
        public string $jobId,
        public array $movements,
    ) {}
}
