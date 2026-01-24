<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Actions;

use Illuminate\Support\Facades\Auth;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final class AddStock
{
    /**
     * Add stock to a location and create a movement record.
     */
    public function execute(AddStockData $data): StockMovement
    {
        // Find or create the stock record
        /** @var Stock */
        $stock = $this->getStockModel()::query()->firstOrCreate(
            [
                'item_id' => $data->itemId,
                'location_id' => $data->locationId,
                'lot_number' => $data->lotNumber,
                'serial_number' => $data->serialNumber,
            ],
            [
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]
        );

        // Update quantity and cost basis
        $oldQuantity = (float) $stock->quantity;
        $newQuantity = $oldQuantity + $data->quantity;

        // Calculate weighted average cost if cost provided
        if ($data->cost !== null) {
            $oldCostBasis = (float) ($stock->cost_basis ?? 0);
            $oldTotalCost = $oldQuantity * $oldCostBasis;
            $newTotalCost = $oldTotalCost + ($data->quantity * $data->cost);
            $stock->cost_basis = $newQuantity > 0 ? $newTotalCost / $newQuantity : $data->cost;
        }

        $stock->quantity = $newQuantity;
        $stock->save();

        // Create movement record
        /** @var StockMovement */
        $movement = $this->getMovementModel()::query()->create([
            'item_id' => $data->itemId,
            'from_location_id' => null,
            'to_location_id' => $data->locationId,
            'quantity' => $data->quantity,
            'cost' => $data->cost,
            'reference_type' => $data->referenceType,
            'reference_id' => $data->referenceId,
            'actor_type' => $this->getActorType(),
            'actor_id' => $this->getActorId(),
            'lot_number' => $data->lotNumber,
            'serial_number' => $data->serialNumber,
            'notes' => $data->notes,
            'metadata' => $data->metadata,
        ]);

        $movement->type = $data->type;

        return $movement;
    }

    private function getActorType(): ?string
    {
        $guard = config('inventory.actor.guard', 'web');
        $user = Auth::guard($guard)->user();

        return $user !== null ? get_class($user) : null;
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
}
