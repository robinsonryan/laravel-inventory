<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;

describe('ColumnDefinition', function () {
    it('creates column definition with defaults', function () {
        $column = new ColumnDefinition('id', 'ID');

        expect($column->key)->toBe('id')
            ->and($column->label)->toBe('ID')
            ->and($column->type)->toBe(ColumnDefinition::TYPE_STRING)
            ->and($column->sortable)->toBeTrue()
            ->and($column->format)->toBeNull();
    });

    it('creates column definition with all properties', function () {
        $column = new ColumnDefinition(
            key: 'amount',
            label: 'Total Amount',
            type: ColumnDefinition::TYPE_CURRENCY,
            sortable: false,
            format: '0.00',
        );

        expect($column->key)->toBe('amount')
            ->and($column->label)->toBe('Total Amount')
            ->and($column->type)->toBe(ColumnDefinition::TYPE_CURRENCY)
            ->and($column->sortable)->toBeFalse()
            ->and($column->format)->toBe('0.00');
    });

    it('has correct type constants', function () {
        expect(ColumnDefinition::TYPE_STRING)->toBe('string')
            ->and(ColumnDefinition::TYPE_NUMBER)->toBe('number')
            ->and(ColumnDefinition::TYPE_CURRENCY)->toBe('currency')
            ->and(ColumnDefinition::TYPE_DATE)->toBe('date')
            ->and(ColumnDefinition::TYPE_DATETIME)->toBe('datetime');
    });

    it('creates from array', function () {
        $column = ColumnDefinition::fromArray([
            'key' => 'price',
            'label' => 'Price',
            'type' => 'currency',
            'sortable' => true,
            'format' => '$0.00',
        ]);

        expect($column->key)->toBe('price')
            ->and($column->label)->toBe('Price')
            ->and($column->type)->toBe('currency')
            ->and($column->sortable)->toBeTrue()
            ->and($column->format)->toBe('$0.00');
    });

    it('creates from array with defaults', function () {
        $column = ColumnDefinition::fromArray([
            'key' => 'name',
            'label' => 'Name',
        ]);

        expect($column->key)->toBe('name')
            ->and($column->label)->toBe('Name')
            ->and($column->type)->toBe('string')
            ->and($column->sortable)->toBeTrue()
            ->and($column->format)->toBeNull();
    });

    it('converts to array', function () {
        $column = new ColumnDefinition(
            key: 'quantity',
            label: 'Quantity',
            type: ColumnDefinition::TYPE_NUMBER,
            sortable: true,
            format: '0.00',
        );

        $array = $column->toArray();

        expect($array)->toBe([
            'key' => 'quantity',
            'label' => 'Quantity',
            'type' => 'number',
            'sortable' => true,
            'format' => '0.00',
        ]);
    });

    it('handles null format in array conversion', function () {
        $column = new ColumnDefinition('id', 'ID');
        $array = $column->toArray();

        expect($array['format'])->toBeNull();
    });
});
