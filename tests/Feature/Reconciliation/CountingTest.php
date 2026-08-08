<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reconciliation\Actions\CompleteCounting;
use RobinsonRyan\Inventory\Reconciliation\Actions\RecordCount;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCountLine;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;

beforeEach(function (): void {
    $this->location = Location::factory()->create();
    $this->item = Item::factory()->create();
    $this->stock = Stock::factory()
        ->forItem($this->item->id)
        ->forLocation($this->location)
        ->withQuantity(100)
        ->create();
});

describe('RecordCount', function (): void {
    it('records a count', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $line = CycleCountLine::create([
            'cycle_count_id' => $count->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
        ]);

        $action = App::make(RecordCount::class);
        $updated = $action->execute($line, 95.0);

        expect((float) $updated->counted_quantity)->toBe(95.0)
            ->and($updated->counted_at)->not->toBeNull()
            ->and($updated->isCounted())->toBeTrue();
    });

    it('calculates variance', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $line = CycleCountLine::create([
            'cycle_count_id' => $count->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
        ]);

        $action = App::make(RecordCount::class);
        $updated = $action->execute($line, 95.0);

        expect((float) $updated->variance)->toBe(-5.0)
            ->and($updated->hasVariance())->toBeTrue();
    });

    it('calculates positive variance when more found', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $line = CycleCountLine::create([
            'cycle_count_id' => $count->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
        ]);

        $action = App::make(RecordCount::class);
        $updated = $action->execute($line, 110.0);

        expect((float) $updated->variance)->toBe(10.0);
    });

    it('updates cycle count to in-progress when first count recorded', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $line = CycleCountLine::create([
            'cycle_count_id' => $count->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
        ]);

        $action = App::make(RecordCount::class);
        $action->execute($line, 95.0);

        $count->refresh();
        expect($count->status)->toBe('in-progress')
            ->and($count->started_at)->not->toBeNull();
    });

    it('records a second count against an already started cycle count', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $first = CycleCountLine::create([
            'cycle_count_id' => $count->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
        ]);

        $second = CycleCountLine::create([
            'cycle_count_id' => $count->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 40,
        ]);

        $action = App::make(RecordCount::class);
        $action->execute($first, 95.0);

        // The first count moved the cycle count out of draft; the second must
        // still be accepted against the resulting "in-progress" status.
        $updated = $action->execute($second, 38.0);

        expect((float) $updated->counted_quantity)->toBe(38.0)
            ->and($count->fresh()->status)->toBe('in-progress');
    });

    it('adds notes to line', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $line = CycleCountLine::create([
            'cycle_count_id' => $count->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
        ]);

        $action = App::make(RecordCount::class);
        $updated = $action->execute($line, 95.0, 'Found 5 damaged items');

        expect($updated->notes)->toBe('Found 5 damaged items');
    });

    it('cannot record count for approved cycle count', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->approved()
            ->create();

        $line = CycleCountLine::create([
            'cycle_count_id' => $count->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
        ]);

        $action = App::make(RecordCount::class);

        expect(fn () => $action->execute($line, 95.0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('cannot record count for cancelled cycle count', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->cancelled()
            ->create();

        $line = CycleCountLine::create([
            'cycle_count_id' => $count->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
        ]);

        $action = App::make(RecordCount::class);

        expect(fn () => $action->execute($line, 95.0))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('CompleteCounting', function (): void {
    it('marks count as complete', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->inProgress()
            ->create();

        // Create fully counted line
        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        $action = App::make(CompleteCounting::class);
        $completed = $action->execute($count);

        expect($completed->status)->toBe('pending')
            ->and($completed->completed_at)->not->toBeNull();
    });

    it('validates all lines counted before completion', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->inProgress()
            ->create();

        // Create uncounted line
        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
        ]);

        $action = App::make(CompleteCounting::class);

        expect(fn () => $action->execute($count))
            ->toThrow(InvalidArgumentException::class, 'not been counted');
    });

    it('cannot complete a draft count', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $action = App::make(CompleteCounting::class);

        expect(fn () => $action->execute($count))
            ->toThrow(InvalidArgumentException::class, 'in-progress');
    });

    it('cannot complete already pending count', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        $action = App::make(CompleteCounting::class);

        expect(fn () => $action->execute($count))
            ->toThrow(InvalidArgumentException::class, 'in-progress');
    });

    it('cannot complete approved count', function (): void {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->approved()
            ->create();

        $action = App::make(CompleteCounting::class);

        expect(fn () => $action->execute($count))
            ->toThrow(InvalidArgumentException::class, 'in-progress');
    });
});

describe('CycleCountLine Model', function (): void {
    it('checks if counted', function (): void {
        $line = new CycleCountLine([
            'expected_quantity' => 100,
        ]);

        expect($line->isCounted())->toBeFalse();

        $line->counted_at = now();
        expect($line->isCounted())->toBeTrue();
    });

    it('checks if has variance', function (): void {
        $line = new CycleCountLine([
            'expected_quantity' => 100,
            'counted_quantity' => 100,
            'variance' => 0,
        ]);

        expect($line->hasVariance())->toBeFalse();

        $line->variance = -5;
        expect($line->hasVariance())->toBeTrue();
    });

    it('calculates variance', function (): void {
        $line = new CycleCountLine([
            'expected_quantity' => 100,
            'counted_quantity' => 95,
        ]);

        expect($line->calculateVariance())->toBe(-5.0);
    });

    it('returns zero variance when not counted', function (): void {
        $line = new CycleCountLine([
            'expected_quantity' => 100,
        ]);

        expect($line->calculateVariance())->toBe(0.0);
    });
});
