<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Reconciliation\CycleCountFactory;
use RobinsonRyan\Inventory\Reconciliation\TagDefinitions\CycleCountStatusDefinition;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Taxon\HasTags;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $count_number
 * @property string|null $location_id
 * @property \Illuminate\Support\Carbon|null $scheduled_date
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string|null $approved_by
 * @property string|null $created_by
 * @property string|null $notes
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|null $status
 * @property-read Location|null $location
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CycleCountLine> $lines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VarianceAdjustment> $adjustments
 */
final class CycleCount extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasTags;
    use HasUuid7;

    /** @var array<string, class-string> */
    protected array $tagAttributes = [
        'status' => CycleCountStatusDefinition::class,
    ];

    protected $fillable = [
        'tenant_id',
        'count_number',
        'location_id',
        'scheduled_date',
        'started_at',
        'completed_at',
        'approved_at',
        'approved_by',
        'created_by',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.cycle_counts', 'cycle_counts');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(
            config('inventory.models.location', Location::class),
            'location_id'
        );
    }

    /**
     * @return HasMany<CycleCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(
            config('inventory.models.cycle_count_line', CycleCountLine::class),
            'cycle_count_id'
        );
    }

    /**
     * @return HasMany<VarianceAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(
            config('inventory.models.variance_adjustment', VarianceAdjustment::class),
            'cycle_count_id'
        );
    }

    /**
     * Get the total variance value for this cycle count.
     */
    public function getTotalVariance(): float
    {
        return (float) $this->lines()->sum('variance');
    }

    /**
     * Get the count of lines with variances.
     */
    public function getVarianceCount(): int
    {
        return $this->lines()
            ->whereNotNull('variance')
            ->where('variance', '!=', 0)
            ->count();
    }

    /**
     * Check if all lines have been counted.
     */
    public function isFullyCounted(): bool
    {
        return $this->lines()->whereNull('counted_at')->count() === 0;
    }

    protected static function newFactory(): CycleCountFactory
    {
        return CycleCountFactory::new();
    }
}
