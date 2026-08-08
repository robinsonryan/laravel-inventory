<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Forecasting\Models\ReorderRecommendation;
use RobinsonRyan\Inventory\Forecasting\Services\ReorderCalculator;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Data\RemoveStockData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Services\StockService;

beforeEach(function (): void {
    $this->reorderCalculator = App::make(ReorderCalculator::class);
    $this->stockService = App::make(StockService::class);
    $this->location = Location::factory()->warehouse()->create();
});

describe('ReorderCalculator', function (): void {
    it('calculates reorder point using min_stock_level when set', function (): void {
        $item = Item::factory()->create([
            'min_stock_level' => 50,
            'lead_time_days' => 5,
        ]);

        $reorderPoint = $this->reorderCalculator->calculateReorderPoint($item->id);

        expect($reorderPoint)->toBe(50);
    });

    it('calculates reorder point using formula when min_stock_level is null', function (): void {
        $item = Item::factory()->create([
            'min_stock_level' => null,
            'lead_time_days' => 5,
        ]);

        // Add stock and create usage history
        $this->stockService->addStock(new AddStockData(
            itemId: $item->id,
            locationId: $this->location->id,
            quantity: 1000,
        ));

        // Create usage: 3 units/day for 30 days
        for ($day = 1; $day <= 30; $day++) {
            Carbon::setTestNow(Carbon::now()->subDays(30 - $day));

            $this->stockService->removeStock(new RemoveStockData(
                itemId: $item->id,
                locationId: $this->location->id,
                quantity: 3,
                type: 'consumption',
            ));
        }

        Carbon::setTestNow();

        $reorderPoint = $this->reorderCalculator->calculateReorderPoint($item->id);

        // ROP = (ADU * Lead Time) + (ADU * Safety Days)
        // ROP = (3 * 5) + (3 * 7) = 15 + 21 = 36
        expect($reorderPoint)->toBeGreaterThanOrEqual(30)
            ->and($reorderPoint)->toBeLessThanOrEqual(45);
    });

    it('calculates reorder quantity', function (): void {
        $item = Item::factory()->create([
            'min_stock_level' => 50,
            'lead_time_days' => 5,
        ]);

        // Add stock and create usage history
        $this->stockService->addStock(new AddStockData(
            itemId: $item->id,
            locationId: $this->location->id,
            quantity: 1000,
        ));

        // Create usage: 3 units/day for 90 days
        for ($day = 1; $day <= 90; $day++) {
            Carbon::setTestNow(Carbon::now()->subDays(90 - $day));

            $this->stockService->removeStock(new RemoveStockData(
                itemId: $item->id,
                locationId: $this->location->id,
                quantity: 3,
                type: 'consumption',
            ));
        }

        Carbon::setTestNow();

        $reorderQuantity = $this->reorderCalculator->calculateReorderQuantity($item->id);

        // ROQ = ADU * (Lead Time + Review Period + Safety Days)
        // ROQ = 3 * (5 + 7 + 7) = 3 * 19 = 57
        expect($reorderQuantity)->toBeGreaterThan(40)
            ->and($reorderQuantity)->toBeLessThan(80);
    });

    it('estimates stockout date', function (): void {
        $item = Item::factory()->create([
            'lead_time_days' => 5,
        ]);

        // Add 30 units of stock
        $this->stockService->addStock(new AddStockData(
            itemId: $item->id,
            locationId: $this->location->id,
            quantity: 30,
        ));

        // Create usage: 3 units/day for 30 days
        for ($day = 1; $day <= 30; $day++) {
            Carbon::setTestNow(Carbon::now()->subDays(30 - $day));

            $this->stockService->removeStock(new RemoveStockData(
                itemId: $item->id,
                locationId: $this->location->id,
                quantity: 1,
                type: 'consumption',
            ));
        }

        Carbon::setTestNow();

        // Current stock should be 0, so stockout is now
        $stockoutDate = $this->reorderCalculator->estimateStockoutDate($item->id);

        expect($stockoutDate)->not->toBeNull()
            ->and($stockoutDate->toDateString())->toBe(Carbon::now()->toDateString());
    });

    it('identifies items below reorder point', function (): void {
        // Item 1: below reorder point
        $itemBelow = Item::factory()->create([
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        $this->stockService->addStock(new AddStockData(
            itemId: $itemBelow->id,
            locationId: $this->location->id,
            quantity: 50, // Below min of 100
        ));

        // Item 2: above reorder point
        $itemAbove = Item::factory()->create([
            'min_stock_level' => 50,
            'is_active' => true,
        ]);

        $this->stockService->addStock(new AddStockData(
            itemId: $itemAbove->id,
            locationId: $this->location->id,
            quantity: 200, // Above min of 50
        ));

        $itemsBelowReorderPoint = $this->reorderCalculator->getItemsBelowReorderPoint();

        expect($itemsBelowReorderPoint)->toHaveCount(1)
            ->and($itemsBelowReorderPoint->first()->id)->toBe($itemBelow->id);
    });

    it('generates recommendation', function (): void {
        $item = Item::factory()->create([
            'min_stock_level' => 100,
            'lead_time_days' => 5,
            'is_active' => true,
        ]);

        // Add stock below reorder point
        $this->stockService->addStock(new AddStockData(
            itemId: $item->id,
            locationId: $this->location->id,
            quantity: 30,
        ));

        $recommendation = $this->reorderCalculator->generateRecommendation($item->id);

        expect($recommendation)->not->toBeNull()
            ->and($recommendation)->toBeInstanceOf(ReorderRecommendation::class)
            ->and($recommendation->item_id)->toBe($item->id)
            ->and((float) $recommendation->current_quantity)->toBe(30.0)
            ->and($recommendation->min_stock_level)->toBe(100)
            ->and($recommendation->recommended_quantity)->toBeGreaterThan(0)
            ->and($recommendation->generated_at)->not->toBeNull();
    });

    it('does not generate duplicate recommendation', function (): void {
        $item = Item::factory()->create([
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        $this->stockService->addStock(new AddStockData(
            itemId: $item->id,
            locationId: $this->location->id,
            quantity: 30,
        ));

        // Generate first recommendation
        $first = $this->reorderCalculator->generateRecommendation($item->id);
        expect($first)->not->toBeNull();

        // Try to generate second recommendation
        $second = $this->reorderCalculator->generateRecommendation($item->id);
        expect($second)->toBeNull();

        // Verify only one exists
        $count = ReorderRecommendation::where('item_id', $item->id)->count();
        expect($count)->toBe(1);
    });

    it('does not generate recommendation for inactive items', function (): void {
        $item = Item::factory()->inactive()->create([
            'min_stock_level' => 100,
        ]);

        $this->stockService->addStock(new AddStockData(
            itemId: $item->id,
            locationId: $this->location->id,
            quantity: 30,
        ));

        $recommendation = $this->reorderCalculator->generateRecommendation($item->id);

        expect($recommendation)->toBeNull();
    });
});
