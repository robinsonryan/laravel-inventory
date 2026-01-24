<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Catalog\Actions\CreateKit;
use RobinsonRyan\Inventory\Catalog\Data\KitData;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\Models\Kit;

describe('Kit', function (): void {
    it('creates a kit with items', function (): void {
        $item1 = Item::factory()->create(['cost' => 10.00, 'price' => 20.00]);
        $item2 = Item::factory()->create(['cost' => 15.00, 'price' => 30.00]);
        $kitItem = Item::factory()->create(['name' => 'Kit Item']);

        $kit = Kit::factory()->forItem($kitItem)->create();

        $kit->kitItems()->create([
            'item_id' => $item1->id,
            'quantity' => 2,
            'is_optional' => false,
            'sort_order' => 0,
        ]);

        $kit->kitItems()->create([
            'item_id' => $item2->id,
            'quantity' => 1,
            'is_optional' => false,
            'sort_order' => 1,
        ]);

        expect($kit->items)->toHaveCount(2)
            ->and($kit->item->name)->toBe('Kit Item');
    });

    it('creates a kit via action', function (): void {
        $item1 = Item::factory()->create(['name' => 'Component A']);
        $item2 = Item::factory()->create(['name' => 'Component B']);

        $data = new KitData(
            name: 'Action Kit',
            description: 'Created via action',
            items: [
                ['item_id' => $item1->id, 'quantity' => 2],
                ['item_id' => $item2->id, 'quantity' => 3, 'is_optional' => true],
            ],
        );

        $action = new CreateKit;
        $kit = $action->execute($data);

        expect($kit)->toBeInstanceOf(Kit::class)
            ->and($kit->name)->toBe('Action Kit')
            ->and($kit->items)->toHaveCount(2)
            ->and($kit->item)->not->toBeNull();

        $optionalItem = $kit->kitItems->firstWhere('item_id', $item2->id);
        expect($optionalItem->is_optional)->toBeTrue();
    });

    it('calculates kit total cost', function (): void {
        $item1 = Item::factory()->create(['cost' => 10.00]);
        $item2 = Item::factory()->create(['cost' => 5.00]);

        $kit = Kit::factory()->create();

        $kit->kitItems()->create([
            'item_id' => $item1->id,
            'quantity' => 2, // 2 x 10 = 20
            'sort_order' => 0,
        ]);

        $kit->kitItems()->create([
            'item_id' => $item2->id,
            'quantity' => 3, // 3 x 5 = 15
            'sort_order' => 1,
        ]);

        $kit->load('items');

        expect($kit->calculateTotalCost())->toBe(35.0);
    });

    it('calculates kit total price', function (): void {
        $item1 = Item::factory()->create(['price' => 20.00]);
        $item2 = Item::factory()->create(['price' => 10.00]);

        $kit = Kit::factory()->create();

        $kit->kitItems()->create([
            'item_id' => $item1->id,
            'quantity' => 1, // 1 x 20 = 20
            'sort_order' => 0,
        ]);

        $kit->kitItems()->create([
            'item_id' => $item2->id,
            'quantity' => 2, // 2 x 10 = 20
            'sort_order' => 1,
        ]);

        $kit->load('items');

        expect($kit->calculateTotalPrice())->toBe(40.0);
    });

    it('handles optional kit items', function (): void {
        $required = Item::factory()->create(['name' => 'Required']);
        $optional = Item::factory()->create(['name' => 'Optional']);

        $kit = Kit::factory()->create();

        $kit->kitItems()->create([
            'item_id' => $required->id,
            'quantity' => 1,
            'is_optional' => false,
            'sort_order' => 0,
        ]);

        $kit->kitItems()->create([
            'item_id' => $optional->id,
            'quantity' => 1,
            'is_optional' => true,
            'sort_order' => 1,
        ]);

        $requiredItems = $kit->kitItems()->where('is_optional', false)->get();
        $optionalItems = $kit->kitItems()->where('is_optional', true)->get();

        expect($requiredItems)->toHaveCount(1)
            ->and($optionalItems)->toHaveCount(1)
            ->and($requiredItems->first()->item->name)->toBe('Required')
            ->and($optionalItems->first()->item->name)->toBe('Optional');
    });

    it('associates kit with parent item', function (): void {
        $kitItem = Item::factory()->create(['name' => 'Oil Change Kit']);
        $kit = Kit::factory()->forItem($kitItem)->create(['name' => 'Oil Change Kit']);

        expect($kit->item_id)->toBe($kitItem->id)
            ->and($kitItem->asKit->id)->toBe($kit->id);
    });

    it('orders kit items by sort_order', function (): void {
        $item1 = Item::factory()->create(['name' => 'First']);
        $item2 = Item::factory()->create(['name' => 'Second']);
        $item3 = Item::factory()->create(['name' => 'Third']);

        $kit = Kit::factory()->create();

        // Add in reverse order
        $kit->kitItems()->create(['item_id' => $item3->id, 'quantity' => 1, 'sort_order' => 2]);
        $kit->kitItems()->create(['item_id' => $item1->id, 'quantity' => 1, 'sort_order' => 0]);
        $kit->kitItems()->create(['item_id' => $item2->id, 'quantity' => 1, 'sort_order' => 1]);

        $orderedItems = $kit->items;

        expect($orderedItems[0]->name)->toBe('First')
            ->and($orderedItems[1]->name)->toBe('Second')
            ->and($orderedItems[2]->name)->toBe('Third');
    });
});
