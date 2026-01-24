<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Inventory\Concerns\HasUuid7;

/**
 * @property string $id
 * @property string $transformation_id
 * @property string $item_id
 * @property string $quantity
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Transformation $transformation
 * @property-read Item $item
 */
final class TransformationOutput extends Model
{
    use HasUuid7;

    protected $fillable = [
        'transformation_id',
        'item_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.transformation_outputs', 'transformation_outputs');
    }

    /**
     * The transformation this output belongs to.
     *
     * @return BelongsTo<Transformation, $this>
     */
    public function transformation(): BelongsTo
    {
        return $this->belongsTo(Transformation::class, 'transformation_id');
    }

    /**
     * The item produced as output.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
