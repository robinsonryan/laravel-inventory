<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\TagDefinitions;

use RobinsonRyan\Taxon\TagDefinition;

final class MovementTypeDefinition extends TagDefinition
{
    public static string $slug = 'movement-type';

    public static string $name = 'Movement Type';

    public static bool $singleSelect = true;

    public static bool $global = true;

    /**
     * @var list<string>
     */
    public const array DEFAULT_VALUES = [
        'purchase',
        'sale',
        'transfer',
        'adjustment',
        'consumption',
        'production',
        'return',
        'scrap',
    ];
}
