<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Forecasting\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Forecasting\Models\ReorderRecommendation;
use RobinsonRyan\Inventory\Stock\Models\Stock;

final class ReorderCalculator
{
    private const DEFAULT_SAFETY_DAYS = 7;

    private const DEFAULT_LEAD_TIME_DAYS = 5;

    private const DEFAULT_REVIEW_PERIOD_DAYS = 7;

    public function __construct(
        private readonly DemandAnalyzer $demandAnalyzer,
    ) {}

    /**
     * Calculate reorder point (when to order).
     *
     * ROP = (Average Daily Usage x Lead Time Days) + Safety Stock
     * Safety Stock = Average Daily Usage x Safety Days
     */
    public function calculateReorderPoint(string $itemId): int
    {
        $item = $this->getItem($itemId);

        // If item has a min_stock_level set, use that as the reorder point
        if ($item !== null && $item->min_stock_level !== null) {
            return $item->min_stock_level;
        }

        $averageDailyUsage = $this->demandAnalyzer->calculateAverageDailyUsage($itemId);
        $leadTimeDays = $item?->lead_time_days ?? self::DEFAULT_LEAD_TIME_DAYS;
        $safetyDays = self::DEFAULT_SAFETY_DAYS;

        // ROP = (ADU x Lead Time) + (ADU x Safety Days)
        $reorderPoint = ($averageDailyUsage * $leadTimeDays) + ($averageDailyUsage * $safetyDays);

        return (int) ceil($reorderPoint);
    }

    /**
     * Calculate reorder quantity (how much to order).
     *
     * Simplified EOQ: ROQ = ADU x (Lead Time + Review Period + Safety Days)
     */
    public function calculateReorderQuantity(string $itemId): int
    {
        $item = $this->getItem($itemId);
        $averageDailyUsage = $this->demandAnalyzer->calculateAverageDailyUsage($itemId);

        // If no usage history, return a reasonable minimum
        if ($averageDailyUsage <= 0) {
            return $item?->min_stock_level ?? 10;
        }

        $leadTimeDays = $item?->lead_time_days ?? self::DEFAULT_LEAD_TIME_DAYS;
        $reviewPeriod = self::DEFAULT_REVIEW_PERIOD_DAYS;
        $safetyDays = self::DEFAULT_SAFETY_DAYS;

        // ROQ = ADU x (Lead Time + Review Period + Safety Days)
        $reorderQuantity = $averageDailyUsage * ($leadTimeDays + $reviewPeriod + $safetyDays);

        // Ensure we order at least enough to meet the reorder point
        $currentStock = $this->getCurrentStockQuantity($itemId);
        $reorderPoint = $this->calculateReorderPoint($itemId);
        $minOrderQuantity = max(0, $reorderPoint - $currentStock);

        return (int) ceil(max($reorderQuantity, $minOrderQuantity));
    }

    /**
     * Calculate estimated stockout date.
     */
    public function estimateStockoutDate(string $itemId): ?Carbon
    {
        $currentAvailable = $this->getAvailableStockQuantity($itemId);
        $averageDailyUsage = $this->demandAnalyzer->calculateAverageDailyUsage($itemId);

        // No stock means already stocked out
        if ($currentAvailable <= 0) {
            return Carbon::now();
        }

        // No usage means no stockout date
        if ($averageDailyUsage <= 0) {
            return null;
        }

        $daysToStockout = (int) floor($currentAvailable / $averageDailyUsage);

        return Carbon::now()->addDays($daysToStockout);
    }

    /**
     * Get items below reorder point.
     *
     * @return Collection<int, Item>
     */
    public function getItemsBelowReorderPoint(): Collection
    {
        $items = $this->getItemModel()::query()
            ->where('is_active', true)
            ->get();

        return $items->filter(function (Item $item) {
            $currentAvailable = $this->getAvailableStockQuantity($item->id);
            $reorderPoint = $this->calculateReorderPoint($item->id);

            return $currentAvailable <= $reorderPoint;
        })->values();
    }

    /**
     * Generate reorder recommendation for an item.
     */
    public function generateRecommendation(string $itemId): ?ReorderRecommendation
    {
        $item = $this->getItem($itemId);

        if ($item === null || ! $item->is_active) {
            return null;
        }

        $currentQuantity = $this->getCurrentStockQuantity($itemId);
        $reservedQuantity = $this->getReservedStockQuantity($itemId);
        $availableQuantity = $currentQuantity - $reservedQuantity;
        $reorderPoint = $this->calculateReorderPoint($itemId);

        // Only generate recommendation if below reorder point
        if ($availableQuantity > $reorderPoint) {
            return null;
        }

        // Check for existing pending recommendation
        $existingRecommendation = $this->getRecommendationModel()::query()
            ->where('item_id', $itemId)
            ->whereNull('actioned_at')
            ->whereNull('dismissed_at')
            ->first();

        if ($existingRecommendation !== null) {
            return null; // Don't create duplicate
        }

        $averageDailyUsage = $this->demandAnalyzer->calculateAverageDailyUsage($itemId);
        $stockoutDate = $this->estimateStockoutDate($itemId);
        $recommendedQuantity = $this->calculateReorderQuantity($itemId);

        /** @var ReorderRecommendation */
        return $this->getRecommendationModel()::query()->create([
            'item_id' => $itemId,
            'vendor_id' => null, // Could be enhanced to use preferred vendor
            'current_quantity' => $currentQuantity,
            'reserved_quantity' => $reservedQuantity,
            'available_quantity' => $availableQuantity,
            'min_stock_level' => $item->min_stock_level,
            'recommended_quantity' => $recommendedQuantity,
            'estimated_stockout_date' => $stockoutDate,
            'average_daily_usage' => $averageDailyUsage,
            'lead_time_days' => $item->lead_time_days ?? self::DEFAULT_LEAD_TIME_DAYS,
            'generated_at' => Carbon::now(),
        ]);
    }

    /**
     * Generate recommendations for all items needing reorder.
     *
     * @return Collection<int, ReorderRecommendation>
     */
    public function generateAllRecommendations(): Collection
    {
        $itemsBelowReorderPoint = $this->getItemsBelowReorderPoint();

        $recommendations = collect();

        foreach ($itemsBelowReorderPoint as $item) {
            $recommendation = $this->generateRecommendation($item->id);

            if ($recommendation !== null) {
                $recommendations->push($recommendation);
            }
        }

        return $recommendations;
    }

    private function getItem(string $itemId): ?Item
    {
        return $this->getItemModel()::query()->find($itemId);
    }

    private function getCurrentStockQuantity(string $itemId): float
    {
        return (float) $this->getStockModel()::query()
            ->where('item_id', $itemId)
            ->sum('quantity');
    }

    private function getReservedStockQuantity(string $itemId): float
    {
        return (float) $this->getStockModel()::query()
            ->where('item_id', $itemId)
            ->sum('reserved_quantity');
    }

    private function getAvailableStockQuantity(string $itemId): float
    {
        return $this->getCurrentStockQuantity($itemId) - $this->getReservedStockQuantity($itemId);
    }

    /**
     * @return class-string<Item>
     */
    private function getItemModel(): string
    {
        return config('inventory.models.item', Item::class);
    }

    /**
     * @return class-string<Stock>
     */
    private function getStockModel(): string
    {
        return config('inventory.models.stock', Stock::class);
    }

    /**
     * @return class-string<ReorderRecommendation>
     */
    private function getRecommendationModel(): string
    {
        return config('inventory.models.reorder_recommendation', ReorderRecommendation::class);
    }
}
