<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Matching;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Matching\Enums\ClassificationAxis;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartClassification;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartFitmentDefinition;

/**
 * @extends Factory<PartClassification>
 */
final class PartClassificationFactory extends Factory
{
    protected $model = PartClassification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'axis' => ClassificationAxis::Fitment,
            'tag_id' => fn (): mixed => PartFitmentDefinition::valueTag('ford')->getKey(),
            'status' => MatchStatus::Proposed,
            'confidence' => 0.7,
            'method' => MatchMethod::Gateway,
        ];
    }

    public function newCategory(string $name = 'Running boards'): self
    {
        return $this->state(fn (): array => [
            'axis' => ClassificationAxis::Category,
            'tag_id' => null,
            'proposed_value' => $name,
        ]);
    }
}
