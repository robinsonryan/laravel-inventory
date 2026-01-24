<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Reconciliation\Data\CycleCountData;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCountLine;
use RobinsonRyan\Inventory\Reconciliation\Services\ReconciliationService;
use RobinsonRyan\Inventory\Stock\Models\Stock;

final class InitiateCycleCount
{
    public function __construct(
        private readonly ReconciliationService $service,
    ) {}

    /**
     * Create a new cycle count session.
     */
    public function execute(CycleCountData $data): CycleCount
    {
        return DB::transaction(function () use ($data): CycleCount {
            $countNumber = $this->service->generateCountNumber();

            /** @var CycleCount */
            $cycleCount = $this->getCycleCountModel()::query()->create([
                'count_number' => $countNumber,
                'location_id' => $data->locationId,
                'scheduled_date' => $data->scheduledDate,
                'notes' => $data->notes,
                'created_by' => $this->getActorId(),
            ]);

            $cycleCount->status = 'draft';

            // Create lines based on item selection
            $this->createLines($cycleCount, $data);

            return $cycleCount->fresh(['lines', 'location']);
        });
    }

    /**
     * Create cycle count lines.
     */
    private function createLines(CycleCount $cycleCount, CycleCountData $data): void
    {
        $stockQuery = $this->getStockModel()::query();

        // If location specified, filter by location
        if ($data->locationId !== null) {
            $stockQuery->where('location_id', $data->locationId);
        }

        // If specific items specified, filter by items
        if ($data->itemIds !== null && count($data->itemIds) > 0) {
            $stockQuery->whereIn('item_id', $data->itemIds);
        }

        // Only include stock records with positive quantity
        $stockQuery->where('quantity', '>', 0);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Stock> $stocks */
        $stocks = $stockQuery->get();

        foreach ($stocks as $stock) {
            $this->getCycleCountLineModel()::query()->create([
                'cycle_count_id' => $cycleCount->id,
                'item_id' => $stock->item_id,
                'location_id' => $stock->location_id,
                'stock_id' => $stock->id,
                'expected_quantity' => $stock->quantity,
                'lot_number' => $stock->lot_number,
                'serial_number' => $stock->serial_number,
            ]);
        }
    }

    private function getActorId(): ?string
    {
        $guard = config('inventory.actor.guard', 'web');
        $user = Auth::guard($guard)->user();

        return $user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null;
    }

    /**
     * @return class-string<CycleCount>
     */
    private function getCycleCountModel(): string
    {
        return config('inventory.models.cycle_count', CycleCount::class);
    }

    /**
     * @return class-string<CycleCountLine>
     */
    private function getCycleCountLineModel(): string
    {
        return config('inventory.models.cycle_count_line', CycleCountLine::class);
    }

    /**
     * @return class-string<Stock>
     */
    private function getStockModel(): string
    {
        return config('inventory.models.stock', Stock::class);
    }
}
