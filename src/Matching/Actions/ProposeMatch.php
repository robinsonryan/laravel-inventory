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

            $match = $pairing->first()
                ?? ($item instanceof Item ? $this->openEmptyProposal($vendorItem) : null)
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
            ?? $pairing->first(fn (PartMatch $match): bool => $match->status === MatchStatus::Vetoed);
    }

    /**
     * A proposal with no part, which a real proposal answers. Filling it in
     * keeps a vendor item from queuing twice.
     */
    private function openEmptyProposal(VendorItem $vendorItem): ?PartMatch
    {
        return PartMatch::query()
            ->where('vendor_item_id', $vendorItem->id)
            ->where('status', MatchStatus::Proposed->value)
            ->whereNull('item_id')
            ->first();
    }
}
