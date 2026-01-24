<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Forecasting\Services\DemandAnalyzer;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Data\RemoveStockData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Services\StockService;

beforeEach(function () {
    $this->demandAnalyzer = App::make(DemandAnalyzer::class);
    $this->stockService = App::make(StockService::class);
    $this->location = Location::factory()->warehouse()->create();
    $this->item = Item::factory()->create();
});

describe('DemandAnalyzer', function () {
    it('calculates average daily usage', function () {
        // Add initial stock
        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 1000,
        ));

        // Simulate 30 units consumed over 30 days (backdated movements)
        for ($day = 1; $day <= 30; $day++) {
            Carbon::setTestNow(Carbon::now()->subDays(30 - $day));

            $this->stockService->removeStock(new RemoveStockData(
                itemId: $this->item->id,
                locationId: $this->location->id,
                quantity: 1,
                type: 'consumption',
            ));
        }

        Carbon::setTestNow(); // Reset to now

        $averageDailyUsage = $this->demandAnalyzer->calculateAverageDailyUsage($this->item->id, 30);

        // Should be approximately 1 unit per day
        expect($averageDailyUsage)->toBeGreaterThanOrEqual(0.9)
            ->and($averageDailyUsage)->toBeLessThanOrEqual(1.1);
    });

    it('detects increasing trend', function () {
        // Add initial stock
        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 1000,
        ));

        // First half: 10 units consumed (older period)
        for ($day = 1; $day <= 10; $day++) {
            Carbon::setTestNow(Carbon::now()->subDays(60 - $day));

            $this->stockService->removeStock(new RemoveStockData(
                itemId: $this->item->id,
                locationId: $this->location->id,
                quantity: 1,
                type: 'consumption',
            ));
        }

        // Second half: 50 units consumed (recent period) - much more
        for ($day = 1; $day <= 50; $day++) {
            Carbon::setTestNow(Carbon::now()->subDays(30 - ($day % 30)));

            $this->stockService->removeStock(new RemoveStockData(
                itemId: $this->item->id,
                locationId: $this->location->id,
                quantity: 1,
                type: 'consumption',
            ));
        }

        Carbon::setTestNow();

        $trend = $this->demandAnalyzer->calculateTrend($this->item->id, 60);

        expect($trend)->toBe('increasing');
    });

    it('detects decreasing trend', function () {
        // Add initial stock
        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 1000,
        ));

        // First half: 50 units consumed (older period)
        for ($day = 1; $day <= 50; $day++) {
            Carbon::setTestNow(Carbon::now()->subDays(60 - ($day % 30)));

            $this->stockService->removeStock(new RemoveStockData(
                itemId: $this->item->id,
                locationId: $this->location->id,
                quantity: 1,
                type: 'consumption',
            ));
        }

        // Second half: 10 units consumed (recent period) - much less
        for ($day = 1; $day <= 10; $day++) {
            Carbon::setTestNow(Carbon::now()->subDays(15 - $day));

            $this->stockService->removeStock(new RemoveStockData(
                itemId: $this->item->id,
                locationId: $this->location->id,
                quantity: 1,
                type: 'consumption',
            ));
        }

        Carbon::setTestNow();

        $trend = $this->demandAnalyzer->calculateTrend($this->item->id, 60);

        expect($trend)->toBe('decreasing');
    });

    it('forecasts future demand', function () {
        // Add initial stock
        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 1000,
        ));

        // Simulate consistent usage of 3 units/day for 30 days
        for ($day = 1; $day <= 90; $day++) {
            Carbon::setTestNow(Carbon::now()->subDays(90 - $day));

            $this->stockService->removeStock(new RemoveStockData(
                itemId: $this->item->id,
                locationId: $this->location->id,
                quantity: 3,
                type: 'consumption',
            ));
        }

        Carbon::setTestNow();

        // Forecast for 30 days with ~3 units/day should be ~90 units
        $forecast = $this->demandAnalyzer->forecast($this->item->id, 30);

        expect($forecast)->toBeGreaterThan(80)
            ->and($forecast)->toBeLessThan(110);
    });

    it('handles items with no history', function () {
        $averageDailyUsage = $this->demandAnalyzer->calculateAverageDailyUsage($this->item->id);

        expect($averageDailyUsage)->toBe(0.0);

        $trend = $this->demandAnalyzer->calculateTrend($this->item->id);

        expect($trend)->toBe('stable');

        $forecast = $this->demandAnalyzer->forecast($this->item->id, 30);

        expect($forecast)->toBe(0.0);
    });
});
