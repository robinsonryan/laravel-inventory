<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Catalog;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\Models\Kit;

/**
 * @extends Factory<Kit>
 */
final class KitFactory extends Factory
{
    protected $model = Kit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'name' => $this->faker->words(3, true).' Kit',
            'description' => $this->faker->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function forItem(Item $item): self
    {
        return $this->state(fn (): array => [
            'item_id' => $item->id,
            'name' => $item->name.' Kit',
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a kit with specified items.
     *
     * @param  array<int, array{item: Item, quantity?: float, is_optional?: bool}>  $items
     */
    public function withItems(array $items): self
    {
        return $this->afterCreating(function (Kit $kit) use ($items): void {
            foreach ($items as $index => $itemData) {
                $kit->kitItems()->create([
                    'item_id' => $itemData['item']->id,
                    'quantity' => $itemData['quantity'] ?? 1,
                    'is_optional' => $itemData['is_optional'] ?? false,
                    'sort_order' => $index,
                ]);
            }
        });
    }
}
