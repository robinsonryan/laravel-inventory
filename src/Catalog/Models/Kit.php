<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Catalog\KitFactory;
use RobinsonRyan\Taxon\HasTags;

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
 * @property string|null $option_group
 * @property bool $option_group_exclusive
 * @property string|null $option_slot
 * @property bool $is_standard
 * @property array<string, mixed>|null $sets_vehicle_attribute
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Item $item
 * @property-read Kit|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Kit> $children
 * @property-read \Illuminate\Database\Eloquent\Collection<int, KitItem> $kitItems
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Item> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \RobinsonRyan\Taxon\Models\Tag> $tags
 */
final class Kit extends Model
{
    /** @use HasFactory<KitFactory> */
    use HasFactory;

    use HasTags;
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
        'option_group',
        'option_group_exclusive',
        'option_slot',
        'is_standard',
        'sets_vehicle_attribute',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'retail_price' => 'decimal:2',
        'dealer_price' => 'decimal:2',
        'is_customer_selectable' => 'boolean',
        'display_sort_order' => 'integer',
        'option_group_exclusive' => 'boolean',
        'is_standard' => 'boolean',
        'sets_vehicle_attribute' => 'array',
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
     * Check if this kit is a standard (no-upgrade) option.
     */
    public function isStandard(): bool
    {
        return $this->is_standard;
    }

    /**
     * Scope to find kits in a specific option group and slot.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeInGroupAndSlot(
        \Illuminate\Database\Eloquent\Builder $query,
        string $optionGroup,
        ?string $optionSlot = null,
    ): \Illuminate\Database\Eloquent\Builder {
        $query->where('option_group', $optionGroup);

        if ($optionSlot !== null) {
            $query->where('option_slot', $optionSlot);
        }

        return $query;
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
            'item_id',
        )->withPivot(['quantity', 'is_optional', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * Read the kit_items pivot quantity for a kit member.
     *
     * The pivot is only present when the item was loaded through items(); guard
     * rather than assume, so a directly-constructed Item cannot fatal here.
     */
    private function pivotQuantity(Item $item): float
    {
        $pivot = $item->getAttribute('pivot');

        return $pivot instanceof Pivot ? (float) $pivot->getAttribute('quantity') : 0.0;
    }

    /**
     * Calculate the total cost of all items in the kit.
     */
    public function calculateTotalCost(): float
    {
        return $this->items->sum(function (Item $item): float {
            $quantity = $this->pivotQuantity($item);
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
            $quantity = $this->pivotQuantity($item);
            $price = (float) ($item->price ?? 0);

            return $quantity * $price;
        });
    }

    /**
     * Get the start year from tags (defaults to 1996 if not set).
     */
    public function getYearStart(): int
    {
        $tag = $this->getTagValueIn('year-start');

        return $tag !== null ? (int) $tag : 1996;
    }

    /**
     * Get the end year from tags (null = current and future).
     */
    public function getYearEnd(): ?int
    {
        $tag = $this->getTagValueIn('year-end');

        return $tag !== null ? (int) $tag : null;
    }

    /**
     * Check if kit applies to the given year.
     */
    public function fitsYear(int $year): bool
    {
        $start = $this->getYearStart();
        $end = $this->getYearEnd();

        if ($year < $start) {
            return false;
        }

        if ($end !== null && $year > $end) {
            return false;
        }

        return true;
    }

    protected static function newFactory(): KitFactory
    {
        return KitFactory::new();
    }
}
