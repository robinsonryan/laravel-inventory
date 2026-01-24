<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Stock\Database\Factories\ReservationFactory;
use RobinsonRyan\Inventory\Stock\TagDefinitions\ReservationStatusDefinition;
use RobinsonRyan\Taxon\HasTags;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $item_id
 * @property string|null $stock_id
 * @property string|null $location_id
 * @property string $job_type
 * @property string $job_id
 * @property string|null $line_id
 * @property float $quantity
 * @property \Illuminate\Support\Carbon $reserved_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $fulfilled_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $notes
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|null $status
 * @property-read Model $item
 * @property-read Stock|null $stock
 * @property-read Location|null $location
 */
final class Reservation extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasTags;
    use HasUuid7;

    /** @var array<string, class-string> */
    protected array $tagAttributes = [
        'status' => ReservationStatusDefinition::class,
    ];

    protected $fillable = [
        'tenant_id',
        'item_id',
        'stock_id',
        'location_id',
        'job_type',
        'job_id',
        'line_id',
        'quantity',
        'reserved_at',
        'expires_at',
        'fulfilled_at',
        'cancelled_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.reservations', 'reservations');
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.location', Location::class),
            'location_id'
        );
    }

    protected static function newFactory(): ReservationFactory
    {
        return ReservationFactory::new();
    }
}
