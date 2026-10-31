<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\TagDefinitions;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RobinsonRyan\Taxon\Models\Tag;
use RobinsonRyan\Taxon\TagDefinition;

/**
 * Where a part sits in AFWD's parts tree. Not `item-category`, which is a flat
 * list of what kind of thing an item is.
 *
 * The tree nests through taxon's `parent_id`, which taxon's definition API does
 * not know about: `values()`, `setTagAs()` and `getTagAs()` see the first level
 * only. So categories are written through ManageCategories, tags are attached
 * directly, and the tree is read through the methods here. The tree is data a
 * host seeds with PartCategoryInstaller, not a list of declared values.
 */
final class PartCategoryDefinition extends TagDefinition
{
    /**
     * @var list<string>
     */
    public const DEFAULT_VALUES = [];

    public static string $slug = 'part-category';

    public static string $name = 'Part Category';

    public static bool $singleSelect = true;

    public static bool $global = true;

    /**
     * Every category at every depth, without the root that holds them.
     *
     * @return Collection<int, Tag>
     */
    public static function tree(): Collection
    {
        return self::tag()->descendants();
    }

    /**
     * The category with this name, at any depth. Null when there is none, and
     * when two branches share the name, since then the name does not say which.
     */
    public static function findByName(string $name): ?Tag
    {
        $found = self::tree()->where('slug', Str::slug($name));

        return $found->count() === 1 ? $found->first() : null;
    }

    public static function contains(Tag $tag): bool
    {
        $root = self::tag();

        return $tag->ancestors()->contains(fn (Tag $ancestor): bool => $ancestor->is($root));
    }
}
