<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Data\AdjustStockData;
use RobinsonRyan\Inventory\Stock\Data\RemoveStockData;
use RobinsonRyan\Inventory\Stock\Data\TransferData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;
use RobinsonRyan\Inventory\Stock\Services\StockService;

beforeEach(function (): void {
    $this->stockService = App::make(StockService::class);
    $this->location = Location::factory()->warehouse()->create();
    $this->itemId = (string) Symfony\Component\Uid\Uuid::v7();
});

describe('Stock Model', function (): void {
    it('creates a stock record', function (): void {
        $stock = Stock::factory()
            ->forItem($this->itemId)
            ->forLocation($this->location)
            ->withQuantity(100)
            ->withCost(25.50)
            ->create();

        expect($stock)->toBeInstanceOf(Stock::class)
            ->and($stock->item_id)->toBe($this->itemId)
            ->and($stock->location_id)->toBe($this->location->id)
            ->and((float) $stock->quantity)->toBe(100.0)
            ->and((float) $stock->cost_basis)->toBe(25.50);
    });

    it('calculates available quantity', function (): void {
        $stock = Stock::factory()
            ->forItem($this->itemId)
            ->forLocation($this->location)
            ->withQuantity(100)
            ->withReserved(30)
            ->create();

        expect($stock->available_quantity)->toBe(70.0);
    });

    it('handles lot numbers', function (): void {
        $stock = Stock::factory()
            ->forItem($this->itemId)
            ->forLocation($this->location)
            ->withLotNumber('LOT-2026-001')
            ->create();

        expect($stock->lot_number)->toBe('LOT-2026-001');
    });

    it('handles serial numbers', function (): void {
        $stock = Stock::factory()
            ->forItem($this->itemId)
            ->forLocation($this->location)
            ->withSerialNumber('SN-12345')
            ->create();

        expect($stock->serial_number)->toBe('SN-12345')
            ->and((float) $stock->quantity)->toBe(1.0);
    });

    it('handles expiry dates', function (): void {
        $expiryDate = now()->addMonths(6);

        $stock = Stock::factory()
            ->forItem($this->itemId)
            ->forLocation($this->location)
            ->withExpiry($expiryDate)
            ->create();

        expect($stock->expiry_date->toDateString())->toBe($expiryDate->toDateString());
    });
});

describe('StockService - Add Stock', function (): void {
    it('adds stock to a location', function (): void {
        $data = new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 50,
            type: 'purchase',
            cost: 10.00,
        );

        $movement = $this->stockService->addStock($data);

        expect($movement)->toBeInstanceOf(StockMovement::class)
            ->and($movement->type)->toBe('purchase')
            ->and((float) $movement->quantity)->toBe(50.0);

        $quantity = $this->stockService->getQuantity($this->itemId, $this->location->id);
        expect($quantity)->toBe(50.0);
    });

    it('tracks cost basis with weighted average', function (): void {
        // Add first batch at $10
        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
            cost: 10.00,
        ));

        // Add second batch at $20
        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
            cost: 20.00,
        ));

        /** @var Stock */
        $stock = Stock::query()
            ->where('item_id', $this->itemId)
            ->where('location_id', $this->location->id)
            ->first();

        // Weighted average: (100 * 10 + 100 * 20) / 200 = 15
        expect((float) $stock->cost_basis)->toBe(15.0);
    });
});

describe('StockService - Remove Stock', function (): void {
    it('removes stock from a location', function (): void {
        // First add some stock
        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
        ));

        // Then remove some
        $movement = $this->stockService->removeStock(new RemoveStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 30,
            type: 'sale',
        ));

        expect($movement)->toBeInstanceOf(StockMovement::class)
            ->and($movement->type)->toBe('sale');

        $quantity = $this->stockService->getQuantity($this->itemId, $this->location->id);
        expect($quantity)->toBe(70.0);
    });

    it('prevents negative stock by default', function (): void {
        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 50,
        ));

        expect(fn () => $this->stockService->removeStock(new RemoveStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
        )))->toThrow(InvalidArgumentException::class);
    });

    it('allows negative stock when configured', function (): void {
        config(['inventory.rules.allow_negative_stock' => true]);

        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 50,
        ));

        $movement = $this->stockService->removeStock(new RemoveStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
        ));

        expect($movement)->toBeInstanceOf(StockMovement::class);

        $quantity = $this->stockService->getQuantity($this->itemId, $this->location->id);
        expect($quantity)->toBe(-50.0);
    });
});

describe('StockService - Transfer Stock', function (): void {
    it('transfers stock between locations', function (): void {
        $location2 = Location::factory()->warehouse()->create();

        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
            cost: 10.00,
        ));

        $movement = $this->stockService->transfer(new TransferData(
            itemId: $this->itemId,
            fromLocationId: $this->location->id,
            toLocationId: $location2->id,
            quantity: 40,
        ));

        expect($movement)->toBeInstanceOf(StockMovement::class)
            ->and($movement->type)->toBe('transfer')
            ->and($movement->from_location_id)->toBe($this->location->id)
            ->and($movement->to_location_id)->toBe($location2->id);

        expect($this->stockService->getQuantity($this->itemId, $this->location->id))->toBe(60.0);
        expect($this->stockService->getQuantity($this->itemId, $location2->id))->toBe(40.0);
    });

    it('preserves cost basis on transfer', function (): void {
        $location2 = Location::factory()->warehouse()->create();

        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
            cost: 15.00,
        ));

        $this->stockService->transfer(new TransferData(
            itemId: $this->itemId,
            fromLocationId: $this->location->id,
            toLocationId: $location2->id,
            quantity: 50,
        ));

        /** @var Stock */
        $destStock = Stock::query()
            ->where('item_id', $this->itemId)
            ->where('location_id', $location2->id)
            ->first();

        expect((float) $destStock->cost_basis)->toBe(15.0);
    });
});

describe('StockService - Adjust Stock', function (): void {
    it('adjusts stock quantity', function (): void {
        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
        ));

        $movement = $this->stockService->adjust(new AdjustStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            newQuantity: 85,
            reason: 'Physical count correction',
        ));

        expect($movement)->toBeInstanceOf(StockMovement::class)
            ->and($movement->type)->toBe('adjustment')
            ->and((float) $movement->quantity)->toBe(15.0);

        $quantity = $this->stockService->getQuantity($this->itemId, $this->location->id);
        expect($quantity)->toBe(85.0);
    });

    it('handles positive adjustments', function (): void {
        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
        ));

        $movement = $this->stockService->adjust(new AdjustStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            newQuantity: 120,
            reason: 'Found extra stock',
        ));

        expect((float) $movement->quantity)->toBe(20.0)
            ->and($movement->to_location_id)->toBe($this->location->id)
            ->and($movement->from_location_id)->toBeNull();

        $quantity = $this->stockService->getQuantity($this->itemId, $this->location->id);
        expect($quantity)->toBe(120.0);
    });
});

describe('StockService - Query Methods', function (): void {
    it('gets stock by item across all locations', function (): void {
        $location2 = Location::factory()->warehouse()->create();

        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 50,
        ));

        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $location2->id,
            quantity: 30,
        ));

        $stocks = $this->stockService->getStockByItem($this->itemId);

        expect($stocks)->toHaveCount(2);
        expect($this->stockService->getQuantity($this->itemId))->toBe(80.0);
    });

    it('gets stock by location', function (): void {
        $itemId2 = (string) Symfony\Component\Uid\Uuid::v7();

        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 50,
        ));

        $this->stockService->addStock(new AddStockData(
            itemId: $itemId2,
            locationId: $this->location->id,
            quantity: 75,
        ));

        $stocks = $this->stockService->getStockByLocation($this->location->id);

        expect($stocks)->toHaveCount(2);
    });

    it('gets available quantity excluding reservations', function (): void {
        $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
        ));

        /** @var Stock */
        $stock = Stock::query()
            ->where('item_id', $this->itemId)
            ->where('location_id', $this->location->id)
            ->first();

        $stock->reserved_quantity = 25;
        $stock->save();

        $total = $this->stockService->getQuantity($this->itemId, $this->location->id);
        $available = $this->stockService->getAvailable($this->itemId, $this->location->id);

        expect($total)->toBe(100.0)
            ->and($available)->toBe(75.0);
    });
});
