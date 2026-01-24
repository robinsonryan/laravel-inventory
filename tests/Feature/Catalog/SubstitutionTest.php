<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Catalog\Actions\DefineSubstitution;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\Models\ItemSubstitution;

describe('Item Substitution', function (): void {
    it('creates a substitution', function (): void {
        $original = Item::factory()->create(['name' => 'Brand A Oil Filter']);
        $substitute = Item::factory()->create(['name' => 'Brand B Oil Filter']);

        $action = new DefineSubstitution;
        $substitution = $action->execute($original, $substitute);

        expect($substitution)->toBeInstanceOf(ItemSubstitution::class)
            ->and($substitution->item_id)->toBe($original->id)
            ->and($substitution->substitute_item_id)->toBe($substitute->id)
            ->and($substitution->priority)->toBe(0)
            ->and((float) $substitution->ratio)->toBe(1.0);
    });

    it('creates substitution with priority', function (): void {
        $original = Item::factory()->create();
        $primary = Item::factory()->create(['name' => 'Primary Substitute']);
        $secondary = Item::factory()->create(['name' => 'Secondary Substitute']);

        $action = new DefineSubstitution;

        $sub1 = $action->execute($original, $primary, priority: 1);
        $sub2 = $action->execute($original, $secondary, priority: 2);

        expect($sub1->priority)->toBe(1)
            ->and($sub2->priority)->toBe(2);

        // Query substitutes ordered by priority
        $substitutes = $original->substitutes()->orderBy('priority')->get();
        expect($substitutes->first()->substituteItem->name)->toBe('Primary Substitute');
    });

    it('creates substitution with ratio', function (): void {
        $large = Item::factory()->create(['name' => 'Large Container (1 gallon)']);
        $small = Item::factory()->create(['name' => 'Small Container (1 quart)']);

        $action = new DefineSubstitution;
        // 4 small containers = 1 large container
        $substitution = $action->execute($large, $small, ratio: 4.0);

        expect((float) $substitution->ratio)->toBe(4.0);
    });

    it('creates bidirectional substitution', function (): void {
        $itemA = Item::factory()->create(['name' => 'Item A']);
        $itemB = Item::factory()->create(['name' => 'Item B']);

        $action = new DefineSubstitution;
        $substitution = $action->execute($itemA, $itemB, bidirectional: true);

        expect($substitution->is_bidirectional)->toBeTrue();

        // Check that reverse substitution was created
        $reverse = ItemSubstitution::where('item_id', $itemB->id)
            ->where('substitute_item_id', $itemA->id)
            ->first();

        expect($reverse)->not->toBeNull()
            ->and($reverse->is_bidirectional)->toBeTrue();
    });

    it('finds substitutes for an item', function (): void {
        $original = Item::factory()->create(['name' => 'Original Part']);
        $sub1 = Item::factory()->create(['name' => 'Substitute 1']);
        $sub2 = Item::factory()->create(['name' => 'Substitute 2']);

        $action = new DefineSubstitution;
        $action->execute($original, $sub1, priority: 1);
        $action->execute($original, $sub2, priority: 2);

        $substitutes = $original->substitutes()
            ->orderBy('priority')
            ->with('substituteItem')
            ->get();

        expect($substitutes)->toHaveCount(2)
            ->and($substitutes[0]->substituteItem->name)->toBe('Substitute 1')
            ->and($substitutes[1]->substituteItem->name)->toBe('Substitute 2');
    });

    it('finds items this can substitute for', function (): void {
        $universal = Item::factory()->create(['name' => 'Universal Filter']);
        $specific1 = Item::factory()->create(['name' => 'Ford Filter']);
        $specific2 = Item::factory()->create(['name' => 'Chevy Filter']);

        $action = new DefineSubstitution;
        $action->execute($specific1, $universal);
        $action->execute($specific2, $universal);

        $substitutedFor = $universal->substitutedBy()->with('item')->get();

        expect($substitutedFor)->toHaveCount(2);

        $names = $substitutedFor->pluck('item.name')->toArray();
        expect($names)->toContain('Ford Filter')
            ->and($names)->toContain('Chevy Filter');
    });

    it('stores notes with substitution', function (): void {
        $original = Item::factory()->create();
        $substitute = Item::factory()->create();

        $action = new DefineSubstitution;
        $substitution = $action->execute(
            $original,
            $substitute,
            notes: 'Use only in emergency situations'
        );

        expect($substitution->notes)->toBe('Use only in emergency situations');
    });

    it('calculates inverse ratio for bidirectional substitution', function (): void {
        $itemA = Item::factory()->create();
        $itemB = Item::factory()->create();

        $action = new DefineSubstitution;
        // 2 of A = 1 of B
        $action->execute($itemA, $itemB, ratio: 2.0, bidirectional: true);

        $reverse = ItemSubstitution::where('item_id', $itemB->id)
            ->where('substitute_item_id', $itemA->id)
            ->first();

        // Reverse should be 0.5 (1 of B = 0.5 of A, or 1/2)
        expect((float) $reverse->ratio)->toBe(0.5);
    });

    it('prevents duplicate substitution pairs', function (): void {
        $original = Item::factory()->create();
        $substitute = Item::factory()->create();

        ItemSubstitution::create([
            'item_id' => $original->id,
            'substitute_item_id' => $substitute->id,
            'priority' => 0,
            'ratio' => 1.0,
        ]);

        expect(fn () => ItemSubstitution::create([
            'item_id' => $original->id,
            'substitute_item_id' => $substitute->id,
            'priority' => 1,
            'ratio' => 1.0,
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });
});
