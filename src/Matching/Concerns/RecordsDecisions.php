<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;

/**
 * The state a match and a classification share: proposed, confirmed or vetoed,
 * and who took it there.
 *
 * Reinstating a rejected row overwrites `status` and leaves `vetoed_at` where it
 * was, because rejection deletes nothing. So `status` is what the row *is* now,
 * and the veto columns are only ever what happened to it.
 *
 * @mixin Model
 */
trait RecordsDecisions
{
    /**
     * What `confirmed_by_type` and `vetoed_by_type` hold when no person acted.
     */
    public const BY_RULE = 'rule';

    public function markConfirmed(?Model $actor): void
    {
        $this->setAttribute('status', MatchStatus::Confirmed);
        $this->setAttribute('confirmed_by_type', $actor?->getMorphClass() ?? self::BY_RULE);
        $this->setAttribute('confirmed_by_id', $actor?->getKey());
        $this->setAttribute('confirmed_at', Date::now());
    }

    public function markVetoed(?Model $actor, ?string $note = null): void
    {
        $this->setAttribute('status', MatchStatus::Vetoed);
        $this->setAttribute('vetoed_by_type', $actor?->getMorphClass() ?? self::BY_RULE);
        $this->setAttribute('vetoed_by_id', $actor?->getKey());
        $this->setAttribute('vetoed_at', Date::now());

        if ($note !== null) {
            $this->setAttribute('note', $note);
        }
    }

    public function isConfirmedByRule(): bool
    {
        return $this->getAttribute('confirmed_by_type') === self::BY_RULE;
    }

    public function isVetoedByRule(): bool
    {
        return $this->getAttribute('vetoed_by_type') === self::BY_RULE;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', MatchStatus::Confirmed->value);
    }
}
