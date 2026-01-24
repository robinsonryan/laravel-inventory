<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\TagDefinitions;

use RobinsonRyan\Taxon\TagDefinition;

final class PurchaseOrderStatusDefinition extends TagDefinition
{
    public static string $slug = 'purchase-order-status';

    public static string $name = 'Purchase Order Status';

    public static bool $singleSelect = true;

    public static bool $global = true;

    /**
     * @var list<string>
     */
    public const array DEFAULT_VALUES = [
        'draft',
        'submitted',
        'partial',
        'received',
        'cancelled',
    ];

    /**
     * @var list<string>
     */
    public const array OPEN = ['draft', 'submitted', 'partial'];

    /**
     * @var list<string>
     */
    public const array TERMINAL = ['received', 'cancelled'];
}
