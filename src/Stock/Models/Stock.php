<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Stock\Database\Factories\StockFactory;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $item_id
 * @property string $location_id
 * @property float $quantity
 * @property float $reserved_quantity
 * @property float $available_quantity
 * @property float|null $cost_basis
 * @property string|null $lot_number
 * @property string|null $serial_number
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Model $item
 * @property-read Location $location
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StockMovement> $movements
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Reservation> $reservations
 */
final class Stock extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid7;

    protected $fillable = [
        'tenant_id',
        'item_id',
        'location_id',
        'quantity',
        'reserved_quantity',
        'cost_basis',
        'lot_number',
        'serial_number',
        'expiry_date',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'cost_basis' => 'decimal:4',
        'expiry_date' => 'date',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'quantity' => 0,
        'reserved_quantity' => 0,
    ];

    public function getTable(): string
    {
        return config('inventory.tables.stock', 'stock');
    }

    /**
     * @return Attribute<float, never>
     */
    protected function availableQuantity(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->quantity - (float) $this->reserved_quantity,
        );
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function item(): BelongsTo
    {
        $itemModel = config('inventory.models.item');

        return $this->belongsTo($itemModel, 'item_id');
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
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(
            config('inventory.models.stock_movement', StockMovement::class),
            'item_id',
            'item_id'
        )->where('to_location_id', $this->location_id)
            ->orWhere('from_location_id', $this->location_id);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(
            config('inventory.models.reservation', Reservation::class),
            'stock_id'
        );
    }

    protected static function newFactory(): StockFactory
    {
        return StockFactory::new();
    }
}
