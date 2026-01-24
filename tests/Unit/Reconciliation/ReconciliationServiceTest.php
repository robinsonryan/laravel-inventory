<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Reconciliation\Services\ReconciliationService;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;

beforeEach(function () {
    $this->service = App::make(ReconciliationService::class);
    $this->location = Location::factory()->create();
});

describe('ReconciliationService', function () {
    describe('generateCountNumber', function () {
        it('generates count number with correct format', function () {
            $countNumber = $this->service->generateCountNumber();

            expect($countNumber)->toStartWith('CC-')
                ->and($countNumber)->toMatch('/^CC-\d{8}-\d{4}$/');
        });

        it('increments sequence for same date', function () {
            // Create first count
            CycleCount::factory()
                ->forLocation($this->location)
                ->state(['count_number' => $this->service->generateCountNumber()])
                ->create();

            // Get next number
            $countNumber = $this->service->generateCountNumber();

            expect($countNumber)->toEndWith('-0002');
        });

        it('respects tenant isolation', function () {
            $tenantId = (string) \Symfony\Component\Uid\Uuid::v7();

            // Create count for tenant
            CycleCount::factory()
                ->forLocation($this->location)
                ->forTenant($tenantId)
                ->state(['count_number' => $this->service->generateCountNumber($tenantId)])
                ->create();

            // Get next number for same tenant
            $countNumber = $this->service->generateCountNumber($tenantId);
            expect($countNumber)->toEndWith('-0002');

            // Get number for different tenant (should be -0001)
            $otherTenantId = (string) \Symfony\Component\Uid\Uuid::v7();
            $otherCountNumber = $this->service->generateCountNumber($otherTenantId);
            expect($otherCountNumber)->toEndWith('-0001');
        });
    });

    describe('getItemsToCount', function () {
        it('returns stock records with positive quantity', function () {
            $item = Item::factory()->create();
            Stock::factory()
                ->forItem($item->id)
                ->forLocation($this->location)
                ->withQuantity(50)
                ->create();

            $items = $this->service->getItemsToCount($this->location->id);

            expect($items)->toHaveCount(1)
                ->and((float) $items->first()->quantity)->toBe(50.0);
        });

        it('excludes zero quantity stock', function () {
            $item = Item::factory()->create();
            Stock::factory()
                ->forItem($item->id)
                ->forLocation($this->location)
                ->empty()
                ->create();

            $items = $this->service->getItemsToCount($this->location->id);

            expect($items)->toHaveCount(0);
        });

        it('excludes negative quantity stock', function () {
            $item = Item::factory()->create();
            Stock::factory()
                ->forItem($item->id)
                ->forLocation($this->location)
                ->state(['quantity' => -10])
                ->create();

            $items = $this->service->getItemsToCount($this->location->id);

            expect($items)->toHaveCount(0);
        });

        it('eager loads item relationship', function () {
            $item = Item::factory()->create();
            Stock::factory()
                ->forItem($item->id)
                ->forLocation($this->location)
                ->withQuantity(50)
                ->create();

            $items = $this->service->getItemsToCount($this->location->id);

            expect($items->first()->relationLoaded('item'))->toBeTrue();
        });
    });

    describe('calculateVarianceValue', function () {
        it('calculates total variance value using cost basis', function () {
            $item = Item::factory()->create();
            $stock = Stock::factory()
                ->forItem($item->id)
                ->forLocation($this->location)
                ->withQuantity(100)
                ->withCost(25.0)
                ->create();

            $count = CycleCount::factory()
                ->forLocation($this->location)
                ->pending()
                ->create();

            $count->lines()->create([
                'item_id' => $item->id,
                'location_id' => $this->location->id,
                'stock_id' => $stock->id,
                'expected_quantity' => 100,
                'counted_quantity' => 90,
                'variance' => -10,
                'counted_at' => now(),
            ]);

            $value = $this->service->calculateVarianceValue($count);

            expect($value)->toBe(-250.0); // -10 units * $25 cost
        });

        it('handles multiple lines', function () {
            $item1 = Item::factory()->create();
            $stock1 = Stock::factory()
                ->forItem($item1->id)
                ->forLocation($this->location)
                ->withQuantity(100)
                ->withCost(10.0)
                ->create();

            $item2 = Item::factory()->create();
            $stock2 = Stock::factory()
                ->forItem($item2->id)
                ->forLocation($this->location)
                ->withQuantity(50)
                ->withCost(20.0)
                ->create();

            $count = CycleCount::factory()
                ->forLocation($this->location)
                ->pending()
                ->create();

            $count->lines()->create([
                'item_id' => $item1->id,
                'location_id' => $this->location->id,
                'stock_id' => $stock1->id,
                'expected_quantity' => 100,
                'counted_quantity' => 95,
                'variance' => -5,
                'counted_at' => now(),
            ]);

            $count->lines()->create([
                'item_id' => $item2->id,
                'location_id' => $this->location->id,
                'stock_id' => $stock2->id,
                'expected_quantity' => 50,
                'counted_quantity' => 55,
                'variance' => 5,
                'counted_at' => now(),
            ]);

            $value = $this->service->calculateVarianceValue($count);

            // -5 * $10 + 5 * $20 = -50 + 100 = 50
            expect($value)->toBe(50.0);
        });

        it('returns zero for no variances', function () {
            $count = CycleCount::factory()
                ->forLocation($this->location)
                ->pending()
                ->create();

            $value = $this->service->calculateVarianceValue($count);

            expect($value)->toBe(0.0);
        });
    });

    describe('canApprove', function () {
        it('returns true for pending fully counted', function () {
            $count = CycleCount::factory()
                ->forLocation($this->location)
                ->pending()
                ->create();

            $item = Item::factory()->create();
            $count->lines()->create([
                'item_id' => $item->id,
                'location_id' => $this->location->id,
                'expected_quantity' => 100,
                'counted_quantity' => 100,
                'variance' => 0,
                'counted_at' => now(),
            ]);

            expect($this->service->canApprove($count))->toBeTrue();
        });

        it('returns false for non-pending status', function () {
            $count = CycleCount::factory()
                ->forLocation($this->location)
                ->draft()
                ->create();

            expect($this->service->canApprove($count))->toBeFalse();
        });

        it('returns false when not fully counted', function () {
            $count = CycleCount::factory()
                ->forLocation($this->location)
                ->pending()
                ->create();

            $item = Item::factory()->create();
            $count->lines()->create([
                'item_id' => $item->id,
                'location_id' => $this->location->id,
                'expected_quantity' => 100,
            ]);

            expect($this->service->canApprove($count))->toBeFalse();
        });
    });

    describe('canComplete', function () {
        it('returns true for in_progress fully counted', function () {
            $count = CycleCount::factory()
                ->forLocation($this->location)
                ->inProgress()
                ->create();

            $item = Item::factory()->create();
            $count->lines()->create([
                'item_id' => $item->id,
                'location_id' => $this->location->id,
                'expected_quantity' => 100,
                'counted_quantity' => 100,
                'variance' => 0,
                'counted_at' => now(),
            ]);

            expect($this->service->canComplete($count))->toBeTrue();
        });

        it('returns false for non-in_progress status', function () {
            $count = CycleCount::factory()
                ->forLocation($this->location)
                ->draft()
                ->create();

            expect($this->service->canComplete($count))->toBeFalse();
        });
    });

    describe('canCancel', function () {
        it('returns true for open statuses', function () {
            $draft = CycleCount::factory()
                ->forLocation($this->location)
                ->draft()
                ->create();

            $inProgress = CycleCount::factory()
                ->forLocation($this->location)
                ->inProgress()
                ->create();

            $pending = CycleCount::factory()
                ->forLocation($this->location)
                ->pending()
                ->create();

            expect($this->service->canCancel($draft))->toBeTrue()
                ->and($this->service->canCancel($inProgress))->toBeTrue()
                ->and($this->service->canCancel($pending))->toBeTrue();
        });

        it('returns false for terminal statuses', function () {
            $approved = CycleCount::factory()
                ->forLocation($this->location)
                ->approved()
                ->create();

            $cancelled = CycleCount::factory()
                ->forLocation($this->location)
                ->cancelled()
                ->create();

            expect($this->service->canCancel($approved))->toBeFalse()
                ->and($this->service->canCancel($cancelled))->toBeFalse();
        });
    });

    describe('getLinesWithVariances', function () {
        it('returns only lines with non-zero variance', function () {
            $count = CycleCount::factory()
                ->forLocation($this->location)
                ->pending()
                ->create();

            $item1 = Item::factory()->create();
            $item2 = Item::factory()->create();

            $count->lines()->create([
                'item_id' => $item1->id,
                'location_id' => $this->location->id,
                'expected_quantity' => 100,
                'counted_quantity' => 95,
                'variance' => -5,
                'counted_at' => now(),
            ]);

            $count->lines()->create([
                'item_id' => $item2->id,
                'location_id' => $this->location->id,
                'expected_quantity' => 50,
                'counted_quantity' => 50,
                'variance' => 0,
                'counted_at' => now(),
            ]);

            $withVariances = $this->service->getLinesWithVariances($count);

            expect($withVariances)->toHaveCount(1)
                ->and($withVariances->first()->item_id)->toBe($item1->id);
        });
    });
});
