<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Actions;

use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Stock\Data\AdjustStockData;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final class AdjustStock
{
    /**
     * Adjust stock to a specific quantity and create a movement record.
     */
    public function execute(AdjustStockData $data): StockMovement
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

        $currentQuantity = (float) $stock->quantity;
        $difference = $data->newQuantity - $currentQuantity;

        if ($difference === 0.0) {
            throw new InvalidArgumentException('New quantity is the same as current quantity.');
        }

        $allowNegative = config('inventory.rules.allow_negative_stock', false);

        // Check if adjustment would result in negative stock
        if (! $allowNegative && $data->newQuantity < 0) {
            throw new InvalidArgumentException('Cannot adjust stock to negative quantity.');
        }

        // Check if adjustment would violate reserved quantity
        if (! $allowNegative && $data->newQuantity < (float) $stock->reserved_quantity) {
            throw new InvalidArgumentException(
                'Cannot adjust quantity below reserved quantity.'
            );
        }

        // Update stock
        $stock->quantity = $data->newQuantity;
        $stock->save();

        // Create movement record - positive difference is "add", negative is "remove"
        $isIncrease = $difference > 0;
        $fromLocation = $isIncrease ? null : $data->locationId;
        $toLocation = $isIncrease ? $data->locationId : null;

        $notes = sprintf(
            'Adjustment: %s - Reason: %s%s',
            $data->reason,
            $data->notes ?? '',
            $data->notes !== null ? '' : ' (no notes)'
        );

        /** @var StockMovement */
        $movement = $this->getMovementModel()::query()->create([
            'item_id' => $data->itemId,
            'from_location_id' => $fromLocation,
            'to_location_id' => $toLocation,
            'quantity' => abs($difference),
            'cost' => $stock->cost_basis,
            'actor_type' => $this->getActorType(),
            'actor_id' => $this->getActorId(),
            'lot_number' => $data->lotNumber,
            'serial_number' => $data->serialNumber,
            'notes' => $notes,
            'metadata' => [
                'adjustment_reason' => $data->reason,
                'previous_quantity' => $currentQuantity,
                'new_quantity' => $data->newQuantity,
            ],
        ]);

        $movement->type = 'adjustment';

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
