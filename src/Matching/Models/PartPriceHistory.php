<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;
use RuntimeException;

/**
 * What was paid, line by line: the `part_price_history` view over stock lines.
 *
 * `item_id` comes from the confirmed match alone, so rejecting a match takes its
 * lines off the part while they stay on the vendor item. Credit memos are left
 * out. Price history is not a table; to change it, change the lines or the match.
 *
 * @property string $purchase_line_id
 * @property string $vendor_document_id
 * @property string $vendor_id
 * @property string|null $vendor_item_id
 * @property string|null $item_id
 * @property \Illuminate\Support\Carbon|null $document_date
 * @property string|null $quantity
 * @property string|null $unit_of_measure
 * @property string|null $unit_price
 * @property string|null $extended
 * @property string|null $document_number
 */
final class PartPriceHistory extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'purchase_line_id';

    protected $keyType = 'string';

    protected $casts = [
        'document_date' => 'date',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'extended' => 'decimal:2',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.part_price_history', 'part_price_history');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForItem(Builder $query, Item|string $item): Builder
    {
        return $query->where('item_id', $item instanceof Item ? $item->getKey() : $item);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForVendorItem(Builder $query, VendorItem|string $vendorItem): Builder
    {
        return $query->where('vendor_item_id', $vendorItem instanceof VendorItem ? $vendorItem->getKey() : $vendorItem);
    }

    /**
     * Oldest first. Lines dated the same day keep a stable order.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('document_date')->orderBy('purchase_line_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw new RuntimeException('Part price history is a read-only view.');
        };

        self::saving($refuse);
        self::deleting($refuse);
    }
}
