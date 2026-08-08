<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Stock\Actions\AddStock;
use RobinsonRyan\Inventory\Stock\Actions\AdjustStock;
use RobinsonRyan\Inventory\Stock\Actions\RemoveStock;
use RobinsonRyan\Inventory\Stock\Actions\TransferStock;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Data\AdjustStockData;
use RobinsonRyan\Inventory\Stock\Data\RemoveStockData;
use RobinsonRyan\Inventory\Stock\Data\TransferData;
use RobinsonRyan\Inventory\Stock\Services\StockService;

describe('AddStockData', function (): void {
    it('creates from constructor', function (): void {
        $data = new AddStockData(
            itemId: 'item-123',
            locationId: 'loc-456',
            quantity: 100.5,
            type: 'purchase',
            cost: 10.25,
            referenceType: 'purchase_order',
            referenceId: 'PO-001',
            lotNumber: 'LOT-001',
            notes: 'Test notes',
            metadata: ['key' => 'value'],
        );

        expect($data->itemId)->toBe('item-123')
            ->and($data->locationId)->toBe('loc-456')
            ->and($data->quantity)->toBe(100.5)
            ->and($data->type)->toBe('purchase')
            ->and($data->cost)->toBe(10.25)
            ->and($data->referenceType)->toBe('purchase_order')
            ->and($data->referenceId)->toBe('PO-001')
            ->and($data->lotNumber)->toBe('LOT-001')
            ->and($data->serialNumber)->toBeNull()
            ->and($data->notes)->toBe('Test notes')
            ->and($data->metadata)->toBe(['key' => 'value']);
    });

    it('creates from array', function (): void {
        $data = AddStockData::fromArray([
            'item_id' => 'item-123',
            'location_id' => 'loc-456',
            'quantity' => 50,
            'cost' => 15.00,
        ]);

        expect($data->itemId)->toBe('item-123')
            ->and($data->locationId)->toBe('loc-456')
            ->and($data->quantity)->toBe(50.0)
            ->and($data->type)->toBe('purchase')
            ->and($data->cost)->toBe(15.0);
    });

    it('converts to array', function (): void {
        $data = new AddStockData(
            itemId: 'item-123',
            locationId: 'loc-456',
            quantity: 100,
            cost: 10.00,
        );

        $array = $data->toArray();

        expect($array)->toHaveKey('item_id', 'item-123')
            ->and($array)->toHaveKey('location_id', 'loc-456')
            ->and($array)->toHaveKey('quantity', 100)
            ->and($array)->toHaveKey('cost', 10.0)
            ->and($array)->not->toHaveKey('serial_number');
    });
});

describe('RemoveStockData', function (): void {
    it('creates with defaults', function (): void {
        $data = new RemoveStockData(
            itemId: 'item-123',
            locationId: 'loc-456',
            quantity: 25,
        );

        expect($data->itemId)->toBe('item-123')
            ->and($data->type)->toBe('sale');
    });

    it('creates from array', function (): void {
        $data = RemoveStockData::fromArray([
            'item_id' => 'item-123',
            'location_id' => 'loc-456',
            'quantity' => 25,
            'type' => 'consumption',
        ]);

        expect($data->type)->toBe('consumption');
    });
});

describe('TransferData', function (): void {
    it('creates transfer data', function (): void {
        $data = new TransferData(
            itemId: 'item-123',
            fromLocationId: 'loc-from',
            toLocationId: 'loc-to',
            quantity: 50,
            notes: 'Transfer for production',
        );

        expect($data->itemId)->toBe('item-123')
            ->and($data->fromLocationId)->toBe('loc-from')
            ->and($data->toLocationId)->toBe('loc-to')
            ->and($data->quantity)->toBe(50.0)
            ->and($data->notes)->toBe('Transfer for production');
    });

    it('creates from array', function (): void {
        $data = TransferData::fromArray([
            'item_id' => 'item-123',
            'from_location_id' => 'loc-from',
            'to_location_id' => 'loc-to',
            'quantity' => 30,
        ]);

        expect($data->fromLocationId)->toBe('loc-from')
            ->and($data->toLocationId)->toBe('loc-to');
    });
});

describe('AdjustStockData', function (): void {
    it('creates adjustment data', function (): void {
        $data = new AdjustStockData(
            itemId: 'item-123',
            locationId: 'loc-456',
            newQuantity: 85,
            reason: 'Physical count discrepancy',
            notes: 'Counted by John',
        );

        expect($data->itemId)->toBe('item-123')
            ->and($data->newQuantity)->toBe(85.0)
            ->and($data->reason)->toBe('Physical count discrepancy')
            ->and($data->notes)->toBe('Counted by John');
    });

    it('creates from array', function (): void {
        $data = AdjustStockData::fromArray([
            'item_id' => 'item-123',
            'location_id' => 'loc-456',
            'new_quantity' => 100,
            'reason' => 'Correction',
        ]);

        expect($data->newQuantity)->toBe(100.0)
            ->and($data->reason)->toBe('Correction');
    });
});

describe('StockService Dependencies', function (): void {
    it('can be instantiated with actions', function (): void {
        $service = new StockService(
            new AddStock,
            new RemoveStock,
            new TransferStock,
            new AdjustStock,
        );

        expect($service)->toBeInstanceOf(StockService::class);
    });
});
