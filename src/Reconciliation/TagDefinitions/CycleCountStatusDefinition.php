<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\TagDefinitions;

use RobinsonRyan\Taxon\TagDefinition;

final class CycleCountStatusDefinition extends TagDefinition
{
    public static string $slug = 'cycle-count-status';

    public static string $name = 'Cycle Count Status';

    public static bool $singleSelect = true;

    public static bool $global = true;

    /**
     * @var list<string>
     */
    public const array DEFAULT_VALUES = [
        'draft',
        'in_progress',
        'pending',
        'approved',
        'cancelled',
    ];

    /**
     * @var list<string>
     */
    public const array OPEN = ['draft', 'in_progress', 'pending'];

    /**
     * @var list<string>
     */
    public const array TERMINAL = ['approved', 'cancelled'];
}
