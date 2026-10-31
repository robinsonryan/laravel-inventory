<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Concerns\BelongsToTenant;
use RobinsonRyan\Inventory\Concerns\HasUuid7;
use RobinsonRyan\Inventory\Database\Factories\Matching\PartMatchFactory;
use RobinsonRyan\Inventory\Matching\Concerns\RecordsDecisions;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Support\ModelResolver;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

/**
 * The link from one vendor's part number to AFWD's own part.
 *
 * A vendor item has at most one confirmed match, which the database enforces. A
 * row is never deleted: a rejected pairing stays as a vetoed row, and that row
 * is what stops the same pairing being proposed again when a document is
 * re-posted.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $vendor_item_id
 * @property string|null $item_id null for a new-part proposal
 * @property MatchStatus $status
 * @property float $confidence
 * @property MatchMethod $method
 * @property array{name?: string, description?: string}|null $new_part
 * @property string|null $confirmed_by_type
 * @property string|null $confirmed_by_id
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $vetoed_at
 * @property string|null $vetoed_by_type
 * @property string|null $vetoed_by_id
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Model|null $item
 * @property-read VendorItem $vendorItem
 */
final class PartMatch extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PartMatchFactory> */
    use HasFactory;

    use HasUuid7;
    use RecordsDecisions;

    protected $fillable = [
        'tenant_id',
        'vendor_item_id',
        'item_id',
        'status',
        'confidence',
        'method',
        'new_part',
        'note',
    ];

    protected $casts = [
        'status' => MatchStatus::class,
        'confidence' => 'float',
        'method' => MatchMethod::class,
        'new_part' => 'array',
        'confirmed_at' => 'datetime',
        'vetoed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('inventory.tables.part_matches', 'part_matches');
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
     * @return BelongsTo<VendorItem, $this>
     */
    public function vendorItem(): BelongsTo
    {
        /** @var class-string<VendorItem> $model */
        $model = config('vendor.models.vendor_item', VendorItem::class);

        return $this->belongsTo($model, 'vendor_item_id');
    }

    /**
     * The review queue: every open proposal, plus a rejected match standing in
     * for a vendor item left with nothing open or confirmed.
     *
     * A new-part suggestion waits behind an open part for its vendor item and
     * comes forward if that part is rejected. Rival parts queue one by one,
     * because a person has to see each. The stand-in is the newest rejected row,
     * so a vendor item with nothing open queues once. Its status stays `vetoed`:
     * it has no proposal to confirm, only a vendor item needing a part.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedsReview(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->where(function (Builder $query) use ($table): void {
            $query->where(function (Builder $query) use ($table): void {
                $query->where("{$table}.status", MatchStatus::Proposed->value)
                    ->where(function (Builder $query) use ($table): void {
                        $query->whereNotNull("{$table}.item_id")
                            ->orWhereNotExists(function (QueryBuilder $openPart) use ($table): void {
                                $openPart->from($table, 'open_part')
                                    ->whereColumn('open_part.vendor_item_id', "{$table}.vendor_item_id")
                                    ->where('open_part.status', MatchStatus::Proposed->value)
                                    ->whereNotNull('open_part.item_id');
                            });
                    });
            })
                ->orWhere(function (Builder $query) use ($table): void {
                    $query->where("{$table}.status", MatchStatus::Vetoed->value)
                        ->whereNotExists(function (QueryBuilder $sibling) use ($table): void {
                            $sibling->from($table, 'sibling')
                                ->whereColumn('sibling.vendor_item_id', "{$table}.vendor_item_id")
                                ->where(function (QueryBuilder $answer) use ($table): void {
                                    $answer->whereIn('sibling.status', [
                                        MatchStatus::Proposed->value,
                                        MatchStatus::Confirmed->value,
                                    ])->orWhereColumn('sibling.id', '>', "{$table}.id");
                                });
                        });
                });
        });
    }

    /**
     * Take the lock every change to a vendor item's matches queues on, so two
     * writers cannot each read "no confirmed match" and race to the unique
     * index. Call inside the transaction doing the writing.
     */
    public static function lockVendorItem(string $vendorItemId): void
    {
        VendorItem::query()->whereKey($vendorItemId)->lockForUpdate()->firstOrFail();
    }

    protected static function newFactory(): PartMatchFactory
    {
        return PartMatchFactory::new();
    }
}
