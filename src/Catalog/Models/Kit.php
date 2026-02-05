<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Catalog\KitFactory;

/**
 * @property string $id
 * @property string $item_id
 * @property string|null $parent_kit_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property string|null $retail_price
 * @property string|null $dealer_price
 * @property bool $is_customer_selectable
 * @property string|null $display_description
 * @property int $display_sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Item $item
 * @property-read Kit|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Kit> $children
 * @property-read \Illuminate\Database\Eloquent\Collection<int, KitItem> $kitItems
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Item> $items
 */
final class Kit extends Model
{
    use HasFactory;
    use HasUuid7;

    protected $fillable = [
        'item_id',
        'parent_kit_id',
        'name',
        'description',
        'is_active',
        'retail_price',
        'dealer_price',
        'is_customer_selectable',
        'display_description',
        'display_sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'retail_price' => 'decimal:2',
        'dealer_price' => 'decimal:2',
        'is_customer_selectable' => 'boolean',
        'display_sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_customer_selectable' => false,
        'display_sort_order' => 0,
    ];

    public function getTable(): string
    {
        return config('inventory.tables.kits', 'kits');
    }

    /**
     * The item this kit represents.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * Parent kit (for options that belong to a conversion).
     *
     * @return BelongsTo<Kit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_kit_id');
    }

    /**
     * Child kits (options that belong to this conversion).
     *
     * @return HasMany<Kit, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_kit_id')->orderBy('display_sort_order');
    }

    /**
     * Check if this kit is a conversion (top-level, no parent).
     */
    public function isConversion(): bool
    {
        return $this->parent_kit_id === null;
    }

    /**
     * Check if this kit is an option (has a parent conversion).
     */
    public function isOption(): bool
    {
        return $this->parent_kit_id !== null;
    }

    /**
     * Get the effective price based on pricing type.
     *
     * @param  string  $pricingType  Either 'contract' for dealer pricing or 'retail' for retail pricing
     */
    public function getEffectivePrice(string $pricingType = 'retail'): ?string
    {
        if ($pricingType === 'contract') {
            return $this->dealer_price ?? $this->retail_price;
        }

        return $this->retail_price ?? $this->dealer_price;
    }

    /**
     * Kit items (pivot records).
     *
     * @return HasMany<KitItem, $this>
     */
    public function kitItems(): HasMany
    {
        return $this->hasMany(KitItem::class, 'kit_id')->orderBy('sort_order');
    }

    /**
     * Items in this kit.
     *
     * @return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(
            Item::class,
            config('inventory.tables.kit_items', 'kit_items'),
            'kit_id',
            'item_id'
        )->withPivot(['quantity', 'is_optional', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * Calculate the total cost of all items in the kit.
     */
    public function calculateTotalCost(): float
    {
        return $this->items->sum(function (Item $item): float {
            $quantity = (float) $item->pivot->quantity;
            $cost = (float) ($item->cost ?? 0);

            return $quantity * $cost;
        });
    }

    /**
     * Calculate the total price of all items in the kit.
     */
    public function calculateTotalPrice(): float
    {
        return $this->items->sum(function (Item $item): float {
            $quantity = (float) $item->pivot->quantity;
            $price = (float) ($item->price ?? 0);

            return $quantity * $price;
        });
    }

    protected static function newFactory(): KitFactory
    {
        return KitFactory::new();
    }
}
