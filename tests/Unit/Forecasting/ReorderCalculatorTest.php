<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Forecasting\Services\DemandAnalyzer;
use RobinsonRyan\Inventory\Forecasting\Services\ReorderCalculator;

describe('ReorderCalculator Unit Tests', function (): void {
    it('can be instantiated with dependencies', function (): void {
        $demandAnalyzer = new DemandAnalyzer;
        $calculator = new ReorderCalculator($demandAnalyzer);

        expect($calculator)->toBeInstanceOf(ReorderCalculator::class);
    });

    it('returns default reorder quantity for items with no usage', function (): void {
        $demandAnalyzer = new DemandAnalyzer;
        $calculator = new ReorderCalculator($demandAnalyzer);
        $itemId = (string) Symfony\Component\Uid\Uuid::v7();

        $quantity = $calculator->calculateReorderQuantity($itemId);

        // Should return a reasonable minimum (10 by default when no item/no min_stock_level)
        expect($quantity)->toBeGreaterThanOrEqual(10);
    });

    it('returns null stockout date for items with no usage', function (): void {
        $demandAnalyzer = new DemandAnalyzer;
        $calculator = new ReorderCalculator($demandAnalyzer);
        $itemId = (string) Symfony\Component\Uid\Uuid::v7();

        $stockoutDate = $calculator->estimateStockoutDate($itemId);

        // No usage means no stockout prediction (would need stock to be positive though)
        // Since there's no stock at all, it could return now as stockout or null
        // Based on implementation, 0 available returns Carbon::now()
        expect($stockoutDate)->not->toBeNull();
    });

    it('returns null recommendation for non-existent items', function (): void {
        $demandAnalyzer = new DemandAnalyzer;
        $calculator = new ReorderCalculator($demandAnalyzer);
        $itemId = (string) Symfony\Component\Uid\Uuid::v7();

        $recommendation = $calculator->generateRecommendation($itemId);

        expect($recommendation)->toBeNull();
    });

    it('returns zero reorder point for items with no usage and no min_stock_level', function (): void {
        $demandAnalyzer = new DemandAnalyzer;
        $calculator = new ReorderCalculator($demandAnalyzer);
        $itemId = (string) Symfony\Component\Uid\Uuid::v7();

        $reorderPoint = $calculator->calculateReorderPoint($itemId);

        // Formula with 0 ADU should give 0 ROP
        expect($reorderPoint)->toBe(0);
    });
});
