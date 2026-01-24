<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Forecasting\Services\DemandAnalyzer;

describe('DemandAnalyzer Unit Tests', function () {
    it('can be instantiated', function () {
        $analyzer = new DemandAnalyzer();

        expect($analyzer)->toBeInstanceOf(DemandAnalyzer::class);
    });

    it('returns zero for items with no consumption history', function () {
        $analyzer = new DemandAnalyzer();
        $itemId = (string) \Symfony\Component\Uid\Uuid::v7();

        $usage = $analyzer->calculateAverageDailyUsage($itemId);

        expect($usage)->toBe(0.0);
    });

    it('returns stable trend for items with no history', function () {
        $analyzer = new DemandAnalyzer();
        $itemId = (string) \Symfony\Component\Uid\Uuid::v7();

        $trend = $analyzer->calculateTrend($itemId);

        expect($trend)->toBe('stable');
    });

    it('returns zero forecast for items with no usage', function () {
        $analyzer = new DemandAnalyzer();
        $itemId = (string) \Symfony\Component\Uid\Uuid::v7();

        $forecast = $analyzer->forecast($itemId, 30);

        expect($forecast)->toBe(0.0);
    });

    it('returns empty collection for items with no consumption periods', function () {
        $analyzer = new DemandAnalyzer();
        $itemId = (string) \Symfony\Component\Uid\Uuid::v7();

        $consumption = $analyzer->getConsumptionByPeriod($itemId, 'daily', 30);

        expect($consumption)->toBeEmpty();
    });
});
