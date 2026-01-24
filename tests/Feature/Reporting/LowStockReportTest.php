<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Reports\LowStockReport;
use RobinsonRyan\Inventory\Reporting\Services\ReportService;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;

beforeEach(function () {
    $this->reportService = App::make(ReportService::class);
    $this->location = Location::factory()->warehouse()->create(['code' => 'WH1']);
});

describe('LowStockReport', function () {
    it('generates low stock report', function () {
        // Item below minimum
        $lowItem = Item::factory()->create([
            'sku' => 'LOW-001',
            'name' => 'Low Stock Item',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($lowItem->id)
            ->forLocation($this->location)
            ->withQuantity(50)
            ->create();

        // Item above minimum (should not appear)
        $goodItem = Item::factory()->create([
            'sku' => 'GOOD-001',
            'name' => 'Good Stock Item',
            'min_stock_level' => 50,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($goodItem->id)
            ->forLocation($this->location)
            ->withQuantity(100)
            ->create();

        $report = new LowStockReport();
        $data = $this->reportService->generate($report);

        expect($data)->toBeInstanceOf(ReportData::class)
            ->and($data->title)->toBe('Low Stock Report')
            ->and($data->rows)->toHaveCount(1)
            ->and($data->rows->first()->item_sku)->toBe('LOW-001');
    });

    it('includes zero stock items', function () {
        $item = Item::factory()->create([
            'sku' => 'ZERO-001',
            'min_stock_level' => 50,
            'is_active' => true,
        ]);

        // No stock record created = zero stock

        $report = new LowStockReport(includeZero: true);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()->item_sku)->toBe('ZERO-001')
            ->and((float) $data->rows->first()->current_quantity)->toBe(0.0);
    });

    it('excludes zero stock items when requested', function () {
        $zeroItem = Item::factory()->create([
            'sku' => 'ZERO-001',
            'min_stock_level' => 50,
            'is_active' => true,
        ]);

        $lowItem = Item::factory()->create([
            'sku' => 'LOW-001',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($lowItem->id)
            ->forLocation($this->location)
            ->withQuantity(50)
            ->create();

        $report = new LowStockReport(includeZero: false, includeBelowMin: true);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()->item_sku)->toBe('LOW-001');
    });

    it('filters by category', function () {
        $partItem = Item::factory()->create([
            'sku' => 'PART-001',
            'category' => 'part',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        $supplyItem = Item::factory()->create([
            'sku' => 'SUPPLY-001',
            'category' => 'supply',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($partItem->id)
            ->forLocation($this->location)
            ->withQuantity(50)
            ->create();

        Stock::factory()
            ->forItem($supplyItem->id)
            ->forLocation($this->location)
            ->withQuantity(50)
            ->create();

        $report = new LowStockReport(category: 'part');
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()->category)->toBe('part');
    });

    it('calculates shortfall correctly', function () {
        $item = Item::factory()->create([
            'sku' => 'LOW-001',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($this->location)
            ->withQuantity(60)
            ->withReserved(10)
            ->create();

        $report = new LowStockReport();
        $data = $this->reportService->generate($report);

        // Available = 60 - 10 = 50
        // Shortfall = 100 - 50 = 50
        expect((float) $data->rows->first()->current_quantity)->toBe(60.0)
            ->and((float) $data->rows->first()->available_quantity)->toBe(50.0)
            ->and((float) $data->rows->first()->shortfall)->toBe(50.0);
    });

    it('aggregates stock across locations', function () {
        $location2 = Location::factory()->warehouse()->create();

        $item = Item::factory()->create([
            'sku' => 'MULTI-001',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($this->location)
            ->withQuantity(30)
            ->create();

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($location2)
            ->withQuantity(40)
            ->create();

        $report = new LowStockReport();
        $data = $this->reportService->generate($report);

        // Total = 30 + 40 = 70, still below min of 100
        expect($data->rows)->toHaveCount(1)
            ->and((float) $data->rows->first()->current_quantity)->toBe(70.0)
            ->and((float) $data->rows->first()->shortfall)->toBe(30.0);
    });

    it('excludes inactive items', function () {
        $activeItem = Item::factory()->create([
            'sku' => 'ACTIVE-001',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        $inactiveItem = Item::factory()->create([
            'sku' => 'INACTIVE-001',
            'min_stock_level' => 100,
            'is_active' => false,
        ]);

        Stock::factory()
            ->forItem($activeItem->id)
            ->forLocation($this->location)
            ->withQuantity(50)
            ->create();

        Stock::factory()
            ->forItem($inactiveItem->id)
            ->forLocation($this->location)
            ->withQuantity(50)
            ->create();

        $report = new LowStockReport();
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()->item_sku)->toBe('ACTIVE-001');
    });

    it('calculates summary correctly', function () {
        // Zero stock item
        Item::factory()->create([
            'sku' => 'ZERO-001',
            'min_stock_level' => 50,
            'is_active' => true,
        ]);

        // Low stock item 1
        $low1 = Item::factory()->create([
            'sku' => 'LOW-001',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($low1->id)
            ->forLocation($this->location)
            ->withQuantity(60)
            ->create();

        // Low stock item 2
        $low2 = Item::factory()->create([
            'sku' => 'LOW-002',
            'min_stock_level' => 80,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($low2->id)
            ->forLocation($this->location)
            ->withQuantity(30)
            ->create();

        $report = new LowStockReport();
        $data = $this->reportService->generate($report);

        expect($data->getSummary('total_items'))->toBe(3)
            ->and($data->getSummary('zero_stock_count'))->toBe(1)
            ->and($data->getSummary('below_min_count'))->toBe(2)
            ->and($data->getSummary('total_shortfall'))->toBe(140.0); // 50 + 40 + 50
    });
});
