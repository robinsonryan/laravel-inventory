<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;
use RobinsonRyan\Inventory\Stock\Services\StockService;
use RobinsonRyan\Inventory\Stock\TagDefinitions\MovementTypeDefinition;

beforeEach(function () {
    $this->stockService = App::make(StockService::class);
    $this->location = Location::factory()->warehouse()->create();
    $this->itemId = (string) \Symfony\Component\Uid\Uuid::v7();
});

describe('StockMovement Model', function () {
    it('creates immutable movement records', function () {
        $movement = $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
            type: 'purchase',
            cost: 10.00,
            notes: 'Initial stock',
        ));

        expect($movement)->toBeInstanceOf(StockMovement::class)
            ->and($movement->id)->not->toBeNull()
            ->and($movement->item_id)->toBe($this->itemId)
            ->and($movement->to_location_id)->toBe($this->location->id)
            ->and($movement->from_location_id)->toBeNull()
            ->and((float) $movement->quantity)->toBe(100.0)
            ->and((float) $movement->cost)->toBe(10.0)
            ->and($movement->type)->toBe('purchase')
            ->and($movement->notes)->toBe('Initial stock');
    });

    it('prevents movement updates', function () {
        config(['inventory.rules.movement_immutable' => true]);

        $movement = $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
        ));

        expect(function () use ($movement) {
            $movement->quantity = 200;
            $movement->save();
        })->toThrow(RuntimeException::class, 'Cannot update');
    });

    it('prevents movement deletes', function () {
        config(['inventory.rules.movement_immutable' => true]);

        $movement = $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
        ));

        expect(fn () => $movement->delete())->toThrow(RuntimeException::class, 'Cannot delete');
    });

    it('tracks movement types', function () {
        // Purchase
        $purchase = $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
            type: 'purchase',
        ));
        expect($purchase->type)->toBe('purchase');

        // Sale
        $sale = $this->stockService->removeStock(new \RobinsonRyan\Inventory\Stock\Data\RemoveStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 10,
            type: 'sale',
        ));
        expect($sale->type)->toBe('sale');

        // Transfer
        $location2 = Location::factory()->warehouse()->create();
        $transfer = $this->stockService->transfer(new \RobinsonRyan\Inventory\Stock\Data\TransferData(
            itemId: $this->itemId,
            fromLocationId: $this->location->id,
            toLocationId: $location2->id,
            quantity: 20,
        ));
        expect($transfer->type)->toBe('transfer');

        // Adjustment
        $adjustment = $this->stockService->adjust(new \RobinsonRyan\Inventory\Stock\Data\AdjustStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            newQuantity: 50,
            reason: 'Count correction',
        ));
        expect($adjustment->type)->toBe('adjustment');
    });

    it('stores reference information', function () {
        $movement = $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
            referenceType: 'purchase_order',
            referenceId: 'PO-12345',
        ));

        expect($movement->reference_type)->toBe('purchase_order')
            ->and($movement->reference_id)->toBe('PO-12345');
    });

    it('stores lot and serial information', function () {
        $movement = $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 1,
            lotNumber: 'LOT-2026-001',
            serialNumber: 'SN-12345',
        ));

        expect($movement->lot_number)->toBe('LOT-2026-001')
            ->and($movement->serial_number)->toBe('SN-12345');
    });

    it('stores metadata', function () {
        $movement = $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
            metadata: ['vendor' => 'ACME', 'invoice' => 'INV-001'],
        ));

        expect($movement->metadata)->toBeArray()
            ->and($movement->metadata['vendor'])->toBe('ACME')
            ->and($movement->metadata['invoice'])->toBe('INV-001');
    });

    it('only has created_at timestamp', function () {
        $movement = $this->stockService->addStock(new AddStockData(
            itemId: $this->itemId,
            locationId: $this->location->id,
            quantity: 100,
        ));

        expect($movement->created_at)->not->toBeNull();

        // StockMovement has UPDATED_AT = null, so it should not track updated_at
        $attributes = $movement->getAttributes();
        expect(array_key_exists('updated_at', $attributes))->toBeFalse();
    });
});

describe('MovementTypeDefinition', function () {
    it('has correct slug', function () {
        expect(MovementTypeDefinition::$slug)->toBe('movement-type');
    });

    it('has correct name', function () {
        expect(MovementTypeDefinition::$name)->toBe('Movement Type');
    });

    it('is single select', function () {
        expect(MovementTypeDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function () {
        expect(MovementTypeDefinition::$global)->toBeTrue();
    });

    it('has expected default values', function () {
        expect(MovementTypeDefinition::DEFAULT_VALUES)
            ->toContain('purchase')
            ->toContain('sale')
            ->toContain('transfer')
            ->toContain('adjustment')
            ->toContain('consumption')
            ->toContain('production')
            ->toContain('return')
            ->toContain('scrap');
    });
});
