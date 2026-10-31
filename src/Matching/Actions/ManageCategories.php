<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Exceptions\CannotManageCategoryException;
use RobinsonRyan\Inventory\Matching\Models\PartClassification;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartCategoryDefinition;
use RobinsonRyan\Inventory\Support\TaxonTables;
use RobinsonRyan\Taxon\Exceptions\CircularTagHierarchyException;
use RobinsonRyan\Taxon\Exceptions\DuplicateTagSlugException;
use RobinsonRyan\Taxon\Models\Tag;
use stdClass;

/**
 * Reorganises the parts tree. Every method refuses a tag outside the tree, and a
 * null parent always means the top of the tree: taxon's own `moveTo(null)` would
 * make the category a taxonomy of its own, and it would vanish from the tree.
 */
final class ManageCategories
{
    /**
     * @throws DuplicateTagSlugException when the parent already holds this name
     */
    public function create(string $name, ?Tag $parent = null): Tag
    {
        if ($parent instanceof Tag) {
            $this->assertCategory($parent);
        }

        $parent ??= PartCategoryDefinition::tag();
        $slug = Str::slug($name);

        if ($parent->children()->where('slug', $slug)->exists()) {
            throw new DuplicateTagSlugException($slug, $parent->getKey());
        }

        return $parent->addChild($name);
    }

    /**
     * @throws DuplicateTagSlugException when a sibling already holds this name
     */
    public function rename(Tag $category, string $name): Tag
    {
        $this->assertCategory($category);

        $slug = Str::slug($name);

        $taken = Tag::query()
            ->where('parent_id', $category->parent_id)
            ->where('slug', $slug)
            ->whereKeyNot($category->getKey())
            ->exists();

        if ($taken) {
            throw new DuplicateTagSlugException($slug, $category->parent_id);
        }

        $category->update(['name' => $name, 'slug' => $slug]);

        return $category;
    }

    /**
     * @throws CircularTagHierarchyException when the destination sits inside the category
     * @throws DuplicateTagSlugException when the destination already holds this name
     */
    public function move(Tag $category, ?Tag $parent): Tag
    {
        $this->assertCategory($category);

        if ($parent instanceof Tag) {
            $this->assertCategory($parent);
        }

        return $category->moveTo($parent ?? PartCategoryDefinition::tag());
    }

    /**
     * Fold one category into another and delete it, in one transaction.
     *
     * Parts and classification rows follow, rejected rows included. Children
     * move under the survivor, and a child whose name the survivor already holds
     * is merged in the same way, all the way down.
     *
     * @return int how many parts left the merged categories for the survivors,
     *             counting a part that was already on the survivor
     */
    public function merge(Tag $from, Tag $into): int
    {
        $this->assertCategory($from);
        $this->assertCategory($into);

        if ($from->is($into) || $into->ancestors()->contains(fn (Tag $ancestor): bool => $ancestor->is($from))) {
            throw CannotManageCategoryException::mergeIntoItself($from->name, $into->name);
        }

        return DB::transaction(function () use ($from, $into): int {
            Tag::query()->whereKey([$from->getKey(), $into->getKey()])->orderBy('id')->lockForUpdate()->get();

            return count(array_unique($this->absorb($from, $into)));
        });
    }

    /**
     * Children go first, because a child may be folded into `$from` itself when
     * it shares a name with it, and has to be gone before `$from` is.
     *
     * @return list<string> the parts `$from` and its merged children held
     */
    private function absorb(Tag $from, Tag $into): array
    {
        $parts = [];

        while (($child = $from->children()->first()) instanceof Tag) {
            $twin = $into->children()->where('slug', $child->slug)->first();

            if ($twin instanceof Tag) {
                $parts = [...$parts, ...$this->absorb($child, $twin)];
            } else {
                $child->moveTo($into);
            }
        }

        $parts = [...$parts, ...$this->repointTaggables($from, $into)];

        PartClassification::query()->where('tag_id', $from->getKey())->update(['tag_id' => $into->getKey()]);

        $from->unsetRelation('children');
        $from->safeDelete();

        return $parts;
    }

    /**
     * Move every tag link from one tag to the other. A taggable already on the
     * survivor would break taxon's unique index, so its link is dropped instead.
     * The comparison mirrors that index, which treats null and '' as the same.
     *
     * @return list<string> the parts that were on `$from`
     */
    private function repointTaggables(Tag $from, Tag $into): array
    {
        $table = TaxonTables::taggables();

        $identity = static function (stdClass $link): string {
            $tenantColumn = config('taxon.tenant.column', 'tenant_id');

            return implode("\0", [
                $link->taggable_type,
                $link->taggable_id,
                $link->scope_type ?? '',
                $link->scope_id ?? '',
                $link->{$tenantColumn} ?? '',
            ]);
        };

        $taken = DB::table($table)->where('tag_id', $into->getKey())->get()->keyBy($identity);
        $links = DB::table($table)->where('tag_id', $from->getKey())->get();

        $isRedundant = fn (stdClass $link): bool => $taken->has($identity($link));

        DB::table($table)->whereIn('id', $links->filter($isRedundant)->pluck('id'))->delete();
        DB::table($table)->whereIn('id', $links->reject($isRedundant)->pluck('id'))->update([
            'tag_id' => $into->getKey(),
            'updated_at' => Date::now(),
        ]);

        /** @var list<string> */
        return $links->where('taggable_type', (new Item)->getMorphClass())->pluck('taggable_id')->values()->all();
    }

    private function assertCategory(Tag $tag): void
    {
        if (! PartCategoryDefinition::contains($tag)) {
            throw CannotManageCategoryException::notACategory($tag->name);
        }
    }
}
