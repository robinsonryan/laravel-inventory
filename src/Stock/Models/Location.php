<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Stock\Database\Factories\LocationFactory;
use RobinsonRyan\Inventory\Stock\TagDefinitions\LocationTypeDefinition;
use RobinsonRyan\Taxon\HasTags;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_pickable
 * @property bool $is_receivable
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|null $type
 * @property-read Location|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Location> $children
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Stock> $stocks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StockMovement> $incomingMovements
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StockMovement> $outgoingMovements
 */
final class Location extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasTags;
    use HasUuid7;

    /** @var array<string, class-string> */
    protected array $tagAttributes = [
        'type' => LocationTypeDefinition::class,
    ];

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'code',
        'name',
        'description',
        'is_active',
        'is_pickable',
        'is_receivable',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_pickable' => 'boolean',
        'is_receivable' => 'boolean',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_pickable' => true,
        'is_receivable' => true,
    ];

    public function getTable(): string
    {
        return config('inventory.tables.locations', 'locations');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Stock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(
            config('inventory.models.stock', Stock::class),
            'location_id'
        );
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function incomingMovements(): HasMany
    {
        return $this->hasMany(
            config('inventory.models.stock_movement', StockMovement::class),
            'to_location_id'
        );
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function outgoingMovements(): HasMany
    {
        return $this->hasMany(
            config('inventory.models.stock_movement', StockMovement::class),
            'from_location_id'
        );
    }

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }
}
