<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Enums;

/**
 * How a proposal was arrived at. Who confirmed it is recorded separately.
 */
enum MatchMethod: string
{
    case ExactRepeat = 'exact_repeat';
    case Gateway = 'gateway';
    case Person = 'person';
}
