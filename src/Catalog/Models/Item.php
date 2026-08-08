<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RobinsonRyan\Inventory\Catalog\TagDefinitions\ItemCategoryDefinition;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Catalog\ItemFactory;
use RobinsonRyan\Taxon\HasTags;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $sku
 * @property string $name
 * @property string|null $description
 * @property string $unit_of_measure
 * @property string|null $cost
 * @property string|null $price
 * @property int|null $min_stock_level
 * @property int|null $max_stock_level
 * @property int|null $lead_time_days
 * @property string|null $weight
 * @property array<string, mixed>|null $dimensions
 * @property bool $is_serialized
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $category
 * @property-read Kit|null $asKit
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Kit> $kits
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ItemSubstitution> $substitutes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ItemSubstitution> $substitutedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TransformationInput> $transformationInputs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TransformationOutput> $transformationOutputs
 */
final class Item extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use HasTags;
    use HasUuid7;

    /** @var array<string, class-string> */
    protected array $tagAttributes = [
        'category' => ItemCategoryDefinition::class,
    ];

    protected $fillable = [
        'tenant_id',
        'sku',
        'name',
        'description',
        'unit_of_measure',
        'cost',
        'price',
        'min_stock_level',
        'max_stock_level',
        'lead_time_days',
        'weight',
        'dimensions',
        'is_serialized',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'cost' => 'decimal:4',
        'price' => 'decimal:4',
        'min_stock_level' => 'integer',
        'max_stock_level' => 'integer',
        'lead_time_days' => 'integer',
        'weight' => 'decimal:4',
        'dimensions' => 'array',
        'is_serialized' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'unit_of_measure' => 'each',
        'is_serialized' => false,
        'is_active' => true,
    ];

    public function getTable(): string
    {
        return config('inventory.tables.items', 'items');
    }

    /**
     * Kits this item belongs to.
     *
     * @return BelongsToMany<Kit, $this>
     */
    public function kits(): BelongsToMany
    {
        return $this->belongsToMany(
            Kit::class,
            config('inventory.tables.kit_items', 'kit_items'),
            'item_id',
            'kit_id',
        )->withPivot(['quantity', 'is_optional', 'sort_order'])
            ->withTimestamps();
    }

    /**
     * If this item IS a kit.
     *
     * @return HasOne<Kit, $this>
     */
    public function asKit(): HasOne
    {
        return $this->hasOne(Kit::class, 'item_id');
    }

    /**
     * Items that can substitute for this item.
     *
     * @return HasMany<ItemSubstitution, $this>
     */
    public function substitutes(): HasMany
    {
        return $this->hasMany(ItemSubstitution::class, 'item_id');
    }

    /**
     * Items this item can substitute for.
     *
     * @return HasMany<ItemSubstitution, $this>
     */
    public function substitutedBy(): HasMany
    {
        return $this->hasMany(ItemSubstitution::class, 'substitute_item_id');
    }

    /**
     * Transformations where this item is an input.
     *
     * @return HasMany<TransformationInput, $this>
     */
    public function transformationInputs(): HasMany
    {
        return $this->hasMany(TransformationInput::class, 'item_id');
    }

    /**
     * Transformations where this item is an output.
     *
     * @return HasMany<TransformationOutput, $this>
     */
    public function transformationOutputs(): HasMany
    {
        return $this->hasMany(TransformationOutput::class, 'item_id');
    }

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }
}
