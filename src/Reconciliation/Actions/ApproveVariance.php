<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Actions;

use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCountLine;
use RobinsonRyan\Inventory\Reconciliation\Models\VarianceAdjustment;
use RobinsonRyan\Inventory\Reconciliation\Services\ReconciliationService;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final readonly class ApproveVariance
{
    public function __construct(
        private ReconciliationService $service,
    ) {}

    /**
     * Approve variances and create adjustment movements.
     */
    public function execute(CycleCount $count, ?string $reason = null): CycleCount
    {
        // Validate can approve
        if (! $this->service->canApprove($count)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot approve cycle count in status "%s". Count must be "pending" and fully counted.',
                    $count->status,
                ),
            );
        }

        return DB::transaction(function () use ($count, $reason): CycleCount {
            $actorId = $this->getActorId();
            $actorType = $this->getActorType();
            $now = now();

            // Process each line with variance
            foreach ($count->lines as $line) {
                if ($line->variance !== null && (float) $line->variance !== 0.0) {
                    $this->processVariance($count, $line, $reason, $actorId, $actorType, $now);
                }
            }

            // Update cycle count status
            $count->status = 'approved';
            $count->approved_at = $now;
            $count->approved_by = $actorId;
            $count->save();

            return $count->fresh(['lines', 'adjustments', 'location']) ?? $count;
        });
    }

    /**
     * Process a single variance line - create movement and adjustment record.
     */
    private function processVariance(
        CycleCount $count,
        CycleCountLine $line,
        ?string $reason,
        ?string $actorId,
        ?string $actorType,
        DateTimeInterface $now,
    ): void {
        $variance = (float) $line->variance;

        // Get or create stock record
        /** @var Stock|null */
        $stock = $line->stock;

        if ($stock === null) {
            /** @var Stock */
            $stock = $this->getStockModel()::query()->firstOrCreate(
                [
                    'item_id' => $line->item_id,
                    'location_id' => $line->location_id,
                    'lot_number' => $line->lot_number,
                    'serial_number' => $line->serial_number,
                ],
                [
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                ],
            );
        }

        $quantityBefore = (float) $stock->quantity;
        $quantityAfter = (float) $line->counted_quantity;

        // Determine movement direction
        $isIncrease = $variance > 0;
        $fromLocation = $isIncrease ? null : $line->location_id;
        $toLocation = $isIncrease ? $line->location_id : null;

        // Create stock movement
        $notes = sprintf(
            'Cycle count adjustment (Count: %s)%s',
            $count->count_number,
            $reason !== null ? " - Reason: {$reason}" : '',
        );

        /** @var StockMovement */
        $movement = $this->getMovementModel()::query()->create([
            'tenant_id' => $count->tenant_id,
            'item_id' => $line->item_id,
            'from_location_id' => $fromLocation,
            'to_location_id' => $toLocation,
            'quantity' => abs($variance),
            'cost' => $stock->cost_basis,
            'reference_type' => CycleCount::class,
            'reference_id' => $count->id,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'lot_number' => $line->lot_number,
            'serial_number' => $line->serial_number,
            'notes' => $notes,
            'metadata' => [
                'cycle_count_id' => $count->id,
                'cycle_count_line_id' => $line->id,
                'expected_quantity' => (float) $line->expected_quantity,
                'counted_quantity' => (float) $line->counted_quantity,
            ],
        ]);

        $movement->type = 'adjustment';

        // Update stock quantity
        $stock->quantity = $quantityAfter;
        $stock->save();

        // Calculate cost impact
        $costImpact = $variance * (float) ($stock->cost_basis ?? 0);

        // Create variance adjustment record
        $this->getVarianceAdjustmentModel()::query()->create([
            'tenant_id' => $count->tenant_id,
            'cycle_count_id' => $count->id,
            'cycle_count_line_id' => $line->id,
            'item_id' => $line->item_id,
            'location_id' => $line->location_id,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'variance' => $variance,
            'cost_impact' => $costImpact,
            'stock_movement_id' => $movement->id,
            'reason' => $reason,
            'approved_by' => $actorId,
            'approved_at' => $now,
        ]);
    }

    private function getActorType(): ?string
    {
        $guard = config('inventory.actor.guard', 'web');
        $user = Auth::guard($guard)->user();

        return $user !== null ? $user::class : null;
    }

    private function getActorId(): ?string
    {
        $guard = config('inventory.actor.guard', 'web');
        $user = Auth::guard($guard)->user();

        return $user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null;
    }

    /**
     * @return class-string<Stock>
     */
    private function getStockModel(): string
    {
        return config('inventory.models.stock', Stock::class);
    }

    /**
     * @return class-string<StockMovement>
     */
    private function getMovementModel(): string
    {
        return config('inventory.models.stock_movement', StockMovement::class);
    }

    /**
     * @return class-string<VarianceAdjustment>
     */
    private function getVarianceAdjustmentModel(): string
    {
        return config('inventory.models.variance_adjustment', VarianceAdjustment::class);
    }
}
