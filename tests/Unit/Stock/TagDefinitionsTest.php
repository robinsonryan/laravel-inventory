<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Stock\TagDefinitions\LocationTypeDefinition;
use RobinsonRyan\Inventory\Stock\TagDefinitions\MovementTypeDefinition;
use RobinsonRyan\Inventory\Stock\TagDefinitions\ReservationStatusDefinition;

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

    it('has seven default values', function () {
        expect(LocationTypeDefinition::DEFAULT_VALUES)->toHaveCount(7);
    });

    it('has expected default values', function () {
        $values = LocationTypeDefinition::DEFAULT_VALUES;

        expect($values)->toContain('warehouse')
            ->toContain('bin')
            ->toContain('shelf')
            ->toContain('wip')
            ->toContain('truck')
            ->toContain('virtual')
            ->toContain('quarantine');
    });
});

describe('MovementTypeDefinition', function () {
    it('has correct slug', function () {
        expect(MovementTypeDefinition::$slug)->toBe('movement-type');
    });

    it('has correct name', function () {
        expect(MovementTypeDefinition::$name)->toBe('Movement Type');
    });

    it('is single select', function () {
        expect(MovementTypeDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function () {
        expect(MovementTypeDefinition::$global)->toBeTrue();
    });

    it('has eight default values', function () {
        expect(MovementTypeDefinition::DEFAULT_VALUES)->toHaveCount(8);
    });

    it('has expected default values', function () {
        $values = MovementTypeDefinition::DEFAULT_VALUES;

        expect($values)->toContain('purchase')
            ->toContain('sale')
            ->toContain('transfer')
            ->toContain('adjustment')
            ->toContain('consumption')
            ->toContain('production')
            ->toContain('return')
            ->toContain('scrap');
    });
});

describe('ReservationStatusDefinition', function () {
    it('has correct slug', function () {
        expect(ReservationStatusDefinition::$slug)->toBe('reservation-status');
    });

    it('has correct name', function () {
        expect(ReservationStatusDefinition::$name)->toBe('Reservation Status');
    });

    it('is single select', function () {
        expect(ReservationStatusDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function () {
        expect(ReservationStatusDefinition::$global)->toBeTrue();
    });

    it('has six default values', function () {
        expect(ReservationStatusDefinition::DEFAULT_VALUES)->toHaveCount(6);
    });

    it('has expected default values', function () {
        $values = ReservationStatusDefinition::DEFAULT_VALUES;

        expect($values)->toContain('pending')
            ->toContain('reserved')
            ->toContain('partial')
            ->toContain('fulfilled')
            ->toContain('cancelled')
            ->toContain('expired');
    });
});
