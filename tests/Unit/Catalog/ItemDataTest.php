<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Catalog\Data\ItemData;

describe('ItemData', function (): void {
    it('creates with required fields', function (): void {
        $data = new ItemData(
            sku: 'TEST-001',
            name: 'Test Item',
        );

        expect($data->sku)->toBe('TEST-001')
            ->and($data->name)->toBe('Test Item')
            ->and($data->unitOfMeasure)->toBe('each')
            ->and($data->category)->toBe('part')
            ->and($data->isActive)->toBeTrue()
            ->and($data->isSerialized)->toBeFalse();
    });

    it('creates with all fields', function (): void {
        $data = new ItemData(
            sku: 'FULL-001',
            name: 'Full Item',
            tenantId: 'tenant-123',
            description: 'A complete item',
            unitOfMeasure: 'box',
            category: 'supply',
            cost: 10.50,
            price: 25.99,
            minStockLevel: 10,
            maxStockLevel: 100,
            leadTimeDays: 7,
            weight: 2.5,
            dimensions: ['length' => 10, 'width' => 5, 'height' => 3],
            isSerialized: true,
            isActive: false,
            metadata: ['custom' => 'value'],
        );

        expect($data->tenantId)->toBe('tenant-123')
            ->and($data->description)->toBe('A complete item')
            ->and($data->unitOfMeasure)->toBe('box')
            ->and($data->category)->toBe('supply')
            ->and($data->cost)->toBe(10.50)
            ->and($data->price)->toBe(25.99)
            ->and($data->minStockLevel)->toBe(10)
            ->and($data->maxStockLevel)->toBe(100)
            ->and($data->leadTimeDays)->toBe(7)
            ->and($data->weight)->toBe(2.5)
            ->and($data->dimensions)->toBe(['length' => 10, 'width' => 5, 'height' => 3])
            ->and($data->isSerialized)->toBeTrue()
            ->and($data->isActive)->toBeFalse()
            ->and($data->metadata)->toBe(['custom' => 'value']);
    });

    it('creates from array', function (): void {
        $data = ItemData::fromArray([
            'sku' => 'ARRAY-001',
            'name' => 'Array Item',
            'description' => 'Created from array',
            'unit_of_measure' => 'gallon',
            'category' => 'fluid',
            'cost' => '15.50',
            'price' => '30.00',
            'min_stock_level' => '5',
            'is_serialized' => '1',
        ]);

        expect($data->sku)->toBe('ARRAY-001')
            ->and($data->name)->toBe('Array Item')
            ->and($data->unitOfMeasure)->toBe('gallon')
            ->and($data->category)->toBe('fluid')
            ->and($data->cost)->toBe(15.50)
            ->and($data->price)->toBe(30.00)
            ->and($data->minStockLevel)->toBe(5)
            ->and($data->isSerialized)->toBeTrue();
    });

    it('converts to array', function (): void {
        $data = new ItemData(
            sku: 'TOARRAY-001',
            name: 'To Array Item',
            category: 'part',
            cost: 10.00,
        );

        $array = $data->toArray();

        expect($array['sku'])->toBe('TOARRAY-001')
            ->and($array['name'])->toBe('To Array Item')
            ->and($array['category'])->toBe('part')
            ->and($array['cost'])->toBe(10.00)
            ->and($array['unit_of_measure'])->toBe('each')
            ->and($array)->toHaveKey('tenant_id')
            ->and($array)->toHaveKey('description')
            ->and($array)->toHaveKey('metadata');
    });

    it('converts to model attributes excluding tag attributes', function (): void {
        $data = new ItemData(
            sku: 'MODEL-001',
            name: 'Model Item',
            category: 'supply',
        );

        $attributes = $data->toModelAttributes();

        expect($attributes)->toHaveKey('sku')
            ->and($attributes)->toHaveKey('name')
            ->and($attributes)->not->toHaveKey('category');
    });

    it('handles null optional fields from array', function (): void {
        $data = ItemData::fromArray([
            'sku' => 'NULL-001',
            'name' => 'Null Fields Item',
        ]);

        expect($data->tenantId)->toBeNull()
            ->and($data->description)->toBeNull()
            ->and($data->cost)->toBeNull()
            ->and($data->price)->toBeNull()
            ->and($data->minStockLevel)->toBeNull()
            ->and($data->maxStockLevel)->toBeNull()
            ->and($data->leadTimeDays)->toBeNull()
            ->and($data->weight)->toBeNull()
            ->and($data->dimensions)->toBeNull()
            ->and($data->metadata)->toBeNull();
    });

    it('uses default values for booleans from array', function (): void {
        $data = ItemData::fromArray([
            'sku' => 'BOOL-001',
            'name' => 'Boolean Defaults',
        ]);

        expect($data->isSerialized)->toBeFalse()
            ->and($data->isActive)->toBeTrue();
    });
});
