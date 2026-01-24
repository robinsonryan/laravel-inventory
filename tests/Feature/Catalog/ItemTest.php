<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Catalog\Actions\CreateItem;
use RobinsonRyan\Inventory\Catalog\Actions\UpdateItem;
use RobinsonRyan\Inventory\Catalog\Data\ItemData;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\TagDefinitions\ItemCategoryDefinition;

describe('Item', function (): void {
    it('creates an item with required fields', function (): void {
        $item = Item::create([
            'sku' => 'TEST-001',
            'name' => 'Test Item',
        ]);

        expect($item)->toBeInstanceOf(Item::class)
            ->and($item->sku)->toBe('TEST-001')
            ->and($item->name)->toBe('Test Item')
            ->and($item->unit_of_measure)->toBe('each')
            ->and($item->is_active)->toBeTrue()
            ->and($item->is_serialized)->toBeFalse();
    });

    it('creates an item with all fields', function (): void {
        $item = Item::create([
            'sku' => 'TEST-002',
            'name' => 'Full Item',
            'description' => 'A complete item with all fields',
            'unit_of_measure' => 'box',
            'cost' => 10.5000,
            'price' => 25.9999,
            'min_stock_level' => 10,
            'max_stock_level' => 100,
            'lead_time_days' => 7,
            'weight' => 2.5,
            'dimensions' => ['length' => 10, 'width' => 5, 'height' => 3, 'unit' => 'in'],
            'is_serialized' => true,
            'is_active' => true,
            'metadata' => ['custom' => 'value'],
        ]);

        expect($item->sku)->toBe('TEST-002')
            ->and($item->description)->toBe('A complete item with all fields')
            ->and($item->unit_of_measure)->toBe('box')
            ->and((float) $item->cost)->toBe(10.5)
            ->and((float) $item->price)->toBe(25.9999)
            ->and($item->min_stock_level)->toBe(10)
            ->and($item->max_stock_level)->toBe(100)
            ->and($item->lead_time_days)->toBe(7)
            ->and((float) $item->weight)->toBe(2.5)
            ->and($item->dimensions)->toBe(['length' => 10, 'width' => 5, 'height' => 3, 'unit' => 'in'])
            ->and($item->is_serialized)->toBeTrue()
            ->and($item->metadata)->toBe(['custom' => 'value']);
    });

    it('creates an item via action', function (): void {
        $data = new ItemData(
            sku: 'ACTION-001',
            name: 'Action Created Item',
            description: 'Created via action',
            category: 'part',
        );

        $action = new CreateItem;
        $item = $action->execute($data);

        expect($item)->toBeInstanceOf(Item::class)
            ->and($item->sku)->toBe('ACTION-001')
            ->and($item->name)->toBe('Action Created Item')
            ->and($item->category)->toBe('part');
    });

    it('updates an item', function (): void {
        $item = Item::create([
            'sku' => 'UPDATE-001',
            'name' => 'Original Name',
        ]);

        $data = new ItemData(
            sku: 'UPDATE-001',
            name: 'Updated Name',
            description: 'Now with description',
            category: 'supply',
        );

        $action = new UpdateItem;
        $updated = $action->execute($item, $data);

        expect($updated->name)->toBe('Updated Name')
            ->and($updated->description)->toBe('Now with description')
            ->and($updated->category)->toBe('supply');
    });

    it('sets item category via tag', function (): void {
        $item = Item::create([
            'sku' => 'CAT-001',
            'name' => 'Categorized Item',
        ]);

        $item->category = 'fluid';

        expect($item->category)->toBe('fluid');
    });

    it('scopes items by category', function (): void {
        $part = Item::create(['sku' => 'PART-001', 'name' => 'Part Item']);
        $part->category = 'part';

        $fluid = Item::create(['sku' => 'FLUID-001', 'name' => 'Fluid Item']);
        $fluid->category = 'fluid';

        $supply = Item::create(['sku' => 'SUPPLY-001', 'name' => 'Supply Item']);
        $supply->category = 'supply';

        $parts = Item::withTagIn(ItemCategoryDefinition::$slug, 'part')->get();
        $fluids = Item::withTagIn(ItemCategoryDefinition::$slug, 'fluid')->get();

        expect($parts)->toHaveCount(1)
            ->and($parts->first()->sku)->toBe('PART-001')
            ->and($fluids)->toHaveCount(1)
            ->and($fluids->first()->sku)->toBe('FLUID-001');
    });

    it('validates unique sku', function (): void {
        Item::create([
            'sku' => 'UNIQUE-001',
            'name' => 'First Item',
        ]);

        expect(fn () => Item::create([
            'sku' => 'UNIQUE-001',
            'name' => 'Duplicate SKU',
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('uses factory to create items', function (): void {
        $item = Item::factory()->create();

        expect($item)->toBeInstanceOf(Item::class)
            ->and($item->sku)->not->toBeEmpty()
            ->and($item->name)->not->toBeEmpty();
    });

    it('uses factory states for categories', function (): void {
        $part = Item::factory()->part()->create();
        $labor = Item::factory()->labor()->create();

        expect($part->category)->toBe('part')
            ->and($labor->category)->toBe('labor')
            ->and($labor->unit_of_measure)->toBe('hour');
    });
});
