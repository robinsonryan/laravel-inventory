<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $cycle_count_id
 * @property string $cycle_count_line_id
 * @property string $item_id
 * @property string $location_id
 * @property string $quantity_before
 * @property string $quantity_after
 * @property string $variance
 * @property string|null $cost_impact
 * @property string $stock_movement_id
 * @property string|null $reason
 * @property string|null $approved_by
 * @property \Illuminate\Support\Carbon $approved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CycleCount $cycleCount
 * @property-read CycleCountLine $cycleCountLine
 * @property-read Model $item
 * @property-read Location $location
 * @property-read StockMovement $stockMovement
 */
final class VarianceAdjustment extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $fillable = [
        'tenant_id',
        'cycle_count_id',
        'cycle_count_line_id',
        'item_id',
        'location_id',
        'quantity_before',
        'quantity_after',
        'variance',
        'cost_impact',
        'stock_movement_id',
        'reason',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'quantity_before' => 'decimal:4',
        'quantity_after' => 'decimal:4',
        'variance' => 'decimal:4',
        'cost_impact' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.variance_adjustments', 'variance_adjustments');
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
     * @return BelongsTo<CycleCountLine, $this>
     */
    public function cycleCountLine(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.cycle_count_line', CycleCountLine::class),
            'cycle_count_line_id'
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
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.stock_movement', StockMovement::class),
            'stock_movement_id'
        );
    }

    /**
     * Check if this adjustment is a positive variance (found more than expected).
     */
    public function isPositiveVariance(): bool
    {
        return (float) $this->variance > 0;
    }

    /**
     * Check if this adjustment is a negative variance (found less than expected).
     */
    public function isNegativeVariance(): bool
    {
        return (float) $this->variance < 0;
    }
}
