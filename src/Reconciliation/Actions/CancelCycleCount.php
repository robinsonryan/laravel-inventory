<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Actions;

use InvalidArgumentException;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Reconciliation\Services\ReconciliationService;

final class CancelCycleCount
{
    public function __construct(
        private readonly ReconciliationService $service,
    ) {}

    /**
     * Cancel a cycle count.
     */
    public function execute(CycleCount $count, ?string $reason = null): CycleCount
    {
        // Validate can cancel
        if (! $this->service->canCancel($count)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot cancel cycle count in status "%s". Only open counts can be cancelled.',
                    $count->status
                )
            );
        }

        // Update status to cancelled
        $count->status = 'cancelled';

        // Store cancellation reason in metadata
        $metadata = $count->metadata ?? [];
        $metadata['cancellation_reason'] = $reason;
        $metadata['cancelled_at'] = now()->toIso8601String();
        $count->metadata = $metadata;

        $count->save();

        return $count->fresh(['lines', 'location']);
    }
}
