<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\TagDefinitions\LocationTypeDefinition;

describe('Location Model', function () {
    it('creates a location', function () {
        $location = Location::factory()->create([
            'code' => 'WH-001',
            'name' => 'Main Warehouse',
        ]);

        expect($location)->toBeInstanceOf(Location::class)
            ->and($location->id)->not->toBeNull()
            ->and($location->code)->toBe('WH-001')
            ->and($location->name)->toBe('Main Warehouse')
            ->and($location->is_active)->toBeTrue()
            ->and($location->is_pickable)->toBeTrue()
            ->and($location->is_receivable)->toBeTrue();
    });

    it('creates nested locations', function () {
        $warehouse = Location::factory()->warehouse()->create([
            'code' => 'WH-001',
            'name' => 'Main Warehouse',
        ]);

        $bin = Location::factory()->bin()->withParent($warehouse)->create([
            'code' => 'BIN-001',
            'name' => 'Bin A-1',
        ]);

        expect($bin->parent_id)->toBe($warehouse->id)
            ->and($bin->parent->id)->toBe($warehouse->id)
            ->and($warehouse->children)->toHaveCount(1)
            ->and($warehouse->children->first()->id)->toBe($bin->id);
    });

    it('sets location type via tag', function () {
        $location = Location::factory()->create();
        $location->type = 'warehouse';

        expect($location->type)->toBe('warehouse');

        $location->type = 'bin';
        expect($location->type)->toBe('bin');

        $location->type = 'quarantine';
        expect($location->type)->toBe('quarantine');
    });

    it('creates locations with factory states', function () {
        $warehouse = Location::factory()->warehouse()->create();
        expect($warehouse->type)->toBe('warehouse');

        $bin = Location::factory()->bin()->create();
        expect($bin->type)->toBe('bin');

        $shelf = Location::factory()->shelf()->create();
        expect($shelf->type)->toBe('shelf');

        $wip = Location::factory()->wip()->create();
        expect($wip->type)->toBe('wip')
            ->and($wip->is_receivable)->toBeFalse();

        $truck = Location::factory()->truck()->create();
        expect($truck->type)->toBe('truck');

        $virtual = Location::factory()->virtual()->create();
        expect($virtual->type)->toBe('virtual')
            ->and($virtual->is_pickable)->toBeFalse()
            ->and($virtual->is_receivable)->toBeFalse();

        $quarantine = Location::factory()->quarantine()->create();
        expect($quarantine->type)->toBe('quarantine')
            ->and($quarantine->is_pickable)->toBeFalse();
    });

    it('creates inactive location', function () {
        $location = Location::factory()->inactive()->create();

        expect($location->is_active)->toBeFalse();
    });

    it('casts metadata to array', function () {
        $location = Location::factory()->create([
            'metadata' => ['capacity' => 1000, 'zone' => 'A'],
        ]);

        expect($location->metadata)->toBeArray()
            ->and($location->metadata['capacity'])->toBe(1000)
            ->and($location->metadata['zone'])->toBe('A');
    });
});

describe('LocationTypeDefinition', function () {
    it('has correct slug', function () {
        expect(LocationTypeDefinition::$slug)->toBe('location-type');
    });

    it('has correct name', function () {
        expect(LocationTypeDefinition::$name)->toBe('Location Type');
    });

    it('is single select', function () {
        expect(LocationTypeDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function () {
        expect(LocationTypeDefinition::$global)->toBeTrue();
    });

    it('has expected default values', function () {
        expect(LocationTypeDefinition::DEFAULT_VALUES)
            ->toContain('warehouse')
            ->toContain('bin')
            ->toContain('shelf')
            ->toContain('wip')
            ->toContain('truck')
            ->toContain('virtual')
            ->toContain('quarantine');
    });
});
