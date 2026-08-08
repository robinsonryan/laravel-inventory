<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RobinsonRyan\Inventory\Concerns\HasUuid7;

/**
 * @property string $id
 * @property string $item_id
 * @property string $transformation_id
 * @property string|null $lot_number
 * @property string|null $serial_number
 * @property string $quantity_produced
 * @property Carbon $production_date
 * @property string|null $notes
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Item $item
 * @property-read Transformation $transformation
 */
final class Assembly extends Model
{
    use HasUuid7;

    protected $fillable = [
        'item_id',
        'transformation_id',
        'lot_number',
        'serial_number',
        'quantity_produced',
        'production_date',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity_produced' => 'decimal:4',
        'production_date' => 'datetime',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.assemblies', 'assemblies');
    }

    /**
     * The item produced by this assembly.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * The transformation used to create this assembly.
     *
     * @return BelongsTo<Transformation, $this>
     */
    public function transformation(): BelongsTo
    {
        return $this->belongsTo(Transformation::class, 'transformation_id');
    }
}
