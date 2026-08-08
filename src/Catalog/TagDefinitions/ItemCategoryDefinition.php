<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\TagDefinitions;

use RobinsonRyan\Taxon\TagDefinition;

final class ItemCategoryDefinition extends TagDefinition
{
    public static string $slug = 'item-category';

    public static string $name = 'Item Category';

    public static bool $singleSelect = true;

    public static bool $global = true;

    /**
     * @var list<string>
     */
    public const DEFAULT_VALUES = [
        'part',
        'supply',
        'fluid',
        'labor',
        'service',
        'assembly',
    ];
}
