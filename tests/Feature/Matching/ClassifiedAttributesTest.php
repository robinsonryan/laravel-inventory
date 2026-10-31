<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Exceptions\CannotClassifyException;
use RobinsonRyan\Inventory\Matching\Actions\ConfirmClassification;
use RobinsonRyan\Inventory\Matching\Actions\ManageCategories;
use RobinsonRyan\Inventory\Matching\Actions\ProposeClassification;
use RobinsonRyan\Inventory\Matching\Enums\ClassificationAxis;
use RobinsonRyan\Inventory\Matching\Models\PartClassification;

beforeEach(function (): void {
    $this->part = Item::factory()->create();
    $this->classify = function (ClassificationAxis $axis, string $value): void {
        (new ConfirmClassification)->execute((new ProposeClassification)->execute($this->part, $axis, $value, 0.95));
    };
});

it('refuses writing fitment past the actions, which would wipe the other makes behind their rows', function (Closure $write): void {
    ($this->classify)(ClassificationAxis::Fitment, 'Ford');
    ($this->classify)(ClassificationAxis::Fitment, 'GM');

    expect(fn () => $write($this->part))->toThrow(CannotClassifyException::class, 'fitment');

    expect($this->part->refresh()->fitment)->toBe(['ford', 'gm'])
        ->and(PartClassification::query()->confirmed()->count())->toBe(2);
})->with([
    'assignment' => [function (Item $part): void {
        $part->fitment = 'nissan';
    }],
    'setAttribute' => [fn (Item $part): mixed => $part->setAttribute('fitment', 'nissan')],
    'forceFill' => [fn (Item $part): Item => $part->forceFill(['fitment' => 'nissan'])],
]);

it('drops fitment from a mass update, since it is not fillable', function (): void {
    ($this->classify)(ClassificationAxis::Fitment, 'Ford');

    $this->part->update(['fitment' => 'nissan']);

    expect($this->part->refresh()->fitment)->toBe(['ford']);
});

it('refuses fitment on an unsaved part before anything is written', function (): void {
    expect(fn () => Item::factory()->create(['sku' => 'NEW-FIT', 'fitment' => 'ford']))
        ->toThrow(CannotClassifyException::class, 'fitment');

    expect(Item::query()->where('sku', 'NEW-FIT')->exists())->toBeFalse();
});

it('refuses assigning the part category', function (): void {
    (new ManageCategories)->create('Brakes');
    ($this->classify)(ClassificationAxis::Category, 'Brakes');

    expect(function (): void {
        $this->part->partCategory = 'wheels';
    })->toThrow(CannotClassifyException::class, 'partCategory');

    expect($this->part->refresh()->partCategory)->toBe('brakes');
});

it('leaves the flat category assignable', function (): void {
    $part = Item::factory()->create();

    $part->category = 'part';

    expect($part->refresh()->category)->toBe('part');
});

it('keeps reading both attributes', function (): void {
    (new ManageCategories)->create('Brakes');
    ($this->classify)(ClassificationAxis::Category, 'Brakes');
    ($this->classify)(ClassificationAxis::Fitment, 'Nissan');

    expect($this->part->partCategory)->toBe('brakes')
        ->and($this->part->fitment)->toBe(['nissan']);
});
