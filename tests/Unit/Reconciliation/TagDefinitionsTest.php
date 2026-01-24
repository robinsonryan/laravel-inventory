<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Reconciliation\TagDefinitions\CycleCountStatusDefinition;
use RobinsonRyan\Taxon\TagDefinition;

describe('CycleCountStatusDefinition', function () {
    it('extends TagDefinition', function () {
        expect(is_subclass_of(CycleCountStatusDefinition::class, TagDefinition::class))->toBeTrue();
    });

    it('has slug property', function () {
        expect(CycleCountStatusDefinition::$slug)->toBe('cycle-count-status');
    });

    it('has name property', function () {
        expect(CycleCountStatusDefinition::$name)->toBe('Cycle Count Status');
    });

    it('is single select', function () {
        expect(CycleCountStatusDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function () {
        expect(CycleCountStatusDefinition::$global)->toBeTrue();
    });

    it('has draft status', function () {
        expect(CycleCountStatusDefinition::DEFAULT_VALUES)->toContain('draft');
    });

    it('has in_progress status', function () {
        expect(CycleCountStatusDefinition::DEFAULT_VALUES)->toContain('in_progress');
    });

    it('has pending status', function () {
        expect(CycleCountStatusDefinition::DEFAULT_VALUES)->toContain('pending');
    });

    it('has approved status', function () {
        expect(CycleCountStatusDefinition::DEFAULT_VALUES)->toContain('approved');
    });

    it('has cancelled status', function () {
        expect(CycleCountStatusDefinition::DEFAULT_VALUES)->toContain('cancelled');
    });

    it('has exactly five statuses', function () {
        expect(CycleCountStatusDefinition::DEFAULT_VALUES)->toHaveCount(5);
    });

    it('defines open statuses array', function () {
        expect(CycleCountStatusDefinition::OPEN)
            ->toBe(['draft', 'in_progress', 'pending']);
    });

    it('defines terminal statuses array', function () {
        expect(CycleCountStatusDefinition::TERMINAL)
            ->toBe(['approved', 'cancelled']);
    });

    it('open and terminal are mutually exclusive', function () {
        $open = CycleCountStatusDefinition::OPEN;
        $terminal = CycleCountStatusDefinition::TERMINAL;

        $intersection = array_intersect($open, $terminal);

        expect($intersection)->toBeEmpty();
    });

    it('open and terminal cover all statuses', function () {
        $open = CycleCountStatusDefinition::OPEN;
        $terminal = CycleCountStatusDefinition::TERMINAL;
        $all = CycleCountStatusDefinition::DEFAULT_VALUES;

        $combined = array_merge($open, $terminal);
        sort($combined);
        sort($all);

        expect($combined)->toBe($all);
    });

    describe('workflow', function () {
        it('draft is an open status', function () {
            expect(CycleCountStatusDefinition::OPEN)->toContain('draft');
        });

        it('in_progress is an open status', function () {
            expect(CycleCountStatusDefinition::OPEN)->toContain('in_progress');
        });

        it('pending is an open status', function () {
            expect(CycleCountStatusDefinition::OPEN)->toContain('pending');
        });

        it('approved is a terminal status', function () {
            expect(CycleCountStatusDefinition::TERMINAL)->toContain('approved');
        });

        it('cancelled is a terminal status', function () {
            expect(CycleCountStatusDefinition::TERMINAL)->toContain('cancelled');
        });
    });
});
