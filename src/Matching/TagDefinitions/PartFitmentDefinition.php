<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\TagDefinitions;

use Illuminate\Support\Str;
use RobinsonRyan\Taxon\TagDefinition;

/**
 * Which makes a part fits. Multi-valued, so it is written with `addTagAs()` and
 * `removeTag()`: assigning `$item->fitment` goes through `setTagAs()`, which
 * clears every other make first.
 */
final class PartFitmentDefinition extends TagDefinition
{
    /**
     * @var list<string>
     */
    public const DEFAULT_VALUES = [
        'ford',
        'gm',
        'nissan',
    ];

    /**
     * Other spellings of a make, by slug. The configurator says `chevy`.
     */
    private const ALIASES = [
        'chevy' => 'gm',
        'chevrolet' => 'gm',
        'gmc' => 'gm',
    ];

    public static string $slug = 'part-fitment';

    public static string $name = 'Part Fitment';

    public static bool $singleSelect = false;

    public static bool $global = true;

    /**
     * The value a make is stored under, or null when it is not one of ours.
     */
    public static function normalize(string $make): ?string
    {
        $slug = Str::slug($make);
        $slug = self::ALIASES[$slug] ?? $slug;

        return in_array($slug, self::DEFAULT_VALUES, true) ? $slug : null;
    }
}
