<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\TagDefinitions\ItemCategoryDefinition;
use RobinsonRyan\Inventory\Exceptions\CannotManageCategoryException;
use RobinsonRyan\Inventory\Matching\Actions\ConfirmClassification;
use RobinsonRyan\Inventory\Matching\Actions\ManageCategories;
use RobinsonRyan\Inventory\Matching\Actions\ProposeClassification;
use RobinsonRyan\Inventory\Matching\Actions\RejectClassification;
use RobinsonRyan\Inventory\Matching\Enums\ClassificationAxis;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartClassification;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartCategoryDefinition;
use RobinsonRyan\Inventory\Support\TaxonTables;
use RobinsonRyan\Inventory\Tests\Support\TestActor;
use RobinsonRyan\Taxon\Exceptions\CircularTagHierarchyException;
use RobinsonRyan\Taxon\Exceptions\DuplicateTagSlugException;
use RobinsonRyan\Taxon\Models\Tag;

beforeEach(function (): void {
    $this->categories = new ManageCategories;
});

function categorize(Item $item, string $name): PartClassification
{
    return (new ConfirmClassification)->execute(
        (new ProposeClassification)->execute($item, ClassificationAxis::Category, $name, 0.95),
    );
}

/** Put a category tag on a part directly, the way an overlap arises. */
function attachCategory(Item $item, Tag $tag): void
{
    $item->tags()->attach($tag->getKey());
}

function taggableCount(Tag $tag): int
{
    return DB::table(TaxonTables::taggables())->where('tag_id', $tag->getKey())->count();
}

describe('create', function (): void {
    it('puts a category with no parent at the top of the part tree', function (): void {
        $wheels = $this->categories->create('Wheels', null);

        expect($wheels->parent_id)->toBe(PartCategoryDefinition::tag()->getKey())
            ->and($wheels->slug)->toBe('wheels')
            ->and($wheels->isAssignable())->toBeTrue()
            ->and($wheels->getKey()[14])->toBe('7');
    });

    it('nests a category under its parent', function (): void {
        $accessories = $this->categories->create('Accessories', null);
        $bumpers = $this->categories->create('Bumpers', $accessories);

        expect($bumpers->parent_id)->toBe($accessories->getKey())
            ->and($bumpers->path())->toBe('part-category/accessories/bumpers');
    });

    it('refuses a name its parent already holds', function (): void {
        $this->categories->create('Wheels', null);

        expect(fn () => $this->categories->create('wheels', null))->toThrow(DuplicateTagSlugException::class);
    });

    it('allows the same name under a different parent', function (): void {
        $accessories = $this->categories->create('Accessories', null);
        $consumables = $this->categories->create('Shop consumables', null);

        $this->categories->create('Paint', $accessories);

        expect($this->categories->create('Paint', $consumables)->parent_id)->toBe($consumables->getKey());
    });

    it('refuses a parent outside the part tree', function (): void {
        expect(fn () => $this->categories->create('Wheels', ItemCategoryDefinition::tag()))
            ->toThrow(CannotManageCategoryException::class);
    });
});

describe('rename', function (): void {
    it('changes the name and slug and the parts follow', function (): void {
        $wheels = $this->categories->create('Wheels', null);
        $part = Item::factory()->create();
        categorize($part, 'Wheels');

        $renamed = $this->categories->rename($wheels, 'Wheels & rims');

        expect($renamed->name)->toBe('Wheels & rims')
            ->and($renamed->slug)->toBe('wheels-rims')
            ->and($renamed->getKey())->toBe($wheels->getKey())
            ->and($part->partCategory)->toBe('wheels-rims');
    });

    it('accepts a change of capitals only', function (): void {
        $wheels = $this->categories->create('wheels', null);

        expect($this->categories->rename($wheels, 'Wheels')->name)->toBe('Wheels');
    });

    it('refuses a name a sibling already holds', function (): void {
        $this->categories->create('Tires', null);
        $wheels = $this->categories->create('Wheels', null);

        expect(fn () => $this->categories->rename($wheels, 'Tires'))->toThrow(DuplicateTagSlugException::class);
    });

    it('refuses the root of the tree and tags outside it', function (Closure $target): void {
        expect(fn () => $this->categories->rename($target(), 'Anything'))
            ->toThrow(CannotManageCategoryException::class);
    })->with([
        'the root' => [PartCategoryDefinition::tag(...)],
        'another taxonomy' => [fn (): Tag => ItemCategoryDefinition::valueTag('part')],
    ]);
});

describe('move', function (): void {
    it('moves a category under another, taking its parts and children along', function (): void {
        $brakes = $this->categories->create('Brakes', null);
        $rotors = $this->categories->create('Rotors', $brakes);
        $suspension = $this->categories->create('Suspension', null);
        $part = Item::factory()->create();
        categorize($part, 'Rotors');

        $this->categories->move($brakes, $suspension);

        expect($brakes->refresh()->parent_id)->toBe($suspension->getKey())
            ->and($rotors->refresh()->parent_id)->toBe($brakes->getKey())
            ->and($part->partCategory)->toBe('rotors');
    });

    it('takes no parent to mean the top of the tree, never out of it', function (): void {
        $accessories = $this->categories->create('Accessories', null);
        $bumpers = $this->categories->create('Bumpers', $accessories);

        $this->categories->move($bumpers, null);

        expect($bumpers->refresh()->parent_id)->toBe(PartCategoryDefinition::tag()->getKey());
    });

    it('refuses to move a category into itself', function (): void {
        $brakes = $this->categories->create('Brakes', null);
        $rotors = $this->categories->create('Rotors', $brakes);

        expect(fn () => $this->categories->move($brakes, $rotors))->toThrow(CircularTagHierarchyException::class);
    });

    it('refuses a destination outside the part tree', function (): void {
        $brakes = $this->categories->create('Brakes', null);

        expect(fn () => $this->categories->move($brakes, ItemCategoryDefinition::tag()))
            ->toThrow(CannotManageCategoryException::class);

        expect($brakes->refresh()->parent_id)->toBe(PartCategoryDefinition::tag()->getKey());
    });
});

describe('merge', function (): void {
    beforeEach(function (): void {
        $this->from = $this->categories->create('Axles', null);
        $this->into = $this->categories->create('Axles & differentials', null);
    });

    it('moves the parts over and deletes the emptied category', function (): void {
        $parts = Item::factory()->count(3)->create();

        foreach ($parts as $part) {
            categorize($part, 'Axles');
        }

        $moved = $this->categories->merge($this->from, $this->into);

        expect($moved)->toBe(3)
            ->and(Tag::query()->whereKey($this->from->getKey())->exists())->toBeFalse()
            ->and(taggableCount($this->into))->toBe(3);

        foreach ($parts as $part) {
            expect($part->partCategory)->toBe('axles-differentials');
        }
    });

    it('survives a part already on both categories', function (): void {
        $both = Item::factory()->create();
        attachCategory($both, $this->from);
        attachCategory($both, $this->into);
        $onlyOnFrom = Item::factory()->create();
        attachCategory($onlyOnFrom, $this->from);

        $moved = $this->categories->merge($this->from, $this->into);

        expect($moved)->toBe(2)
            ->and(taggableCount($this->into))->toBe(2)
            ->and(Tag::query()->whereKey($this->from->getKey())->exists())->toBeFalse();
    });

    it('re-points every classification row, history included', function (): void {
        $current = Item::factory()->create();
        $confirmed = categorize($current, 'Axles');
        $rejected = categorize(Item::factory()->create(), 'Axles');
        (new RejectClassification)->execute($rejected, TestActor::named());
        $proposal = (new ProposeClassification)->execute(Item::factory()->create(), ClassificationAxis::Category, 'Axles', 0.6);

        $this->categories->merge($this->from, $this->into);

        expect($confirmed->refresh()->tag_id)->toBe($this->into->getKey())
            ->and($rejected->refresh()->tag_id)->toBe($this->into->getKey())
            ->and($rejected->status)->toBe(MatchStatus::Vetoed)
            ->and($proposal->refresh()->tag_id)->toBe($this->into->getKey());
    });

    it('moves children under the surviving category', function (): void {
        $child = $this->categories->create('Shafts', $this->from);
        $part = Item::factory()->create();
        categorize($part, 'Shafts');

        $moved = $this->categories->merge($this->from, $this->into);

        expect($moved)->toBe(0)
            ->and($child->refresh()->parent_id)->toBe($this->into->getKey())
            ->and($part->partCategory)->toBe('shafts');
    });

    it('merges a child whose slug the survivor already holds, all the way down', function (): void {
        $losingShafts = $this->categories->create('Shafts', $this->from);
        $keepingShafts = $this->categories->create('Shafts', $this->into);
        $grandchild = $this->categories->create('Rear shafts', $losingShafts);
        $losingFront = $this->categories->create('Front shafts', $losingShafts);
        $keepingFront = $this->categories->create('Front shafts', $keepingShafts);

        $shaftPart = Item::factory()->create();
        attachCategory($shaftPart, $losingShafts);
        $frontPart = Item::factory()->create();
        attachCategory($frontPart, $losingFront);
        $axlePart = Item::factory()->create();
        attachCategory($axlePart, $this->from);

        $moved = $this->categories->merge($this->from, $this->into);

        expect($moved)->toBe(3)
            ->and(Tag::query()->whereKey([$this->from->getKey(), $losingShafts->getKey(), $losingFront->getKey()])->exists())->toBeFalse()
            ->and($grandchild->refresh()->parent_id)->toBe($keepingShafts->getKey())
            ->and(taggableCount($keepingShafts))->toBe(1)
            ->and(taggableCount($keepingFront))->toBe(1)
            ->and(taggableCount($this->into))->toBe(1);
    });

    it('merges a category up into its own parent', function (): void {
        $child = $this->categories->create('Rear axles', $this->into);
        $part = Item::factory()->create();
        attachCategory($part, $child);

        expect($this->categories->merge($child, $this->into))->toBe(1)
            ->and(taggableCount($this->into))->toBe(1);
    });

    it('copes with a child named like the category being merged', function (): void {
        $outer = $this->categories->create('Lights', $this->into);
        $inner = $this->categories->create('Lights', $outer);
        $part = Item::factory()->create();
        attachCategory($part, $inner);

        expect($this->categories->merge($outer, $this->into))->toBe(1)
            ->and(Tag::query()->whereKey([$outer->getKey(), $inner->getKey()])->exists())->toBeFalse()
            ->and(taggableCount($this->into))->toBe(1);
    });

    it('refuses to merge a category into itself or its own subtree', function (): void {
        $child = $this->categories->create('Shafts', $this->from);

        expect(fn () => $this->categories->merge($this->from, $this->from))
            ->toThrow(CannotManageCategoryException::class)
            ->and(fn () => $this->categories->merge($this->from, $child))
            ->toThrow(CannotManageCategoryException::class);
    });

    it('refuses tags outside the part tree', function (): void {
        expect(fn () => $this->categories->merge(ItemCategoryDefinition::valueTag('part'), $this->into))
            ->toThrow(CannotManageCategoryException::class)
            ->and(fn () => $this->categories->merge($this->from, PartCategoryDefinition::tag()))
            ->toThrow(CannotManageCategoryException::class);
    });

    it('leaves everything as it was when the merge fails part way', function (): void {
        $child = $this->categories->create('Shafts', $this->from);
        $part = Item::factory()->create();
        attachCategory($part, $this->from);
        Tag::deleting(function (): void {
            throw new RuntimeException('The disk is full.');
        });

        expect(fn () => $this->categories->merge($this->from, $this->into))->toThrow(RuntimeException::class);

        expect(taggableCount($this->from))->toBe(1)
            ->and(taggableCount($this->into))->toBe(0)
            ->and($child->refresh()->parent_id)->toBe($this->from->getKey());
    });
});
