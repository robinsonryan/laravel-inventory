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
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Item $item
 * @property-read \Illuminate\Database\Eloquent\Collection<int, KitItem> $kitItems
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Item> $items
 */
final class Kit extends Model
{
    use HasFactory;
    use HasUuid7;

    protected $fillable = [
        'item_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
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
