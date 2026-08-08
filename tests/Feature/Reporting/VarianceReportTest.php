<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Reports\VarianceReport;
use RobinsonRyan\Inventory\Reporting\Services\ReportService;
use RobinsonRyan\Inventory\Stock\Models\Location;
use Symfony\Component\Uid\Uuid;

beforeEach(function (): void {
    $this->reportService = App::make(ReportService::class);
    $this->location = Location::factory()->warehouse()->create(['code' => 'WH1']);
    $this->item = Item::factory()->create(['sku' => 'TEST-001', 'name' => 'Test Item', 'cost' => 10.00]);

    // Create cycle count tables if they don't exist for testing
    $cycleCountsTable = config('inventory.tables.cycle_counts', 'cycle_counts');
    $cycleCountLinesTable = config('inventory.tables.cycle_count_lines', 'cycle_count_lines');

    if (! Illuminate\Support\Facades\Schema::hasTable($cycleCountsTable)) {
        Illuminate\Support\Facades\Schema::create($cycleCountsTable, function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('location_id');
            $table->string('count_number');
            $table->string('status')->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Illuminate\Support\Facades\Schema::hasTable($cycleCountLinesTable)) {
        Illuminate\Support\Facades\Schema::create($cycleCountLinesTable, function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('cycle_count_id');
            $table->uuid('item_id');
            $table->decimal('expected_quantity', 15, 4);
            $table->decimal('counted_quantity', 15, 4);
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }
});

describe('VarianceReport', function (): void {
    it('generates variance report', function (): void {
        // Create approved cycle count with variance
        $cycleCountId = (string) Uuid::v7();
        DB::table(config('inventory.tables.cycle_counts', 'cycle_counts'))->insert([
            'id' => $cycleCountId,
            'location_id' => $this->location->id,
            'count_number' => 'CC-001',
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'))->insert([
            'id' => (string) Uuid::v7(),
            'cycle_count_id' => $cycleCountId,
            'item_id' => $this->item->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'reason' => 'Physical shortage',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $report = new VarianceReport;
        $data = $this->reportService->generate($report);

        expect($data)->toBeInstanceOf(ReportData::class)
            ->and($data->title)->toBe('Variance Report')
            ->and($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('TEST-001')
            ->and((float) $data->rows->first()['expected_quantity'])->toBe(100.0)
            ->and((float) $data->rows->first()['counted_quantity'])->toBe(95.0)
            ->and((float) $data->rows->first()['variance'])->toBe(-5.0);
    });

    it('filters by date range', function (): void {
        // Create old cycle count
        $oldCycleCountId = (string) Uuid::v7();
        DB::table(config('inventory.tables.cycle_counts', 'cycle_counts'))->insert([
            'id' => $oldCycleCountId,
            'location_id' => $this->location->id,
            'count_number' => 'CC-001',
            'status' => 'approved',
            'approved_at' => Carbon::parse('2026-01-01'),
            'created_at' => Carbon::parse('2026-01-01'),
            'updated_at' => Carbon::parse('2026-01-01'),
        ]);

        DB::table(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'))->insert([
            'id' => (string) Uuid::v7(),
            'cycle_count_id' => $oldCycleCountId,
            'item_id' => $this->item->id,
            'expected_quantity' => 100,
            'counted_quantity' => 90,
            'created_at' => Carbon::parse('2026-01-01'),
            'updated_at' => Carbon::parse('2026-01-01'),
        ]);

        // Create recent cycle count
        $recentCycleCountId = (string) Uuid::v7();
        DB::table(config('inventory.tables.cycle_counts', 'cycle_counts'))->insert([
            'id' => $recentCycleCountId,
            'location_id' => $this->location->id,
            'count_number' => 'CC-002',
            'status' => 'approved',
            'approved_at' => Carbon::parse('2026-01-15'),
            'created_at' => Carbon::parse('2026-01-15'),
            'updated_at' => Carbon::parse('2026-01-15'),
        ]);

        DB::table(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'))->insert([
            'id' => (string) Uuid::v7(),
            'cycle_count_id' => $recentCycleCountId,
            'item_id' => $this->item->id,
            'expected_quantity' => 90,
            'counted_quantity' => 85,
            'created_at' => Carbon::parse('2026-01-15'),
            'updated_at' => Carbon::parse('2026-01-15'),
        ]);

        $report = new VarianceReport(
            fromDate: Carbon::parse('2026-01-10'),
            toDate: Carbon::parse('2026-01-20'),
        );
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['count_number'])->toBe('CC-002');
    });

    it('filters by location', function (): void {
        $location2 = Location::factory()->warehouse()->create(['code' => 'WH2']);

        // Create cycle count at location 1
        $cycleCountId1 = (string) Uuid::v7();
        DB::table(config('inventory.tables.cycle_counts', 'cycle_counts'))->insert([
            'id' => $cycleCountId1,
            'location_id' => $this->location->id,
            'count_number' => 'CC-001',
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'))->insert([
            'id' => (string) Uuid::v7(),
            'cycle_count_id' => $cycleCountId1,
            'item_id' => $this->item->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Create cycle count at location 2
        $cycleCountId2 = (string) Uuid::v7();
        DB::table(config('inventory.tables.cycle_counts', 'cycle_counts'))->insert([
            'id' => $cycleCountId2,
            'location_id' => $location2->id,
            'count_number' => 'CC-002',
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'))->insert([
            'id' => (string) Uuid::v7(),
            'cycle_count_id' => $cycleCountId2,
            'item_id' => $this->item->id,
            'expected_quantity' => 50,
            'counted_quantity' => 45,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $report = new VarianceReport(locationId: $this->location->id);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['location_code'])->toBe('WH1');
    });

    it('calculates cost impact', function (): void {
        $cycleCountId = (string) Uuid::v7();
        DB::table(config('inventory.tables.cycle_counts', 'cycle_counts'))->insert([
            'id' => $cycleCountId,
            'location_id' => $this->location->id,
            'count_number' => 'CC-001',
            'status' => 'approved',
            'approved_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'))->insert([
            'id' => (string) Uuid::v7(),
            'cycle_count_id' => $cycleCountId,
            'item_id' => $this->item->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95, // -5 variance * $10 cost = -$50 impact
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $report = new VarianceReport;
        $data = $this->reportService->generate($report);

        // Cost impact = (95 - 100) * 10 = -50
        expect((float) $data->rows->first()['cost_impact'])->toBe(-50.0)
            ->and($data->getSummary('total_cost_impact'))->toBe(-50.0)
            ->and($data->getSummary('negative_variances'))->toBe(1);
    });

    it('only includes approved cycle counts', function (): void {
        // Create pending cycle count (should not appear)
        $pendingId = (string) Uuid::v7();
        DB::table(config('inventory.tables.cycle_counts', 'cycle_counts'))->insert([
            'id' => $pendingId,
            'location_id' => $this->location->id,
            'count_number' => 'CC-PENDING',
            'status' => 'pending',
            'approved_at' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'))->insert([
            'id' => (string) Uuid::v7(),
            'cycle_count_id' => $pendingId,
            'item_id' => $this->item->id,
            'expected_quantity' => 100,
            'counted_quantity' => 90,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $report = new VarianceReport;
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(0);
    });
});
