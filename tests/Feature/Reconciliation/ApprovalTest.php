<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reconciliation\Actions\ApproveVariance;
use RobinsonRyan\Inventory\Reconciliation\Actions\CancelCycleCount;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCountLine;
use RobinsonRyan\Inventory\Reconciliation\Models\VarianceAdjustment;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

beforeEach(function () {
    $this->location = Location::factory()->create();
    $this->item = Item::factory()->create();
    $this->stock = Stock::factory()
        ->forItem($this->item->id)
        ->forLocation($this->location)
        ->withQuantity(100)
        ->withCost(10.0)
        ->create();
});

describe('ApproveVariance', function () {
    it('approves variances', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        $action = App::make(ApproveVariance::class);
        $approved = $action->execute($count, 'Damaged items written off');

        expect($approved->status)->toBe('approved')
            ->and($approved->approved_at)->not->toBeNull();
    });

    it('creates adjustment movements for variances', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        $action = App::make(ApproveVariance::class);
        $approved = $action->execute($count);

        $movement = StockMovement::query()
            ->where('reference_type', CycleCount::class)
            ->where('reference_id', $count->id)
            ->first();

        expect($movement)->not->toBeNull()
            ->and((float) $movement->quantity)->toBe(5.0)
            ->and($movement->type)->toBe('adjustment');
    });

    it('updates stock quantity after approval', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        $action = App::make(ApproveVariance::class);
        $action->execute($count);

        $this->stock->refresh();
        expect((float) $this->stock->quantity)->toBe(95.0);
    });

    it('tracks cost impact', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        $action = App::make(ApproveVariance::class);
        $approved = $action->execute($count);

        $adjustment = $approved->adjustments->first();

        expect($adjustment)->not->toBeNull()
            ->and((float) $adjustment->cost_impact)->toBe(-50.0); // -5 units * $10 cost
    });

    it('creates variance adjustment record', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        $line = $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
            'counted_quantity' => 95,
            'variance' => -5,
            'counted_at' => now(),
        ]);

        $action = App::make(ApproveVariance::class);
        $approved = $action->execute($count, 'Test reason');

        $adjustment = VarianceAdjustment::query()
            ->where('cycle_count_id', $count->id)
            ->first();

        expect($adjustment)->not->toBeNull()
            ->and($adjustment->cycle_count_line_id)->toBe($line->id)
            ->and((float) $adjustment->quantity_before)->toBe(100.0)
            ->and((float) $adjustment->quantity_after)->toBe(95.0)
            ->and((float) $adjustment->variance)->toBe(-5.0)
            ->and($adjustment->reason)->toBe('Test reason')
            ->and($adjustment->stock_movement_id)->not->toBeNull();
    });

    it('handles positive variance (found more)', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
            'counted_quantity' => 110,
            'variance' => 10,
            'counted_at' => now(),
        ]);

        $action = App::make(ApproveVariance::class);
        $approved = $action->execute($count);

        $this->stock->refresh();
        expect((float) $this->stock->quantity)->toBe(110.0);

        $adjustment = $approved->adjustments->first();
        expect((float) $adjustment->variance)->toBe(10.0)
            ->and((float) $adjustment->cost_impact)->toBe(100.0);
    });

    it('does not create adjustment for zero variance', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        $count->lines()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'stock_id' => $this->stock->id,
            'expected_quantity' => 100,
            'counted_quantity' => 100,
            'variance' => 0,
            'counted_at' => now(),
        ]);

        $action = App::make(ApproveVariance::class);
        $approved = $action->execute($count);

        expect($approved->adjustments)->toHaveCount(0);
        expect(StockMovement::query()
            ->where('reference_type', CycleCount::class)
            ->count())->toBe(0);
    });

    it('cannot approve draft count', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $action = App::make(ApproveVariance::class);

        expect(fn () => $action->execute($count))
            ->toThrow(InvalidArgumentException::class);
    });

    it('cannot approve in_progress count', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->inProgress()
            ->create();

        $action = App::make(ApproveVariance::class);

        expect(fn () => $action->execute($count))
            ->toThrow(InvalidArgumentException::class);
    });

    it('cannot approve already approved count', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->approved()
            ->create();

        $action = App::make(ApproveVariance::class);

        expect(fn () => $action->execute($count))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('CancelCycleCount', function () {
    it('cancels cycle count', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $action = App::make(CancelCycleCount::class);
        $cancelled = $action->execute($count, 'No longer needed');

        expect($cancelled->status)->toBe('cancelled')
            ->and($cancelled->metadata['cancellation_reason'])->toBe('No longer needed');
    });

    it('can cancel draft count', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $action = App::make(CancelCycleCount::class);
        $cancelled = $action->execute($count);

        expect($cancelled->status)->toBe('cancelled');
    });

    it('can cancel in_progress count', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->inProgress()
            ->create();

        $action = App::make(CancelCycleCount::class);
        $cancelled = $action->execute($count);

        expect($cancelled->status)->toBe('cancelled');
    });

    it('can cancel pending count', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->pending()
            ->create();

        $action = App::make(CancelCycleCount::class);
        $cancelled = $action->execute($count);

        expect($cancelled->status)->toBe('cancelled');
    });

    it('cannot cancel approved count', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->approved()
            ->create();

        $action = App::make(CancelCycleCount::class);

        expect(fn () => $action->execute($count))
            ->toThrow(InvalidArgumentException::class);
    });

    it('cannot cancel already cancelled count', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->cancelled()
            ->create();

        $action = App::make(CancelCycleCount::class);

        expect(fn () => $action->execute($count))
            ->toThrow(InvalidArgumentException::class);
    });

    it('records cancellation timestamp in metadata', function () {
        $count = CycleCount::factory()
            ->forLocation($this->location)
            ->draft()
            ->create();

        $action = App::make(CancelCycleCount::class);
        $cancelled = $action->execute($count);

        expect($cancelled->metadata)->toHaveKey('cancelled_at');
    });
});

describe('VarianceAdjustment Model', function () {
    it('identifies positive variance', function () {
        $adjustment = new VarianceAdjustment([
            'variance' => 10,
        ]);

        expect($adjustment->isPositiveVariance())->toBeTrue()
            ->and($adjustment->isNegativeVariance())->toBeFalse();
    });

    it('identifies negative variance', function () {
        $adjustment = new VarianceAdjustment([
            'variance' => -5,
        ]);

        expect($adjustment->isNegativeVariance())->toBeTrue()
            ->and($adjustment->isPositiveVariance())->toBeFalse();
    });
});
