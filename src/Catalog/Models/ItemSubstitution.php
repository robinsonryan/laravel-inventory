<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Inventory\Concerns\HasUuid7;

/**
 * @property string $id
 * @property string $item_id
 * @property string $substitute_item_id
 * @property int $priority
 * @property string $ratio
 * @property bool $is_bidirectional
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Item $item
 * @property-read Item $substituteItem
 */
final class ItemSubstitution extends Model
{
    use HasUuid7;

    protected $fillable = [
        'item_id',
        'substitute_item_id',
        'priority',
        'ratio',
        'is_bidirectional',
        'notes',
    ];

    protected $casts = [
        'priority' => 'integer',
        'ratio' => 'decimal:4',
        'is_bidirectional' => 'boolean',
    ];

    protected $attributes = [
        'priority' => 0,
        'ratio' => 1.0000,
        'is_bidirectional' => false,
    ];

    public function getTable(): string
    {
        return config('inventory.tables.item_substitutions', 'item_substitutions');
    }

    /**
     * The original item.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * The substitute item.
     *
     * @return BelongsTo<Item, $this>
     */
    public function substituteItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'substitute_item_id');
    }
}
