<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Catalog;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Catalog\Models\Item;

/**
 * @extends Factory<Item>
 */
final class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => $this->faker->unique()->bothify('ITEM-####-???'),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'unit_of_measure' => $this->faker->randomElement(['each', 'box', 'case', 'gallon', 'quart', 'liter', 'kg', 'lb']),
            'cost' => $this->faker->randomFloat(4, 1, 500),
            'price' => $this->faker->randomFloat(4, 5, 1000),
            'min_stock_level' => $this->faker->optional()->numberBetween(5, 50),
            'max_stock_level' => $this->faker->optional()->numberBetween(100, 500),
            'lead_time_days' => $this->faker->optional()->numberBetween(1, 30),
            'weight' => $this->faker->optional()->randomFloat(4, 0.1, 100),
            'is_serialized' => false,
            'is_active' => true,
        ];
    }

    public function part(): self
    {
        return $this->afterCreating(function (Item $item): void {
            $item->category = 'part';
        });
    }

    public function supply(): self
    {
        return $this->afterCreating(function (Item $item): void {
            $item->category = 'supply';
        });
    }

    public function fluid(): self
    {
        return $this->afterCreating(function (Item $item): void {
            $item->category = 'fluid';
        });
    }

    public function labor(): self
    {
        return $this->state(fn (): array => [
            'unit_of_measure' => 'hour',
            'weight' => null,
            'is_serialized' => false,
        ])->afterCreating(function (Item $item): void {
            $item->category = 'labor';
        });
    }

    public function service(): self
    {
        return $this->state(fn (): array => [
            'unit_of_measure' => 'each',
            'weight' => null,
            'is_serialized' => false,
        ])->afterCreating(function (Item $item): void {
            $item->category = 'service';
        });
    }

    public function assembly(): self
    {
        return $this->afterCreating(function (Item $item): void {
            $item->category = 'assembly';
        });
    }

    public function serialized(): self
    {
        return $this->state(fn (): array => [
            'is_serialized' => true,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    public function withDimensions(): self
    {
        return $this->state(fn (): array => [
            'dimensions' => [
                'length' => $this->faker->randomFloat(2, 1, 100),
                'width' => $this->faker->randomFloat(2, 1, 100),
                'height' => $this->faker->randomFloat(2, 1, 100),
                'unit' => 'in',
            ],
        ]);
    }

    public function forTenant(string $tenantId): self
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenantId,
        ]);
    }
}
