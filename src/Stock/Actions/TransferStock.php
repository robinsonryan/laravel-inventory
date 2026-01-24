<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Actions;

use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Stock\Data\TransferData;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final class TransferStock
{
    /**
     * Transfer stock between locations and create a movement record.
     */
    public function execute(TransferData $data): StockMovement
    {
        if ($data->fromLocationId === $data->toLocationId) {
            throw new InvalidArgumentException('Cannot transfer to the same location.');
        }

        // Find the source stock record
        /** @var Stock|null */
        $sourceStock = $this->getStockModel()::query()
            ->where('item_id', $data->itemId)
            ->where('location_id', $data->fromLocationId)
            ->when($data->lotNumber !== null, fn ($q) => $q->where('lot_number', $data->lotNumber))
            ->when($data->serialNumber !== null, fn ($q) => $q->where('serial_number', $data->serialNumber))
            ->first();

        if ($sourceStock === null) {
            throw new InvalidArgumentException(
                'No stock record found at the source location.'
            );
        }

        $currentQuantity = (float) $sourceStock->quantity;
        $availableQuantity = $currentQuantity - (float) $sourceStock->reserved_quantity;

        // Check if we can transfer the requested quantity
        $allowNegative = config('inventory.rules.allow_negative_stock', false);

        if (! $allowNegative && $data->quantity > $availableQuantity) {
            throw new InvalidArgumentException(
                sprintf(
                    'Insufficient stock to transfer. Requested: %.4f, Available: %.4f',
                    $data->quantity,
                    $availableQuantity
                )
            );
        }

        // Update source stock
        $sourceStock->quantity = $currentQuantity - $data->quantity;
        $sourceStock->save();

        // Find or create destination stock record
        /** @var Stock */
        $destStock = $this->getStockModel()::query()->firstOrCreate(
            [
                'item_id' => $data->itemId,
                'location_id' => $data->toLocationId,
                'lot_number' => $data->lotNumber,
                'serial_number' => $data->serialNumber,
            ],
            [
                'quantity' => 0,
                'reserved_quantity' => 0,
                'cost_basis' => $sourceStock->cost_basis,
            ]
        );

        // Update destination stock
        $destStock->quantity = (float) $destStock->quantity + $data->quantity;
        $destStock->save();

        // Create movement record
        /** @var StockMovement */
        $movement = $this->getMovementModel()::query()->create([
            'item_id' => $data->itemId,
            'from_location_id' => $data->fromLocationId,
            'to_location_id' => $data->toLocationId,
            'quantity' => $data->quantity,
            'cost' => $sourceStock->cost_basis,
            'actor_type' => $this->getActorType(),
            'actor_id' => $this->getActorId(),
            'lot_number' => $data->lotNumber,
            'serial_number' => $data->serialNumber,
            'notes' => $data->notes,
        ]);

        $movement->type = 'transfer';

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
