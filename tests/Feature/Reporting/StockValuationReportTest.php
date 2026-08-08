<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Reports\StockValuationReport;
use RobinsonRyan\Inventory\Reporting\Services\ReportService;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;

beforeEach(function (): void {
    $this->reportService = App::make(ReportService::class);
    $this->location1 = Location::factory()->warehouse()->create(['code' => 'WH1', 'name' => 'Warehouse 1']);
    $this->location2 = Location::factory()->warehouse()->create(['code' => 'WH2', 'name' => 'Warehouse 2']);
});

describe('StockValuationReport', function (): void {
    it('generates stock valuation report', function (): void {
        $item = Item::factory()->create(['sku' => 'TEST-001', 'name' => 'Test Item']);

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($this->location1)
            ->withQuantity(100)
            ->withCost(10.00)
            ->create();

        $report = new StockValuationReport;
        $data = $this->reportService->generate($report);

        expect($data)->toBeInstanceOf(ReportData::class)
            ->and($data->title)->toBe('Stock Valuation Report')
            ->and($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('TEST-001')
            ->and((float) $data->rows->first()['quantity'])->toBe(100.0)
            ->and((float) $data->rows->first()['unit_cost'])->toBe(10.0)
            ->and((float) $data->rows->first()['total_value'])->toBe(1000.0);
    });

    it('filters by location', function (): void {
        $item = Item::factory()->create();

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($this->location1)
            ->withQuantity(50)
            ->withCost(10.00)
            ->create();

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($this->location2)
            ->withQuantity(75)
            ->withCost(10.00)
            ->create();

        $report = new StockValuationReport(locationId: $this->location1->id);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['location_code'])->toBe('WH1');
    });

    it('filters by item category', function (): void {
        // The category is a tag, and taxon writes the pivot row immediately, so
        // it can only be set once the item has an id. The factory states do
        // exactly that, after creating.
        $partItem = Item::factory()->part()->create();
        $supplyItem = Item::factory()->supply()->create();

        Stock::factory()
            ->forItem($partItem->id)
            ->forLocation($this->location1)
            ->withQuantity(100)
            ->create();

        Stock::factory()
            ->forItem($supplyItem->id)
            ->forLocation($this->location1)
            ->withQuantity(50)
            ->create();

        $report = new StockValuationReport(category: 'part');
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['category_value'])->toBe('part')
            ->and($data->rows->first()['category_label'])->toBe('Part');
    });

    it('calculates totals correctly', function (): void {
        $item1 = Item::factory()->create();
        $item2 = Item::factory()->create();

        Stock::factory()
            ->forItem($item1->id)
            ->forLocation($this->location1)
            ->withQuantity(100)
            ->withCost(10.00)
            ->withReserved(20)
            ->create();

        Stock::factory()
            ->forItem($item2->id)
            ->forLocation($this->location1)
            ->withQuantity(50)
            ->withCost(20.00)
            ->withReserved(10)
            ->create();

        $report = new StockValuationReport;
        $data = $this->reportService->generate($report);

        // Total value: (100 * 10) + (50 * 20) = 1000 + 1000 = 2000
        expect($data->getSummary('total_quantity'))->toBe(150.0)
            ->and($data->getSummary('total_value'))->toBe(2000.0)
            ->and($data->getSummary('total_reserved'))->toBe(30.0)
            ->and($data->getSummary('total_available'))->toBe(120.0)
            ->and($data->getSummary('unique_items'))->toBe(2);
    });

    it('includes all required columns', function (): void {
        $report = new StockValuationReport;
        $columns = $report->getColumns();

        $columnKeys = array_column($columns, 'key');

        expect($columnKeys)->toContain('item_id')
            ->toContain('item_sku')
            ->toContain('item_name')
            // The category is tag-backed, so the declared column is the label;
            // the stable value rides along undeclared for the JSON feed.
            ->toContain('category_label')
            ->toContain('location_id')
            ->toContain('location_code')
            ->toContain('location_name')
            ->toContain('quantity')
            ->toContain('unit_cost')
            ->toContain('total_value')
            ->toContain('reserved_quantity')
            ->toContain('available_quantity');
    });
});
