<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Matching;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartMatch;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

/**
 * @extends Factory<PartMatch>
 */
final class PartMatchFactory extends Factory
{
    protected $model = PartMatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_item_id' => VendorItem::factory(),
            'item_id' => Item::factory(),
            'status' => MatchStatus::Proposed,
            'confidence' => 0.7,
            'method' => MatchMethod::Gateway,
        ];
    }

    public function newPart(): self
    {
        return $this->state(fn (): array => [
            'item_id' => null,
            'new_part' => ['name' => 'Axle shaft'],
        ]);
    }
}
