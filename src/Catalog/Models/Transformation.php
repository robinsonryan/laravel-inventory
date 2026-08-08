<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Catalog\TransformationFactory;

/**
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TransformationInput> $inputs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TransformationOutput> $outputs
 */
final class Transformation extends Model
{
    /** @use HasFactory<TransformationFactory> */
    use HasFactory;

    use HasUuid7;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function getTable(): string
    {
        return config('inventory.tables.transformations', 'transformations');
    }

    /**
     * Inputs consumed by this transformation.
     *
     * @return HasMany<TransformationInput, $this>
     */
    public function inputs(): HasMany
    {
        return $this->hasMany(TransformationInput::class, 'transformation_id');
    }

    /**
     * Outputs produced by this transformation.
     *
     * @return HasMany<TransformationOutput, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(TransformationOutput::class, 'transformation_id');
    }

    protected static function newFactory(): TransformationFactory
    {
        return TransformationFactory::new();
    }
}
