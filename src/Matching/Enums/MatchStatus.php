<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Enums;

/**
 * Where a match or a classification stands. A plain column rather than a taxon
 * tag: the database has to enforce uniqueness on it.
 */
enum MatchStatus: string
{
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
    case Vetoed = 'vetoed';
}
