<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;
use RobinsonRyan\Inventory\Support\MovementScopes;
use RobinsonRyan\Inventory\Tests\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @param  array<string, mixed>  $attributes
 */
function insertMovementForScopeTest(array $attributes): string
{
    $id = (string) Uuid::v7();

    DB::table('stock_movements')->insert([
        'id' => $id,
        'tenant_id' => TestCase::TENANT_ID,
        'item_id' => (string) Uuid::v7(),
        'quantity' => 1,
        'created_at' => now(),
        ...$attributes,
    ]);

    return $id;
}

describe('MovementScopes', function (): void {
    beforeEach(function (): void {
        $this->from = Location::factory()->create();
        $this->to = Location::factory()->create();

        $this->outflow = insertMovementForScopeTest(['from_location_id' => $this->from->id]);
        $this->inflow = insertMovementForScopeTest(['to_location_id' => $this->to->id]);
        $this->transfer = insertMovementForScopeTest([
            'from_location_id' => $this->from->id,
            'to_location_id' => $this->to->id,
        ]);
    });

    it('counts only movements that left a location and arrived nowhere', function (): void {
        $ids = MovementScopes::depleting(DB::table('stock_movements'))->pluck('id')->all();

        expect($ids)->toBe([$this->outflow]);
    });

    it('applies the same rule to an Eloquent query', function (): void {
        $ids = MovementScopes::depleting(StockMovement::query())->pluck('id')->all();

        expect($ids)->toBe([$this->outflow]);
    });
});
