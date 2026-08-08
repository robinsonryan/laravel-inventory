<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Actions;

use InvalidArgumentException;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;

final class CompleteCounting
{
    /**
     * Mark counting as complete, ready for approval.
     */
    public function execute(CycleCount $count): CycleCount
    {
        // Validate the count can be completed
        $this->validateCompletable($count);

        // Update status to pending
        $count->status = 'pending';
        $count->completed_at = now();
        $count->save();

        return $count->fresh(['lines', 'location']) ?? $count;
    }

    /**
     * Validate the cycle count can be completed.
     */
    private function validateCompletable(CycleCount $count): void
    {
        // Taxon slugifies every tag value it stores, so the persisted spelling is
        // "in-progress" — never the underscored form.
        if ($count->status !== 'in-progress') {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot complete cycle count in status "%s". Count must be "in-progress".',
                    $count->status,
                ),
            );
        }

        if (! $count->isFullyCounted()) {
            $uncountedLines = $count->lines()->whereNull('counted_at')->count();

            throw new InvalidArgumentException(
                sprintf(
                    'Cannot complete cycle count. %d line(s) have not been counted.',
                    $uncountedLines,
                ),
            );
        }
    }
}
