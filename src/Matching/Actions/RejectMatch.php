<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartMatch;

/**
 * Rejects a match and deletes nothing.
 *
 * The vetoed row stays as the record of the rejection, and it is what stops
 * ProposeMatch reviving the pairing on a re-post. A vendor item left with nothing
 * open or confirmed gets a new proposal with no part, which puts it back in the
 * review queue.
 */
final class RejectMatch
{
    public function execute(PartMatch $match, Model $actor, ?string $note = null): PartMatch
    {
        return DB::transaction(function () use ($match, $actor, $note): PartMatch {
            PartMatch::lockVendorItem($match->vendor_item_id);
            $match->refresh();

            if ($match->status === MatchStatus::Vetoed) {
                return $match;
            }

            $match->markVetoed($actor, $note);
            $match->save();

            if (! $this->vendorItemIsSpokenFor($match)) {
                PartMatch::query()->create([
                    'tenant_id' => $match->tenant_id,
                    'vendor_item_id' => $match->vendor_item_id,
                    'item_id' => null,
                    'status' => MatchStatus::Proposed,
                    'confidence' => 0,
                    'method' => MatchMethod::Person,
                ]);
            }

            return $match;
        });
    }

    private function vendorItemIsSpokenFor(PartMatch $match): bool
    {
        return PartMatch::query()
            ->where('vendor_item_id', $match->vendor_item_id)
            ->whereIn('status', [MatchStatus::Proposed->value, MatchStatus::Confirmed->value])
            ->exists();
    }
}
