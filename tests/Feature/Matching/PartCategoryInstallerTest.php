<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Matching\Actions\ManageCategories;
use RobinsonRyan\Inventory\Matching\Support\PartCategoryInstaller;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartCategoryDefinition;
use RobinsonRyan\Taxon\Models\Tag;

/** @return array<string, list<string>> */
function categoryTree(): array
{
    $tree = [];

    foreach (PartCategoryDefinition::tag()->children()->orderBy('created_at')->get() as $category) {
        $tree[$category->name] = $category->children()->orderBy('name')->pluck('name')->all();
    }

    return $tree;
}

it('ships the starting tree as config, leaving the seeding to the host', function (): void {
    expect(config('inventory.part_categories'))->toBe([
        'Wheels',
        'Tires',
        'Accessories' => ['Bumpers', 'Steps', 'Lights', 'Roof racks', 'Winches', 'Awnings'],
        'Suspension',
        'Axles & differentials',
        'Transfer cases',
        'Drivelines',
        'Steering',
        'Brakes',
        'Electrical',
        'Hardware & fasteners',
        'Fluids',
        'Shop consumables' => ['Welding wire', 'Welding gas', 'Abrasives', 'Paint'],
    ])
        ->and(PartCategoryDefinition::tag()->children()->exists())->toBeFalse();
});

it('seeds the tree under the part category', function (): void {
    $created = PartCategoryInstaller::seed(config('inventory.part_categories'));

    $tree = categoryTree();

    expect($created)->toBe(23)
        ->and(array_keys($tree))->toContain('Wheels', 'Axles & differentials', 'Shop consumables')
        ->and($tree['Accessories'])->toEqualCanonicalizing(['Bumpers', 'Steps', 'Lights', 'Roof racks', 'Winches', 'Awnings'])
        ->and($tree['Shop consumables'])->toEqualCanonicalizing(['Welding wire', 'Welding gas', 'Abrasives', 'Paint'])
        ->and($tree['Wheels'])->toBe([]);
});

it('creates nothing on a second run', function (): void {
    PartCategoryInstaller::seed(config('inventory.part_categories'));
    $count = Tag::query()->count();

    expect(PartCategoryInstaller::seed(config('inventory.part_categories')))->toBe(0)
        ->and(Tag::query()->count())->toBe($count);
});

it('tells names apart by their parent', function (): void {
    PartCategoryInstaller::seed(['Accessories' => ['Paint'], 'Shop consumables' => ['Paint']]);

    expect(PartCategoryDefinition::tree()->where('slug', 'paint'))->toHaveCount(2);
});

it('adds the missing branch of a tree partly present', function (): void {
    $accessories = (new ManageCategories)->create('Accessories');

    expect(PartCategoryInstaller::seed(['Accessories' => ['Bumpers']]))->toBe(1)
        ->and($accessories->children()->pluck('name')->all())->toBe(['Bumpers']);
});

it('nests deeper than two levels', function (): void {
    PartCategoryInstaller::seed(['Accessories' => ['Lights' => ['Work lights']]]);

    expect(PartCategoryDefinition::findByName('Work lights')?->path())
        ->toBe('part-category/accessories/lights/work-lights');
});
