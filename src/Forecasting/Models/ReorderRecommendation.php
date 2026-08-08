<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Forecasting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Forecasting\ReorderRecommendationFactory;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Support\ModelResolver;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $item_id
 * @property string|null $vendor_id
 * @property float $current_quantity
 * @property float $reserved_quantity
 * @property float $available_quantity
 * @property int|null $min_stock_level
 * @property int $recommended_quantity
 * @property \Illuminate\Support\Carbon|null $estimated_stockout_date
 * @property float|null $average_daily_usage
 * @property int|null $lead_time_days
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $generated_at
 * @property \Illuminate\Support\Carbon|null $actioned_at
 * @property \Illuminate\Support\Carbon|null $dismissed_at
 * @property string|null $purchase_order_id
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Model $item
 * @property-read Model|null $vendor
 * @property-read Model|null $purchaseOrder
 */
final class ReorderRecommendation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReorderRecommendationFactory> */
    use HasFactory;

    use HasUuid7;

    protected $fillable = [
        'tenant_id',
        'item_id',
        'vendor_id',
        'current_quantity',
        'reserved_quantity',
        'available_quantity',
        'min_stock_level',
        'recommended_quantity',
        'estimated_stockout_date',
        'average_daily_usage',
        'lead_time_days',
        'notes',
        'generated_at',
        'actioned_at',
        'dismissed_at',
        'purchase_order_id',
        'metadata',
    ];

    protected $casts = [
        'current_quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'available_quantity' => 'decimal:4',
        'min_stock_level' => 'integer',
        'recommended_quantity' => 'integer',
        'estimated_stockout_date' => 'date',
        'average_daily_usage' => 'decimal:4',
        'lead_time_days' => 'integer',
        'generated_at' => 'datetime',
        'actioned_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.reorder_recommendations', 'reorder_recommendations');
    }

    /**
     * Check if this recommendation is pending (not actioned or dismissed).
     */
    public function isPending(): bool
    {
        return $this->actioned_at === null && $this->dismissed_at === null;
    }

    /**
     * Check if this recommendation has been actioned (PO created).
     */
    public function isActioned(): bool
    {
        return $this->actioned_at !== null;
    }

    /**
     * Check if this recommendation has been dismissed.
     */
    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function item(): BelongsTo
    {
        $itemModel = ModelResolver::resolveAny('item', Item::class);

        return $this->belongsTo($itemModel, 'item_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function vendor(): BelongsTo
    {
        /** @var class-string<Model> $vendorModel */
        $vendorModel = config('vendor.models.vendor', \RobinsonRyan\Vendor\Models\Vendor::class);

        return $this->belongsTo($vendorModel, 'vendor_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        $purchaseOrderModel = ModelResolver::resolveAny('purchase_order', PurchaseOrder::class);

        return $this->belongsTo($purchaseOrderModel, 'purchase_order_id');
    }

    protected static function newFactory(): ReorderRecommendationFactory
    {
        return ReorderRecommendationFactory::new();
    }
}
