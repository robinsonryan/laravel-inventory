<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Enums;

enum ClassificationAxis: string
{
    case Fitment = 'fitment';
    case Category = 'category';
}
