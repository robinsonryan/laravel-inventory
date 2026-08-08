<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Purchasing\PurchaseOrderFactory;
use RobinsonRyan\Inventory\Purchasing\TagDefinitions\PurchaseOrderStatusDefinition;
use RobinsonRyan\Inventory\Support\ModelResolver;
use RobinsonRyan\Taxon\HasTags;
use RobinsonRyan\Vendor\Concerns\BelongsToVendor;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $vendor_id
 * @property string $po_number
 * @property \Illuminate\Support\Carbon $order_date
 * @property \Illuminate\Support\Carbon|null $expected_date
 * @property string $subtotal
 * @property string $tax
 * @property string $shipping
 * @property string $total
 * @property string|null $notes
 * @property array<string, mixed>|null $shipping_address
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $status
 * @property-read \RobinsonRyan\Vendor\Models\Vendor $vendor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PurchaseOrderLine> $lines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Receipt> $receipts
 */
final class PurchaseOrder extends Model
{
    use BelongsToTenant;
    use BelongsToVendor;

    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    use HasTags;
    use HasUuid7;

    /** @var array<string, class-string> */
    protected array $tagAttributes = [
        'status' => PurchaseOrderStatusDefinition::class,
    ];

    protected $fillable = [
        'tenant_id',
        'vendor_id',
        'po_number',
        'order_date',
        'expected_date',
        'subtotal',
        'tax',
        'shipping',
        'total',
        'notes',
        'shipping_address',
        'metadata',
        'submitted_at',
        'cancelled_at',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'shipping' => 'decimal:2',
        'total' => 'decimal:2',
        'shipping_address' => 'array',
        'metadata' => 'array',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $attributes = [
        'subtotal' => '0.00',
        'tax' => '0.00',
        'shipping' => '0.00',
        'total' => '0.00',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.purchase_orders', 'purchase_orders');
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(
            ModelResolver::resolve('purchase_order_line', PurchaseOrderLine::class),
            'purchase_order_id',
        )->orderBy('sort_order');
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(
            ModelResolver::resolve('receipt', Receipt::class),
            'purchase_order_id',
        );
    }

    protected static function newFactory(): PurchaseOrderFactory
    {
        return PurchaseOrderFactory::new();
    }
}
