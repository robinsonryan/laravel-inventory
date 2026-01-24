<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Events;

final class PartsConsumed
{
    /**
     * @param  array<int, \RobinsonRyan\Inventory\Stock\Models\StockMovement>  $movements
     */
    public function __construct(
        public readonly string $jobType,
        public readonly string $jobId,
        public readonly array $movements,
    ) {}
}
