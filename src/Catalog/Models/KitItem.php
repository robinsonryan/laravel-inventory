<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Inventory\Concerns\HasUuid7;

/**
 * @property string $id
 * @property string $kit_id
 * @property string $item_id
 * @property string $quantity
 * @property bool $is_optional
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Kit $kit
 * @property-read Item $item
 */
final class KitItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'kit_id',
        'item_id',
        'quantity',
        'is_optional',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'is_optional' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'quantity' => 1,
        'is_optional' => false,
        'sort_order' => 0,
    ];

    public function getTable(): string
    {
        return config('inventory.tables.kit_items', 'kit_items');
    }

    /**
     * The kit this item belongs to.
     *
     * @return BelongsTo<Kit, $this>
     */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class, 'kit_id');
    }

    /**
     * The item in this kit.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
