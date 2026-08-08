<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Actions;

use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Stock\Data\RemoveStockData;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final class RemoveStock
{
    /**
     * Remove stock from a location and create a movement record.
     */
    public function execute(RemoveStockData $data): StockMovement
    {
        // Find the stock record
        /** @var Stock|null */
        $stock = $this->getStockModel()::query()
            ->where('item_id', $data->itemId)
            ->where('location_id', $data->locationId)
            ->when($data->lotNumber !== null, fn ($q) => $q->where('lot_number', $data->lotNumber))
            ->when($data->serialNumber !== null, fn ($q) => $q->where('serial_number', $data->serialNumber))
            ->first();

        if ($stock === null) {
            throw new InvalidArgumentException(
                'No stock record found for the specified item and location.',
            );
        }

        $currentQuantity = (float) $stock->quantity;
        $availableQuantity = $currentQuantity - (float) $stock->reserved_quantity;

        // Check if we can remove the requested quantity
        $allowNegative = config('inventory.rules.allow_negative_stock', false);

        if (! $allowNegative && $data->quantity > $availableQuantity) {
            throw new InvalidArgumentException(
                sprintf(
                    'Insufficient stock. Requested: %.4f, Available: %.4f',
                    $data->quantity,
                    $availableQuantity,
                ),
            );
        }

        // Update quantity
        $stock->quantity = $currentQuantity - $data->quantity;
        $stock->save();

        // Create movement record
        /** @var StockMovement */
        $movement = $this->getMovementModel()::query()->create([
            'item_id' => $data->itemId,
            'from_location_id' => $data->locationId,
            'to_location_id' => null,
            'quantity' => $data->quantity,
            'cost' => $stock->cost_basis,
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
}
