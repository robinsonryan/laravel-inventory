<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Events;

final class InsufficientStock
{
    public function __construct(
        public readonly string $jobType,
        public readonly string $jobId,
        public readonly string $itemId,
        public readonly float $requestedQuantity,
        public readonly float $availableQuantity,
    ) {}
}
