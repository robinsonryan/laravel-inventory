<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartCategoryDefinition;
use RobinsonRyan\Taxon\Models\Tag;

/**
 * Seeds a tree of part categories, usually `config('inventory.part_categories')`.
 *
 * Unlike the tag values this package declares, the tree is the host's data, so
 * no migration here runs this: the host calls it from one of its own. A category
 * is the same category when it has the same name and parent, so re-running adds
 * only what is missing. A category renamed since would come back under its old
 * name, so this is for starting a tree rather than maintaining one.
 */
final class PartCategoryInstaller
{
    /**
     * @param  array<int|string, mixed>  $tree  a name with no children, or name => subtree
     * @return int the number of categories created
     */
    public static function seed(array $tree): int
    {
        return DB::transaction(fn (): int => self::seedUnder(PartCategoryDefinition::tag(), $tree));
    }

    /**
     * @param  array<int|string, mixed>  $tree
     */
    private static function seedUnder(Tag $parent, array $tree): int
    {
        $created = 0;

        foreach ($tree as $key => $value) {
            /** @var string $name */
            $name = is_string($key) ? $key : $value;

            /** @var array<int|string, mixed> $children */
            $children = is_string($key) ? $value : [];

            $category = $parent->children()->where('slug', Str::slug($name))->first();

            if (! $category instanceof Tag) {
                $category = $parent->addChild($name);
                $created++;
            }

            $created += self::seedUnder($category, $children);
        }

        return $created;
    }
}
