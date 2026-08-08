<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Jobs\Events;

use RobinsonRyan\Inventory\Exceptions\CannotReserveException;

/**
 * A job line could not be reserved.
 *
 * Two different situations reach a host app through this event, and confusing
 * them costs money in opposite directions:
 *
 * - Not enough stock exists. `$availableQuantity` is below what was asked for
 *   and `isFragmented()` is false. Reordering is the answer.
 * - Enough stock exists, but no one location holds it all — a reservation is
 *   held against a single stock record. `isFragmented()` is true and the
 *   `largestLocation*` properties describe the fullest location. Reordering
 *   here would buy stock the warehouse already has; consolidating it is the
 *   answer.
 *
 * `$availableQuantity` is always the sum across every location, whichever case
 * this is.
 */
final readonly class InsufficientStock
{
    public function __construct(
        public string $jobType,
        public string $jobId,
        public string $itemId,
        public float $requestedQuantity,
        public float $availableQuantity,
        public ?float $largestLocationQuantity = null,
        public ?string $largestLocationId = null,
        public ?string $largestLocationName = null,
    ) {}

    /**
     * Build the event from a refusal the reservation service raised.
     */
    public static function fromRefusal(string $jobType, string $jobId, CannotReserveException $refusal): self
    {
        return new self(
            jobType: $jobType,
            jobId: $jobId,
            itemId: $refusal->itemId,
            requestedQuantity: $refusal->requestedQuantity,
            availableQuantity: $refusal->availableQuantity,
            largestLocationQuantity: $refusal->largestLocationQuantity,
            largestLocationId: $refusal->largestLocationId,
            largestLocationName: $refusal->largestLocationName,
        );
    }

    /**
     * Whether the stock exists and is merely spread out, rather than missing.
     */
    public function isFragmented(): bool
    {
        return $this->largestLocationQuantity !== null;
    }
}
