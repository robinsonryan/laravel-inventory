<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Matching\PartClassificationFactory;
use RobinsonRyan\Inventory\Matching\Concerns\RecordsDecisions;
use RobinsonRyan\Inventory\Matching\Enums\ClassificationAxis;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Support\ModelResolver;
use RobinsonRyan\Taxon\Models\Tag;

/**
 * One proposed, confirmed or rejected fitment or category for a part.
 *
 * Taxon's tag link has no state or history, so those live here. Only a confirmed
 * row puts a tag on the part, and the rows are the record of what a part's
 * fitment and category used to be.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $item_id
 * @property ClassificationAxis $axis
 * @property string|null $tag_id
 * @property string|null $proposed_value the name of a category that did not exist when proposed
 * @property MatchStatus $status
 * @property float $confidence
 * @property MatchMethod $method
 * @property string|null $confirmed_by_type
 * @property string|null $confirmed_by_id
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $vetoed_at
 * @property string|null $vetoed_by_type
 * @property string|null $vetoed_by_id
 * @property string|null $note
 * @property string|null $replaced_by_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Model $item
 * @property-read Tag|null $tag
 * @property-read PartClassification|null $replacedBy
 */
final class PartClassification extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PartClassificationFactory> */
    use HasFactory;

    use HasUuid7;
    use RecordsDecisions;

    protected $fillable = [
        'tenant_id',
        'item_id',
        'axis',
        'tag_id',
        'proposed_value',
        'status',
        'confidence',
        'method',
        'note',
    ];

    protected $casts = [
        'axis' => ClassificationAxis::class,
        'status' => MatchStatus::class,
        'confidence' => 'float',
        'method' => MatchMethod::class,
        'confirmed_at' => 'datetime',
        'vetoed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.part_classifications', 'part_classifications');
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
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        /** @var class-string<Tag> $model */
        $model = config('taxon.tag_model', Tag::class);

        return $this->belongsTo($model, 'tag_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /**
     * Whether this row already speaks for a value, however it was spelled and
     * whether or not its category has come to exist since.
     *
     * Two tags settle it, because a row keeps the name it was proposed under after
     * it resolves, and another category can come to share that name. The name is
     * for when one side has no tag.
     */
    public function isFor(?Tag $tag, string $value): bool
    {
        if ($tag instanceof Tag && $this->tag_id !== null) {
            return $this->tag_id === $tag->getKey();
        }

        return $this->proposed_value !== null && Str::slug($this->proposed_value) === Str::slug($value);
    }

    /**
     * Take the lock every change to a part's classifications queues on. Call
     * inside the transaction doing the writing.
     */
    public static function lockItem(string $itemId): void
    {
        Item::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
    }

    protected static function newFactory(): PartClassificationFactory
    {
        return PartClassificationFactory::new();
    }
}
