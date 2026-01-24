<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasImmutableAuditTrail;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Stock\TagDefinitions\MovementTypeDefinition;
use RobinsonRyan\Taxon\HasTags;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $item_id
 * @property string|null $from_location_id
 * @property string|null $to_location_id
 * @property float $quantity
 * @property float|null $cost
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $lot_number
 * @property string|null $serial_number
 * @property string|null $notes
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read string|null $type
 * @property-read Model $item
 * @property-read Location|null $fromLocation
 * @property-read Location|null $toLocation
 */
final class StockMovement extends Model
{
    use BelongsToTenant;
    use HasImmutableAuditTrail;
    use HasTags;
    use HasUuid7;

    public const UPDATED_AT = null;

    /** @var array<string, class-string> */
    protected array $tagAttributes = [
        'type' => MovementTypeDefinition::class,
    ];

    protected $fillable = [
        'tenant_id',
        'item_id',
        'from_location_id',
        'to_location_id',
        'quantity',
        'cost',
        'reference_type',
        'reference_id',
        'actor_type',
        'actor_id',
        'lot_number',
        'serial_number',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'cost' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.stock_movements', 'stock_movements');
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
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.location', Location::class),
            'from_location_id'
        );
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.location', Location::class),
            'to_location_id'
        );
    }
}
