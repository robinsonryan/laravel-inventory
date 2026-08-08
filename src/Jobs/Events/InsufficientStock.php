<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Events;

final readonly class InsufficientStock
{
    public function __construct(
        public string $jobType,
        public string $jobId,
        public string $itemId,
        public float $requestedQuantity,
        public float $availableQuantity,
    ) {}
}
