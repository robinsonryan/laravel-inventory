<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Stock\Models\Location;

/**
 * @extends Factory<Location>
 */
final class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->bothify('LOC-####'),
            'name' => $this->faker->words(2, true).' '.$this->faker->randomElement(['Warehouse', 'Bin', 'Shelf', 'Area']),
            'description' => $this->faker->optional()->sentence(),
            'is_active' => true,
            'is_pickable' => true,
            'is_receivable' => true,
        ];
    }

    public function warehouse(): self
    {
        return $this->state(fn (): array => [
            'name' => $this->faker->words(2, true).' Warehouse',
        ])->afterCreating(function (Location $location): void {
            $location->type = 'warehouse';
        });
    }

    public function bin(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Bin '.$this->faker->bothify('??-##'),
        ])->afterCreating(function (Location $location): void {
            $location->type = 'bin';
        });
    }

    public function shelf(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Shelf '.$this->faker->randomLetter().'-'.$this->faker->numberBetween(1, 20),
        ])->afterCreating(function (Location $location): void {
            $location->type = 'shelf';
        });
    }

    public function wip(): self
    {
        return $this->state(fn (): array => [
            'name' => 'WIP Area '.$this->faker->numberBetween(1, 10),
            'is_receivable' => false,
        ])->afterCreating(function (Location $location): void {
            $location->type = 'wip';
        });
    }

    public function truck(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Truck '.$this->faker->bothify('###'),
            'is_receivable' => false,
        ])->afterCreating(function (Location $location): void {
            $location->type = 'truck';
        });
    }

    public function virtual(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Virtual - '.$this->faker->word(),
            'is_pickable' => false,
            'is_receivable' => false,
        ])->afterCreating(function (Location $location): void {
            $location->type = 'virtual';
        });
    }

    public function quarantine(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Quarantine Area',
            'is_pickable' => false,
        ])->afterCreating(function (Location $location): void {
            $location->type = 'quarantine';
        });
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    public function withParent(Location $parent): self
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->id,
        ]);
    }
}
