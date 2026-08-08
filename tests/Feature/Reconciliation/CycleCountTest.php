<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reconciliation\Actions\InitiateCycleCount;
use RobinsonRyan\Inventory\Reconciliation\Data\CycleCountData;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Reconciliation\Services\ReconciliationService;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;

beforeEach(function (): void {
    $this->service = App::make(ReconciliationService::class);
    $this->location = Location::factory()->create();
    $this->item = Item::factory()->create();
});

describe('InitiateCycleCount', function (): void {
    it('creates a cycle count', function (): void {
        $data = new CycleCountData(
            locationId: $this->location->id,
            notes: 'Test cycle count',
        );

        $action = App::make(InitiateCycleCount::class);
        $count = $action->execute($data);

        expect($count)->toBeInstanceOf(CycleCount::class)
            ->and($count->location_id)->toBe($this->location->id)
            ->and($count->status)->toBe('draft')
            ->and($count->notes)->toBe('Test cycle count')
            ->and($count->count_number)->toStartWith('CC-');
    });

    it('creates lines for all items at location', function (): void {
        // Create stock at the location
        Stock::factory()
            ->forItem($this->item->id)
            ->forLocation($this->location)
            ->withQuantity(100)
            ->create();

        $item2 = Item::factory()->create();
        Stock::factory()
            ->forItem($item2->id)
            ->forLocation($this->location)
            ->withQuantity(50)
            ->create();

        $data = new CycleCountData(
            locationId: $this->location->id,
        );

        $action = App::make(InitiateCycleCount::class);
        $count = $action->execute($data);

        expect($count->lines)->toHaveCount(2);
    });

    it('creates lines for specific items', function (): void {
        // Create stock at the location
        Stock::factory()
            ->forItem($this->item->id)
            ->forLocation($this->location)
            ->withQuantity(100)
            ->create();

        $item2 = Item::factory()->create();
        Stock::factory()
            ->forItem($item2->id)
            ->forLocation($this->location)
            ->withQuantity(50)
            ->create();

        $data = new CycleCountData(
            locationId: $this->location->id,
            itemIds: [$this->item->id],
        );

        $action = App::make(InitiateCycleCount::class);
        $count = $action->execute($data);

        expect($count->lines)->toHaveCount(1)
            ->and($count->lines->first()->item_id)->toBe($this->item->id);
    });

    it('calculates expected quantities from stock', function (): void {
        Stock::factory()
            ->forItem($this->item->id)
            ->forLocation($this->location)
            ->withQuantity(75.5)
            ->create();

        $data = new CycleCountData(
            locationId: $this->location->id,
        );

        $action = App::make(InitiateCycleCount::class);
        $count = $action->execute($data);

        expect((float) $count->lines->first()->expected_quantity)->toBe(75.5);
    });

    it('includes lot and serial number from stock', function (): void {
        Stock::factory()
            ->forItem($this->item->id)
            ->forLocation($this->location)
            ->withQuantity(10)
            ->withLotNumber('LOT-001')
            ->create();

        $data = new CycleCountData(
            locationId: $this->location->id,
        );

        $action = App::make(InitiateCycleCount::class);
        $count = $action->execute($data);

        expect($count->lines->first()->lot_number)->toBe('LOT-001');
    });

    it('auto-generates count number', function (): void {
        $data = new CycleCountData(locationId: $this->location->id);

        $action = App::make(InitiateCycleCount::class);
        $count = $action->execute($data);

        expect($count->count_number)->toMatch('/^CC-\d{8}-\d{4}$/');
    });

    it('does not create lines for zero quantity stock', function (): void {
        Stock::factory()
            ->forItem($this->item->id)
            ->forLocation($this->location)
            ->empty()
            ->create();

        $data = new CycleCountData(
            locationId: $this->location->id,
        );

        $action = App::make(InitiateCycleCount::class);
        $count = $action->execute($data);

        expect($count->lines)->toHaveCount(0);
    });

    it('sets scheduled date when provided', function (): void {
        $scheduledDate = now()->addDays(3)->format('Y-m-d');

        $data = new CycleCountData(
            locationId: $this->location->id,
            scheduledDate: $scheduledDate,
        );

        $action = App::make(InitiateCycleCount::class);
        $count = $action->execute($data);

        expect($count->scheduled_date->format('Y-m-d'))->toBe($scheduledDate);
    });
});

describe('CycleCount Model', function (): void {
    it('calculates total variance', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->inProgress()
            ->create();

        // Create lines with variance
        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        $count->lines()->create([
            'item_id' => Item::factory()->create()->id,
            'location_id' => $this->location->id,
            'expected_quantity' => 50,
            'counted_quantity' => 55,
            'variance' => 5,
            'counted_at' => now(),
        ]);

        expect($count->getTotalVariance())->toBe(0.0);
    });

    it('counts lines with variances', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->inProgress()
            ->create();

        // Line with variance
        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        // Line without variance
        $count->lines()->create([
            'item_id' => Item::factory()->create()->id,
            'location_id' => $this->location->id,
            'expected_quantity' => 50,
            'counted_quantity' => 50,
            'variance' => 0,
            'counted_at' => now(),
        ]);

        expect($count->getVarianceCount())->toBe(1);
    });

    it('checks if fully counted', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->inProgress()
            ->create();

        // Uncounted line
        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'expected_quantity' => 100,
        ]);

        expect($count->isFullyCounted())->toBeFalse();

        // Mark as counted
        $count->lines->first()->update([
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        $count->refresh();
        expect($count->isFullyCounted())->toBeTrue();
    });
});

describe('ReconciliationService', function (): void {
    it('generates sequential count numbers', function (): void {
        $cc1 = $this->service->generateCountNumber();
        $cc2 = $this->service->generateCountNumber();

        expect($cc1)->toMatch('/^CC-\d{8}-0001$/')
            ->and($cc2)->toMatch('/^CC-\d{8}-0001$/');

        // Create a count with first number
        CycleCount::factory()
            ->forLocation($this->location)
            ->state(['count_number' => $cc1])
            ->create();

        $cc3 = $this->service->generateCountNumber();
        expect($cc3)->toMatch('/^CC-\d{8}-0002$/');
    });

    it('gets items to count for location', function (): void {
        Stock::factory()
            ->forItem($this->item->id)
            ->forLocation($this->location)
            ->withQuantity(100)
            ->create();

        $items = $this->service->getItemsToCount($this->location->id);

        expect($items)->toHaveCount(1)
            ->and($items->first()->item_id)->toBe($this->item->id);
    });

    it('does not include zero quantity items', function (): void {
        Stock::factory()
            ->forItem($this->item->id)
            ->forLocation($this->location)
            ->empty()
            ->create();

        $items = $this->service->getItemsToCount($this->location->id);

        expect($items)->toHaveCount(0);
    });

    it('checks if count can be approved', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        // All lines counted
        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        expect($this->service->canApprove($count))->toBeTrue();
    });

    it('cannot approve draft count', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        expect($this->service->canApprove($count))->toBeFalse();
    });

    it('gets counts requiring approval', function (): void {
        CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        CycleCount::factory()
            ->forLocation($this->location)
            ->approved()
            ->create();

        $pending = $this->service->getCountsRequiringApproval();

        expect($pending)->toHaveCount(1)
            ->and($pending->first()->status)->toBe('pending');
    });

    it('gets open counts', function (): void {
        CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        CycleCount::factory()
            ->forLocation($this->location)
            ->inProgress()
            ->create();

        CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        CycleCount::factory()
            ->forLocation($this->location)
            ->approved()
            ->create();

        $open = $this->service->getOpenCounts();

        expect($open)->toHaveCount(3);
    });
});
