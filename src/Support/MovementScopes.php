<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The one definition of "stock left and did not come back".
 *
 * Depletion is a question about a movement's *shape*, not its label: a movement
 * that names a source location and no destination took stock out of the
 * business. Sales, job consumption, scrap and shrinkage all qualify; a transfer
 * between two owned locations does not, because it names both ends.
 *
 * Deciding it by shape rather than by the `movement-type` tag also keeps a tag
 * lookup out of an aggregate subquery, and — more importantly — stops the
 * low-stock report and the demand analyser from quietly disagreeing about what
 * "usage" counts.
 */
final class MovementScopes
{
    /**
     * Constrain a stock-movement query to movements that deplete stock.
     *
     * Works on both a raw query builder and an Eloquent one, and returns the
     * builder it was given so it can sit inside a chain.
     *
     * @template TBuilder of QueryBuilder|EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function depleting(QueryBuilder|EloquentBuilder $query): QueryBuilder|EloquentBuilder
    {
        $query->whereNotNull('from_location_id')
            ->whereNull('to_location_id');

        return $query;
    }
}
