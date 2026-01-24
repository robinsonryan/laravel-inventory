<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Forecasting\Actions;

use Illuminate\Support\Collection;
use RobinsonRyan\Inventory\Forecasting\Models\ReorderRecommendation;
use RobinsonRyan\Inventory\Forecasting\Services\ReorderCalculator;

final class GenerateRecommendations
{
    public function __construct(
        private readonly ReorderCalculator $reorderCalculator,
    ) {}

    /**
     * Generate reorder recommendations for items below reorder point.
     *
     * @param  array<int, string>|null  $itemIds  Optional array of item IDs to limit generation
     * @return Collection<int, ReorderRecommendation>
     */
    public function execute(?array $itemIds = null): Collection
    {
        if ($itemIds === null) {
            return $this->reorderCalculator->generateAllRecommendations();
        }

        $recommendations = collect();

        foreach ($itemIds as $itemId) {
            $recommendation = $this->reorderCalculator->generateRecommendation($itemId);

            if ($recommendation !== null) {
                $recommendations->push($recommendation);
            }
        }

        return $recommendations;
    }
}
