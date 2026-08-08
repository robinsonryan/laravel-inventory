<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Stock\TagDefinitions\LocationTypeDefinition;
use RobinsonRyan\Inventory\Stock\TagDefinitions\MovementTypeDefinition;
use RobinsonRyan\Inventory\Stock\TagDefinitions\ReservationStatusDefinition;

describe('LocationTypeDefinition', function (): void {
    it('has correct slug', function (): void {
        expect(LocationTypeDefinition::$slug)->toBe('location-type');
    });

    it('has correct name', function (): void {
        expect(LocationTypeDefinition::$name)->toBe('Location Type');
    });

    it('is single select', function (): void {
        expect(LocationTypeDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function (): void {
        expect(LocationTypeDefinition::$global)->toBeTrue();
    });

    it('has seven default values', function (): void {
        expect(LocationTypeDefinition::DEFAULT_VALUES)->toHaveCount(7);
    });

    it('has expected default values', function (): void {
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

describe('MovementTypeDefinition', function (): void {
    it('has correct slug', function (): void {
        expect(MovementTypeDefinition::$slug)->toBe('movement-type');
    });

    it('has correct name', function (): void {
        expect(MovementTypeDefinition::$name)->toBe('Movement Type');
    });

    it('is single select', function (): void {
        expect(MovementTypeDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function (): void {
        expect(MovementTypeDefinition::$global)->toBeTrue();
    });

    it('has eight default values', function (): void {
        expect(MovementTypeDefinition::DEFAULT_VALUES)->toHaveCount(8);
    });

    it('has expected default values', function (): void {
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

describe('ReservationStatusDefinition', function (): void {
    it('has correct slug', function (): void {
        expect(ReservationStatusDefinition::$slug)->toBe('reservation-status');
    });

    it('has correct name', function (): void {
        expect(ReservationStatusDefinition::$name)->toBe('Reservation Status');
    });

    it('is single select', function (): void {
        expect(ReservationStatusDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function (): void {
        expect(ReservationStatusDefinition::$global)->toBeTrue();
    });

    it('has six default values', function (): void {
        expect(ReservationStatusDefinition::DEFAULT_VALUES)->toHaveCount(6);
    });

    it('has expected default values', function (): void {
        $values = ReservationStatusDefinition::DEFAULT_VALUES;

        expect($values)->toContain('pending')
            ->toContain('reserved')
            ->toContain('partial')
            ->toContain('fulfilled')
            ->toContain('cancelled')
            ->toContain('expired');
    });
});
