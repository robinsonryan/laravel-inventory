<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;
use RobinsonRyan\Inventory\Support\ModelResolver;

/**
 * @property string $id
 * @property string $receipt_id
 * @property string $purchase_order_line_id
 * @property string $item_id
 * @property string $location_id
 * @property string $quantity
 * @property string|null $lot_number
 * @property string|null $serial_number
 * @property string|null $stock_movement_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Receipt $receipt
 * @property-read PurchaseOrderLine $purchaseOrderLine
 * @property-read Item $item
 * @property-read Location $location
 * @property-read StockMovement|null $stockMovement
 */
final class ReceiptLine extends Model
{
    use HasUuid7;

    protected $fillable = [
        'receipt_id',
        'purchase_order_line_id',
        'item_id',
        'location_id',
        'quantity',
        'lot_number',
        'serial_number',
        'stock_movement_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.receipt_lines', 'receipt_lines');
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(
            Receipt::class,
            'receipt_id',
        );
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(
            ModelResolver::resolve('purchase_order_line', PurchaseOrderLine::class),
            'purchase_order_line_id',
        );
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(
            ModelResolver::resolveAny('item', Item::class),
            'item_id',
        );
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(
            ModelResolver::resolve('location', Location::class),
            'location_id',
        );
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(
            ModelResolver::resolve('stock_movement', StockMovement::class),
            'stock_movement_id',
        );
    }
}
