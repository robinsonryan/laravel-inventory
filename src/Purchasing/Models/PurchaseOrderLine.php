<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Purchasing\PurchaseOrderLineFactory;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Support\ModelResolver;

/**
 * @property string $id
 * @property string $purchase_order_id
 * @property string $item_id
 * @property string|null $description
 * @property string $quantity
 * @property string $unit_cost
 * @property string $total
 * @property string $quantity_received
 * @property string $quantity_cancelled
 * @property string|null $destination_location_id
 * @property string|null $notes
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Item $item
 * @property-read Location|null $destinationLocation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ReceiptLine> $receiptLines
 */
final class PurchaseOrderLine extends Model
{
    /** @use HasFactory<PurchaseOrderLineFactory> */
    use HasFactory;

    use HasUuid7;

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'description',
        'quantity',
        'unit_cost',
        'total',
        'quantity_received',
        'quantity_cancelled',
        'destination_location_id',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total' => 'decimal:2',
        'quantity_received' => 'decimal:4',
        'quantity_cancelled' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'quantity_received' => '0',
        'quantity_cancelled' => '0',
        'sort_order' => 0,
    ];

    public function getTable(): string
    {
        return config('inventory.tables.purchase_order_lines', 'purchase_order_lines');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(
            ModelResolver::resolve('purchase_order', PurchaseOrder::class),
            'purchase_order_id',
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
    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(
            ModelResolver::resolve('location', Location::class),
            'destination_location_id',
        );
    }

    /**
     * @return HasMany<ReceiptLine, $this>
     */
    public function receiptLines(): HasMany
    {
        return $this->hasMany(
            ReceiptLine::class,
            'purchase_order_line_id',
        );
    }

    /**
     * Get the remaining quantity to receive.
     */
    public function getQuantityPending(): float
    {
        return (float) $this->quantity - (float) $this->quantity_received - (float) $this->quantity_cancelled;
    }

    /**
     * Check if the line is fully received.
     */
    public function isFullyReceived(): bool
    {
        return $this->getQuantityPending() <= 0;
    }

    protected static function newFactory(): PurchaseOrderLineFactory
    {
        return PurchaseOrderLineFactory::new();
    }
}
