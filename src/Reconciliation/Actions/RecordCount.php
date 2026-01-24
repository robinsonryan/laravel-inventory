<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Actions;

use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCountLine;
use RobinsonRyan\Inventory\Reconciliation\TagDefinitions\CycleCountStatusDefinition;

final class RecordCount
{
    /**
     * Record a counted quantity for a cycle count line.
     */
    public function execute(
        CycleCountLine $line,
        float $countedQuantity,
        ?string $notes = null
    ): CycleCountLine {
        // Validate the cycle count is in a countable state
        $cycleCount = $line->cycleCount;
        $this->validateCountable($cycleCount);

        // Update to in_progress if still draft
        if ($cycleCount->status === 'draft') {
            $cycleCount->status = 'in_progress';
            $cycleCount->started_at = now();
            $cycleCount->save();
        }

        // Calculate variance
        $variance = $countedQuantity - (float) $line->expected_quantity;

        // Update the line
        $line->counted_quantity = $countedQuantity;
        $line->variance = $variance;
        $line->counted_at = now();
        $line->counted_by = $this->getActorId();

        if ($notes !== null) {
            $line->notes = $notes;
        }

        $line->save();

        return $line->fresh(['cycleCount', 'item', 'stock']);
    }

    /**
     * Validate the cycle count is in a state that allows counting.
     */
    private function validateCountable(CycleCount $cycleCount): void
    {
        $allowedStatuses = ['draft', 'in_progress'];

        if (! in_array($cycleCount->status, $allowedStatuses, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot record count for cycle count in status "%s". Count must be in "draft" or "in_progress" status.',
                    $cycleCount->status
                )
            );
        }
    }

    private function getActorId(): ?string
    {
        $guard = config('inventory.actor.guard', 'web');
        $user = Auth::guard($guard)->user();

        return $user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null;
    }
}
