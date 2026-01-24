<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Stock\Actions\AddStock;
use RobinsonRyan\Inventory\Stock\Actions\AdjustStock;
use RobinsonRyan\Inventory\Stock\Actions\RemoveStock;
use RobinsonRyan\Inventory\Stock\Actions\TransferStock;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Data\AdjustStockData;
use RobinsonRyan\Inventory\Stock\Data\RemoveStockData;
use RobinsonRyan\Inventory\Stock\Data\TransferData;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final class StockService
{
    public function __construct(
        private readonly AddStock $addStock,
        private readonly RemoveStock $removeStock,
        private readonly TransferStock $transferStock,
        private readonly AdjustStock $adjustStock,
    ) {}

    /**
     * Get current stock level for an item, optionally at a specific location.
     */
    public function getQuantity(string $itemId, ?string $locationId = null): float
    {
        $query = $this->getStockModel()::query()
            ->where('item_id', $itemId);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return (float) $query->sum('quantity');
    }

    /**
     * Get available (unreserved) stock for an item, optionally at a specific location.
     */
    public function getAvailable(string $itemId, ?string $locationId = null): float
    {
        $query = $this->getStockModel()::query()
            ->where('item_id', $itemId);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        $quantity = (float) $query->sum('quantity');
        $reserved = (float) $query->sum('reserved_quantity');

        return $quantity - $reserved;
    }

    /**
     * Add stock to a location (creates movement).
     */
    public function addStock(AddStockData $data): StockMovement
    {
        return DB::transaction(fn () => $this->addStock->execute($data));
    }

    /**
     * Remove stock from a location (creates movement).
     */
    public function removeStock(RemoveStockData $data): StockMovement
    {
        return DB::transaction(fn () => $this->removeStock->execute($data));
    }

    /**
     * Transfer stock between locations.
     */
    public function transfer(TransferData $data): StockMovement
    {
        return DB::transaction(fn () => $this->transferStock->execute($data));
    }

    /**
     * Adjust stock to a specific quantity (for corrections).
     */
    public function adjust(AdjustStockData $data): StockMovement
    {
        return DB::transaction(fn () => $this->adjustStock->execute($data));
    }

    /**
     * Get all stock records for an item across all locations.
     *
     * @return Collection<int, Stock>
     */
    public function getStockByItem(string $itemId): Collection
    {
        return $this->getStockModel()::query()
            ->where('item_id', $itemId)
            ->with('location')
            ->get();
    }

    /**
     * Get all stock records at a specific location.
     *
     * @return Collection<int, Stock>
     */
    public function getStockByLocation(string $locationId): Collection
    {
        return $this->getStockModel()::query()
            ->where('location_id', $locationId)
            ->with('item')
            ->get();
    }

    /**
     * Find or create a stock record for an item at a location.
     */
    public function findOrCreateStock(
        string $itemId,
        string $locationId,
        ?string $lotNumber = null,
        ?string $serialNumber = null
    ): Stock {
        /** @var Stock */
        return $this->getStockModel()::query()->firstOrCreate(
            [
                'item_id' => $itemId,
                'location_id' => $locationId,
                'lot_number' => $lotNumber,
                'serial_number' => $serialNumber,
            ],
            [
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]
        );
    }

    /**
     * @return class-string<Stock>
     */
    private function getStockModel(): string
    {
        return config('inventory.models.stock', Stock::class);
    }
}
