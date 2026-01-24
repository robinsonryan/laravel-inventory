<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Catalog;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\Models\Transformation;

/**
 * @extends Factory<Transformation>
 */
final class TransformationFactory extends Factory
{
    protected $model = Transformation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true).' Transformation',
            'description' => $this->faker->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a transformation with specified inputs.
     *
     * @param  array<int, array{item: Item, quantity: float}>  $inputs
     */
    public function withInputs(array $inputs): self
    {
        return $this->afterCreating(function (Transformation $transformation) use ($inputs): void {
            foreach ($inputs as $input) {
                $transformation->inputs()->create([
                    'item_id' => $input['item']->id,
                    'quantity' => $input['quantity'],
                ]);
            }
        });
    }

    /**
     * Create a transformation with specified outputs.
     *
     * @param  array<int, array{item: Item, quantity: float}>  $outputs
     */
    public function withOutputs(array $outputs): self
    {
        return $this->afterCreating(function (Transformation $transformation) use ($outputs): void {
            foreach ($outputs as $output) {
                $transformation->outputs()->create([
                    'item_id' => $output['item']->id,
                    'quantity' => $output['quantity'],
                ]);
            }
        });
    }

    /**
     * Create a complete transformation with inputs and outputs.
     *
     * @param  array<int, array{item: Item, quantity: float}>  $inputs
     * @param  array<int, array{item: Item, quantity: float}>  $outputs
     */
    public function complete(array $inputs, array $outputs): self
    {
        return $this->withInputs($inputs)->withOutputs($outputs);
    }
}
