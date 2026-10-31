<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Exceptions\CannotConfirmMatchException;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartMatch;

/**
 * Confirms a match, by rule when there is no actor.
 *
 * A person outranks the rule, so only a person can change a confirmed vendor
 * item to another part or undo a rejection. The old match is vetoed first and in
 * the same transaction, because the unique index would refuse two at once.
 */
final class ConfirmMatch
{
    /**
     * @throws CannotConfirmMatchException
     */
    public function execute(PartMatch $match, ?Model $actor = null): PartMatch
    {
        return DB::transaction(function () use ($match, $actor): PartMatch {
            PartMatch::lockVendorItem($match->vendor_item_id);
            $match->refresh();

            if ($match->status === MatchStatus::Confirmed) {
                if ($actor instanceof Model) {
                    $match->markConfirmed($actor);
                    $match->save();
                }

                return $match;
            }

            $this->assertConfirmable($match, $actor);
            $this->answerOtherMatches($match, $actor);

            $match->markConfirmed($actor);
            $match->save();

            return $match;
        });
    }

    private function assertConfirmable(PartMatch $match, ?Model $actor): void
    {
        if ($match->item_id === null) {
            throw CannotConfirmMatchException::noPart($match->id);
        }

        if (Item::query()->whereKey($match->item_id)->value('is_active') !== true) {
            throw CannotConfirmMatchException::inactivePart($match->item_id);
        }

        if ($actor instanceof Model) {
            return;
        }

        if ($match->status === MatchStatus::Vetoed) {
            throw CannotConfirmMatchException::rejected($match->id);
        }

        if ($this->otherMatches($match)->where('status', MatchStatus::Confirmed->value)->exists()) {
            throw CannotConfirmMatchException::alreadyConfirmed($match->vendor_item_id);
        }
    }

    /**
     * Veto what this confirmation answers. A person choosing a part has passed
     * over every other part still open; the rule has not, so it answers only a
     * proposal with no part and leaves its rivals for a person to look at.
     *
     * A proposal with no part is answered by rule whoever confirmed. It waits
     * behind a part in the queue, so confirming that part rejects nothing a
     * person saw, and only a rejection stops it being suggested again.
     */
    private function answerOtherMatches(PartMatch $match, ?Model $actor): void
    {
        $answered = $this->otherMatches($match)->whereIn('status', [
            MatchStatus::Confirmed->value,
            MatchStatus::Proposed->value,
        ]);

        if (! $actor instanceof Model) {
            $answered->whereNull('item_id');
        }

        foreach ($answered->get() as $other) {
            $other->markVetoed($other->item_id === null ? null : $actor);
            $other->save();
        }
    }

    /**
     * @return Builder<PartMatch>
     */
    private function otherMatches(PartMatch $match): Builder
    {
        return PartMatch::query()
            ->where('vendor_item_id', $match->vendor_item_id)
            ->whereKeyNot($match->id);
    }
}
