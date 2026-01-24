<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;

/**
 * @property string $id
 * @property string $cycle_count_id
 * @property string $item_id
 * @property string $location_id
 * @property string|null $stock_id
 * @property string $expected_quantity
 * @property string|null $counted_quantity
 * @property string|null $variance
 * @property string|null $lot_number
 * @property string|null $serial_number
 * @property string|null $counted_by
 * @property \Illuminate\Support\Carbon|null $counted_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CycleCount $cycleCount
 * @property-read Model $item
 * @property-read Location $location
 * @property-read Stock|null $stock
 */
final class CycleCountLine extends Model
{
    use HasUuid7;

    protected $fillable = [
        'cycle_count_id',
        'item_id',
        'location_id',
        'stock_id',
        'expected_quantity',
        'counted_quantity',
        'variance',
        'lot_number',
        'serial_number',
        'counted_by',
        'counted_at',
        'notes',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:4',
        'counted_quantity' => 'decimal:4',
        'variance' => 'decimal:4',
        'counted_at' => 'datetime',
    ];

    protected $attributes = [
        'expected_quantity' => '0',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.cycle_count_lines', 'cycle_count_lines');
    }

    /**
     * @return BelongsTo<CycleCount, $this>
     */
    public function cycleCount(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.cycle_count', CycleCount::class),
            'cycle_count_id'
        );
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.item', Item::class),
            'item_id'
        );
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.location', Location::class),
            'location_id'
        );
    }

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.stock', Stock::class),
            'stock_id'
        );
    }

    /**
     * Check if this line has been counted.
     */
    public function isCounted(): bool
    {
        return $this->counted_at !== null;
    }

    /**
     * Check if this line has a variance.
     */
    public function hasVariance(): bool
    {
        return $this->variance !== null && (float) $this->variance !== 0.0;
    }

    /**
     * Calculate variance from counted and expected quantities.
     */
    public function calculateVariance(): float
    {
        if ($this->counted_quantity === null) {
            return 0.0;
        }

        return (float) $this->counted_quantity - (float) $this->expected_quantity;
    }
}
