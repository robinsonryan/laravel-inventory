<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Catalog\TagDefinitions\ItemCategoryDefinition;

describe('ItemCategoryDefinition', function (): void {
    it('has correct slug', function (): void {
        expect(ItemCategoryDefinition::$slug)->toBe('item-category');
    });

    it('has correct name', function (): void {
        expect(ItemCategoryDefinition::$name)->toBe('Item Category');
    });

    it('is single select', function (): void {
        expect(ItemCategoryDefinition::$singleSelect)->toBeTrue();
    });

    it('is global (not tenant-scoped)', function (): void {
        expect(ItemCategoryDefinition::$global)->toBeTrue();
    });

    it('has expected default values', function (): void {
        $expected = ['part', 'supply', 'fluid', 'labor', 'service', 'assembly'];

        expect(ItemCategoryDefinition::DEFAULT_VALUES)->toBe($expected);
    });

    it('default values include part category', function (): void {
        expect(ItemCategoryDefinition::DEFAULT_VALUES)->toContain('part');
    });

    it('default values include supply category', function (): void {
        expect(ItemCategoryDefinition::DEFAULT_VALUES)->toContain('supply');
    });

    it('default values include fluid category', function (): void {
        expect(ItemCategoryDefinition::DEFAULT_VALUES)->toContain('fluid');
    });

    it('default values include labor category', function (): void {
        expect(ItemCategoryDefinition::DEFAULT_VALUES)->toContain('labor');
    });

    it('default values include service category', function (): void {
        expect(ItemCategoryDefinition::DEFAULT_VALUES)->toContain('service');
    });

    it('default values include assembly category', function (): void {
        expect(ItemCategoryDefinition::DEFAULT_VALUES)->toContain('assembly');
    });

    it('has exactly 6 default values', function (): void {
        expect(ItemCategoryDefinition::DEFAULT_VALUES)->toHaveCount(6);
    });
});
