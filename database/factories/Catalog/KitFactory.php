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
            'parent_kit_id' => null,
            'retail_price' => null,
            'dealer_price' => null,
            'is_customer_selectable' => false,
            'display_description' => null,
            'display_sort_order' => 0,
            'option_group' => null,
            'option_group_exclusive' => false,
            'sets_vehicle_attribute' => null,
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

    /**
     * Create as a conversion kit (top-level, no parent).
     */
    public function conversion(): self
    {
        return $this->state(fn (): array => [
            'name' => '4WD Conversion - '.$this->faker->randomElement(['Ford E-Series Gas', 'Chevy Express', 'GMC Savana']),
            'retail_price' => $this->faker->randomFloat(2, 10000, 15000),
            'dealer_price' => $this->faker->randomFloat(2, 8000, 12000),
            'parent_kit_id' => null,
            'is_customer_selectable' => false,
            'display_description' => 'Complete 4-wheel drive conversion kit',
        ]);
    }

    /**
     * Create as an option kit (child of a conversion).
     */
    public function option(): self
    {
        return $this->state(fn (): array => [
            'name' => $this->faker->randomElement(['Premium Torsion Bars', 'Premium Shocks', 'Skid Plate', 'Sway Bar Addition']),
            'retail_price' => $this->faker->randomFloat(2, 500, 2000),
            'dealer_price' => $this->faker->randomFloat(2, 400, 1500),
            'is_customer_selectable' => true,
            'display_description' => $this->faker->sentence(),
        ]);
    }

    /**
     * Create as an accessory kit.
     */
    public function accessory(): self
    {
        return $this->state(fn (): array => [
            'name' => $this->faker->randomElement(['Roof Rack', 'Rear Ladder', 'Running Boards', 'Storage Cabinet']),
            'retail_price' => $this->faker->randomFloat(2, 300, 1500),
            'dealer_price' => $this->faker->randomFloat(2, 200, 1200),
            'parent_kit_id' => null,
            'is_customer_selectable' => true,
            'display_description' => $this->faker->sentence(),
        ]);
    }

    /**
     * Create as a wheels/tires kit.
     */
    public function wheelsTires(): self
    {
        return $this->state(fn (): array => [
            'name' => $this->faker->randomElement([
                '17" Steel w/ BFG All-Terrain',
                '16" Alloy w/ Goodyear Wrangler',
                '18" Steel w/ Michelin Defender',
            ]),
            'retail_price' => $this->faker->randomFloat(2, 2000, 5000),
            'dealer_price' => $this->faker->randomFloat(2, 1600, 4000),
            'parent_kit_id' => null,
            'is_customer_selectable' => true,
            'display_description' => 'Complete wheel and tire package',
        ]);
    }

    /**
     * Set specific pricing.
     */
    public function withPricing(float $retail, float $dealer): self
    {
        return $this->state(fn (): array => [
            'retail_price' => $retail,
            'dealer_price' => $dealer,
        ]);
    }

    /**
     * Mark as customer selectable.
     */
    public function customerSelectable(): self
    {
        return $this->state(fn (): array => [
            'is_customer_selectable' => true,
        ]);
    }

    /**
     * Set parent kit for hierarchy.
     */
    public function childOf(Kit $parent, int $sortOrder = 0): self
    {
        return $this->state(fn (): array => [
            'parent_kit_id' => $parent->id,
            'display_sort_order' => $sortOrder,
        ]);
    }
}
