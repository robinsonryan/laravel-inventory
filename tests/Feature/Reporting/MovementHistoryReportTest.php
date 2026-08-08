<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Reports\MovementHistoryReport;
use RobinsonRyan\Inventory\Reporting\Services\ReportService;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Data\RemoveStockData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Services\StockService;

beforeEach(function (): void {
    $this->reportService = App::make(ReportService::class);
    $this->stockService = App::make(StockService::class);
    $this->location = Location::factory()->warehouse()->create(['code' => 'WH1']);
    $this->item = Item::factory()->create(['sku' => 'TEST-001', 'name' => 'Test Item']);
});

describe('MovementHistoryReport', function (): void {
    it('generates movement history', function (): void {
        // Create some movements
        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 100,
            type: 'purchase',
            cost: 10.00,
        ));

        $report = new MovementHistoryReport;
        $data = $this->reportService->generate($report);

        expect($data)->toBeInstanceOf(ReportData::class)
            ->and($data->title)->toBe('Movement History Report')
            ->and($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('TEST-001')
            ->and($data->rows->first()['type'])->toBe('purchase');
    });

    it('filters by date range', function (): void {
        // Create movement in the past
        Carbon::setTestNow(Carbon::parse('2026-01-01'));
        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 50,
            type: 'purchase',
        ));

        // Create movement now
        Carbon::setTestNow(Carbon::parse('2026-01-15'));
        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 75,
            type: 'purchase',
        ));

        Carbon::setTestNow();

        // Filter for January 10-20 (should only include second movement)
        $report = new MovementHistoryReport(
            fromDate: Carbon::parse('2026-01-10'),
            toDate: Carbon::parse('2026-01-20'),
        );
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and((float) $data->rows->first()['quantity'])->toBe(75.0);
    });

    it('filters by movement type', function (): void {
        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 100,
            type: 'purchase',
        ));

        $this->stockService->removeStock(new RemoveStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 25,
            type: 'sale',
        ));

        $report = new MovementHistoryReport(movementType: 'sale');
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['type'])->toBe('sale');
    });

    it('includes actor information', function (): void {
        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 100,
            type: 'purchase',
            referenceType: 'PurchaseOrder',
            referenceId: 'po-123',
        ));

        $report = new MovementHistoryReport;
        $data = $this->reportService->generate($report);
        $columns = $report->getColumns();

        $columnKeys = array_column($columns, 'key');

        expect($columnKeys)->toContain('actor_type')
            ->toContain('actor_id')
            ->toContain('reference_type')
            ->toContain('reference_id');

        expect($data->rows->first()['reference_type'])->toBe('PurchaseOrder')
            ->and($data->rows->first()['reference_id'])->toBe('po-123');
    });

    it('filters by item', function (): void {
        $item2 = Item::factory()->create(['sku' => 'TEST-002']);

        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 100,
        ));

        $this->stockService->addStock(new AddStockData(
            itemId: $item2->id,
            locationId: $this->location->id,
            quantity: 50,
        ));

        $report = new MovementHistoryReport(itemId: $this->item->id);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('TEST-001');
    });

    it('filters by location', function (): void {
        $location2 = Location::factory()->warehouse()->create(['code' => 'WH2']);

        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $this->location->id,
            quantity: 100,
        ));

        $this->stockService->addStock(new AddStockData(
            itemId: $this->item->id,
            locationId: $location2->id,
            quantity: 50,
        ));

        $report = new MovementHistoryReport(locationId: $this->location->id);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['to_location'])->toBe('WH1');
    });
});
