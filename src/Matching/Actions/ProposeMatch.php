<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartMatch;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

/**
 * Records what the proposer thinks a vendor item is.
 *
 * Safe to repeat for every re-post, which is the point: a decision already made
 * about this pairing wins, so the row handed back may be confirmed or vetoed
 * rather than proposed, and only a `proposed` row is worth trying to confirm. A
 * vendor item confirmed to a different part keeps that match, and this proposal
 * joins the queue beside it.
 *
 * A veto is a decision only when a person made it. The rule vetoes a proposal
 * with no part because it confirmed a part instead, which says nothing once that
 * part is rejected, so the suggestion can be made again.
 *
 * Each pairing keeps its own row, so a suggestion's date and method stay its own.
 * PartMatch::scopeNeedsReview() is what stops a vendor item queuing twice.
 */
final class ProposeMatch
{
    /**
     * @param  Item|null  $item  null proposes a new part
     * @param  array{name?: string, description?: string}  $newPart
     */
    public function execute(
        VendorItem $vendorItem,
        ?Item $item,
        float $confidence,
        MatchMethod $method,
        array $newPart = [],
    ): PartMatch {
        return DB::transaction(function () use ($vendorItem, $item, $confidence, $method, $newPart): PartMatch {
            PartMatch::lockVendorItem($vendorItem->id);

            /** @var Collection<int, PartMatch> $pairing */
            $pairing = PartMatch::query()
                ->where('vendor_item_id', $vendorItem->id)
                ->where('item_id', $item?->id)
                ->get();

            $decided = $this->decided($pairing);

            if ($decided instanceof PartMatch) {
                return $decided;
            }

            $match = $pairing->first(fn (PartMatch $match): bool => $match->status === MatchStatus::Proposed)
                ?? new PartMatch(['tenant_id' => $vendorItem->tenant_id, 'vendor_item_id' => $vendorItem->id]);

            $match->fill([
                'item_id' => $item?->id,
                'status' => MatchStatus::Proposed,
                'confidence' => $confidence,
                'method' => $method,
                'new_part' => $newPart === [] ? null : $newPart,
            ])->save();

            return $match;
        });
    }

    /**
     * @param  Collection<int, PartMatch>  $pairing
     */
    private function decided(Collection $pairing): ?PartMatch
    {
        return $pairing->first(fn (PartMatch $match): bool => $match->status === MatchStatus::Confirmed)
            ?? $pairing->first(
                fn (PartMatch $match): bool => $match->status === MatchStatus::Vetoed && ! $match->isVetoedByRule(),
            );
    }
}
