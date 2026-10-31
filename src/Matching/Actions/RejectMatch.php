<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartMatch;

/**
 * Rejects a match and deletes nothing.
 *
 * The vetoed row stays as the record of the rejection, and it is what stops
 * ProposeMatch reviving the pairing on a re-post. A vendor item left with nothing
 * open or confirmed goes back in the review queue through that row, which
 * PartMatch::scopeNeedsReview() works out; nothing is written for it here.
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

            return $match;
        });
    }
}
