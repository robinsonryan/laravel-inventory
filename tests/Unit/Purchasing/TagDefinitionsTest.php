<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Purchasing\TagDefinitions\PurchaseOrderStatusDefinition;
use RobinsonRyan\Taxon\TagDefinition;

describe('PurchaseOrderStatusDefinition', function (): void {
    it('extends TagDefinition', function (): void {
        expect(is_subclass_of(PurchaseOrderStatusDefinition::class, TagDefinition::class))->toBeTrue();
    });

    it('has slug property', function (): void {
        expect(PurchaseOrderStatusDefinition::$slug)->toBe('purchase-order-status');
    });

    it('has name property', function (): void {
        expect(PurchaseOrderStatusDefinition::$name)->toBe('Purchase Order Status');
    });

    it('is single select', function (): void {
        expect(PurchaseOrderStatusDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function (): void {
        expect(PurchaseOrderStatusDefinition::$global)->toBeTrue();
    });

    it('has draft status', function (): void {
        expect(PurchaseOrderStatusDefinition::DEFAULT_VALUES)->toContain('draft');
    });

    it('has submitted status', function (): void {
        expect(PurchaseOrderStatusDefinition::DEFAULT_VALUES)->toContain('submitted');
    });

    it('has partial status', function (): void {
        expect(PurchaseOrderStatusDefinition::DEFAULT_VALUES)->toContain('partial');
    });

    it('has received status', function (): void {
        expect(PurchaseOrderStatusDefinition::DEFAULT_VALUES)->toContain('received');
    });

    it('has cancelled status', function (): void {
        expect(PurchaseOrderStatusDefinition::DEFAULT_VALUES)->toContain('cancelled');
    });

    it('has exactly five statuses', function (): void {
        expect(PurchaseOrderStatusDefinition::DEFAULT_VALUES)->toHaveCount(5);
    });

    it('defines open statuses array', function (): void {
        expect(PurchaseOrderStatusDefinition::OPEN)
            ->toBe(['draft', 'submitted', 'partial']);
    });

    it('defines terminal statuses array', function (): void {
        expect(PurchaseOrderStatusDefinition::TERMINAL)
            ->toBe(['received', 'cancelled']);
    });

    it('open and terminal are mutually exclusive', function (): void {
        $open = PurchaseOrderStatusDefinition::OPEN;
        $terminal = PurchaseOrderStatusDefinition::TERMINAL;

        $intersection = array_intersect($open, $terminal);

        expect($intersection)->toBeEmpty();
    });

    it('open and terminal cover all statuses', function (): void {
        $open = PurchaseOrderStatusDefinition::OPEN;
        $terminal = PurchaseOrderStatusDefinition::TERMINAL;
        $all = PurchaseOrderStatusDefinition::DEFAULT_VALUES;

        $combined = array_merge($open, $terminal);
        sort($combined);
        sort($all);

        expect($combined)->toBe($all);
    });
});
