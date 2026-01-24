<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Forecasting\Data\ForecastData;

describe('ForecastData', function () {
    it('creates from constructor', function () {
        $data = new ForecastData(
            itemId: 'item-123',
            averageDailyUsage: 3.5,
            trend: 'increasing',
            forecastedDemand: 105.0,
            daysToStockout: 14,
            recommendedOrderQuantity: 50,
        );

        expect($data->itemId)->toBe('item-123')
            ->and($data->averageDailyUsage)->toBe(3.5)
            ->and($data->trend)->toBe('increasing')
            ->and($data->forecastedDemand)->toBe(105.0)
            ->and($data->daysToStockout)->toBe(14)
            ->and($data->recommendedOrderQuantity)->toBe(50);
    });

    it('creates from array', function () {
        $data = ForecastData::fromArray([
            'item_id' => 'item-456',
            'average_daily_usage' => 2.5,
            'trend' => 'stable',
            'forecasted_demand' => 75.0,
            'days_to_stockout' => 30,
            'recommended_order_quantity' => 100,
        ]);

        expect($data->itemId)->toBe('item-456')
            ->and($data->averageDailyUsage)->toBe(2.5)
            ->and($data->trend)->toBe('stable')
            ->and($data->forecastedDemand)->toBe(75.0)
            ->and($data->daysToStockout)->toBe(30)
            ->and($data->recommendedOrderQuantity)->toBe(100);
    });

    it('converts to array', function () {
        $data = new ForecastData(
            itemId: 'item-789',
            averageDailyUsage: 4.0,
            trend: 'decreasing',
            forecastedDemand: 120.0,
            daysToStockout: 7,
            recommendedOrderQuantity: 200,
        );

        $array = $data->toArray();

        expect($array)->toBe([
            'item_id' => 'item-789',
            'average_daily_usage' => 4.0,
            'trend' => 'decreasing',
            'forecasted_demand' => 120.0,
            'days_to_stockout' => 7,
            'recommended_order_quantity' => 200,
        ]);
    });

    it('is readonly', function () {
        $data = new ForecastData(
            itemId: 'item-123',
            averageDailyUsage: 3.0,
            trend: 'stable',
            forecastedDemand: 90.0,
            daysToStockout: 21,
            recommendedOrderQuantity: 75,
        );

        // Trying to set properties should fail at compile/static analysis time
        // but we can verify the object is readonly class
        $reflection = new ReflectionClass($data);

        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('handles edge case values', function () {
        $data = new ForecastData(
            itemId: 'item-edge',
            averageDailyUsage: 0.0,
            trend: 'stable',
            forecastedDemand: 0.0,
            daysToStockout: 0,
            recommendedOrderQuantity: 0,
        );

        expect($data->averageDailyUsage)->toBe(0.0)
            ->and($data->forecastedDemand)->toBe(0.0)
            ->and($data->daysToStockout)->toBe(0)
            ->and($data->recommendedOrderQuantity)->toBe(0);
    });
});
