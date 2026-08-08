<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Reports\LowStockReport;
use RobinsonRyan\Inventory\Reporting\Services\ReportService;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Tests\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Write one movement straight to the ledger.
 *
 * The report reads the ledger as a raw aggregate, and what it counts is decided
 * by which of the two location columns are filled in — so the test states those
 * columns directly rather than going through a service that would choose them.
 *
 * @param  array<string, mixed>  $attributes
 */
function insertLowStockMovement(array $attributes): void
{
    DB::table('stock_movements')->insert([
        'id' => (string) Uuid::v7(),
        'tenant_id' => TestCase::TENANT_ID,
        'created_at' => Carbon::now(),
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    $this->reportService = App::make(ReportService::class);
    $this->location = Location::factory()->warehouse()->create(['code' => 'WH1']);
});

describe('LowStockReport', function (): void {
    it('generates low stock report', function (): void {
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

        $report = new LowStockReport;
        $data = $this->reportService->generate($report);

        expect($data)->toBeInstanceOf(ReportData::class)
            ->and($data->title)->toBe('Low Stock Report')
            ->and($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('LOW-001');
    });

    it('includes zero stock items', function (): void {
        $item = Item::factory()->create([
            'sku' => 'ZERO-001',
            'min_stock_level' => 50,
            'is_active' => true,
        ]);

        // No stock record created = zero stock

        $report = new LowStockReport(includeZero: true);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('ZERO-001')
            ->and((float) $data->rows->first()['current_quantity'])->toBe(0.0);
    });

    it('excludes zero stock items when requested', function (): void {
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

        $report = new LowStockReport(includeBelowMin: true, includeZero: false);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('LOW-001');
    });

    it('filters by category', function (): void {
        // The category is a tag, and taxon writes the pivot row immediately, so
        // it can only be set once the item has an id. The factory states do
        // exactly that, after creating.
        $partItem = Item::factory()->part()->create([
            'sku' => 'PART-001',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        $supplyItem = Item::factory()->supply()->create([
            'sku' => 'SUPPLY-001',
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
            ->and($data->rows->first()['item_sku'])->toBe('PART-001')
            ->and($data->rows->first()['category_value'])->toBe('part')
            ->and($data->rows->first()['category_label'])->toBe('Part');
    });

    it('calculates shortfall correctly', function (): void {
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

        $report = new LowStockReport;
        $data = $this->reportService->generate($report);

        // Available = 60 - 10 = 50
        // Shortfall = 100 - 50 = 50
        expect((float) $data->rows->first()['current_quantity'])->toBe(60.0)
            ->and((float) $data->rows->first()['available_quantity'])->toBe(50.0)
            ->and((float) $data->rows->first()['shortfall'])->toBe(50.0);
    });

    it('aggregates stock across locations', function (): void {
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

        $report = new LowStockReport;
        $data = $this->reportService->generate($report);

        // Total = 30 + 40 = 70, still below min of 100
        expect($data->rows)->toHaveCount(1)
            ->and((float) $data->rows->first()['current_quantity'])->toBe(70.0)
            ->and((float) $data->rows->first()['shortfall'])->toBe(30.0);
    });

    it('excludes inactive items', function (): void {
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

        $report = new LowStockReport;
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('ACTIVE-001');
    });

    it('averages thirty days of stock that left, and never counts a transfer', function (): void {
        $otherWarehouse = Location::factory()->warehouse()->create(['code' => 'WH2']);

        $item = Item::factory()->create([
            'sku' => 'USAGE-001',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($this->location)
            ->withQuantity(60)
            ->create();

        // 60 units left the business inside the window: 2 a day over 30 days.
        insertLowStockMovement([
            'item_id' => $item->id,
            'from_location_id' => $this->location->id,
            'quantity' => 45,
            'created_at' => Carbon::now()->subDays(10),
        ]);
        insertLowStockMovement([
            'item_id' => $item->id,
            'from_location_id' => $this->location->id,
            'quantity' => 15,
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // Moving stock between two of our own locations consumes nothing.
        insertLowStockMovement([
            'item_id' => $item->id,
            'from_location_id' => $this->location->id,
            'to_location_id' => $otherWarehouse->id,
            'quantity' => 300,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        // Older than the window the average is taken over.
        insertLowStockMovement([
            'item_id' => $item->id,
            'from_location_id' => $this->location->id,
            'quantity' => 900,
            'created_at' => Carbon::now()->subDays(45),
        ]);

        $data = $this->reportService->generate(new LowStockReport);
        $row = $data->rows->first();

        // 60 available at 2 a day is 30 days of cover.
        expect((float) $row['average_daily_usage'])->toBe(2.0)
            ->and($row['days_of_stock'])->toBe(30);
    });

    it('reports no days of cover for an item nothing is consuming', function (): void {
        $item = Item::factory()->create([
            'sku' => 'IDLE-001',
            'min_stock_level' => 100,
            'is_active' => true,
        ]);

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($this->location)
            ->withQuantity(60)
            ->create();

        // The only movement is a transfer, so nothing has been used.
        insertLowStockMovement([
            'item_id' => $item->id,
            'from_location_id' => $this->location->id,
            'to_location_id' => Location::factory()->warehouse()->create()->id,
            'quantity' => 25,
        ]);

        $row = $this->reportService->generate(new LowStockReport)->rows->first();

        expect((float) $row['average_daily_usage'])->toBe(0.0)
            ->and($row['days_of_stock'])->toBeNull();
    });

    it('calculates summary correctly', function (): void {
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

        $report = new LowStockReport;
        $data = $this->reportService->generate($report);

        expect($data->getSummary('total_items'))->toBe(3)
            ->and($data->getSummary('zero_stock_count'))->toBe(1)
            ->and($data->getSummary('below_min_count'))->toBe(2)
            ->and($data->getSummary('total_shortfall'))->toBe(140.0); // 50 + 40 + 50
    });
});
