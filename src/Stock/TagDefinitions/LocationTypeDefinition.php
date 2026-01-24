<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\TagDefinitions;

use RobinsonRyan\Taxon\TagDefinition;

final class LocationTypeDefinition extends TagDefinition
{
    public static string $slug = 'location-type';

    public static string $name = 'Location Type';

    public static bool $singleSelect = true;

    public static bool $global = true;

    /**
     * @var list<string>
     */
    public const array DEFAULT_VALUES = [
        'warehouse',
        'bin',
        'shelf',
        'wip',
        'truck',
        'virtual',
        'quarantine',
    ];
}
