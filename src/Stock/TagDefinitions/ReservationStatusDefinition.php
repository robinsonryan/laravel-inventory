<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\TagDefinitions;

use RobinsonRyan\Taxon\TagDefinition;

final class ReservationStatusDefinition extends TagDefinition
{
    public static string $slug = 'reservation-status';

    public static string $name = 'Reservation Status';

    public static bool $singleSelect = true;

    public static bool $global = true;

    /**
     * @var list<string>
     */
    public const array DEFAULT_VALUES = [
        'pending',
        'reserved',
        'partial',
        'fulfilled',
        'cancelled',
        'expired',
    ];
}
