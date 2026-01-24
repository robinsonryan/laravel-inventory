<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $purchase_order_id
 * @property string $receipt_number
 * @property \Illuminate\Support\Carbon $received_at
 * @property string|null $received_by
 * @property string|null $notes
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ReceiptLine> $lines
 */
final class Receipt extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid7;

    protected $fillable = [
        'tenant_id',
        'purchase_order_id',
        'receipt_number',
        'received_at',
        'received_by',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.receipts', 'receipts');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.purchase_order', PurchaseOrder::class),
            'purchase_order_id'
        );
    }

    /**
     * @return HasMany<ReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(
            ReceiptLine::class,
            'receipt_id'
        );
    }
}
