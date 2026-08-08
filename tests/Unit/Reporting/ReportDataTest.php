<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;

describe('ReportData', function (): void {
    it('creates report data with all properties', function (): void {
        $columns = [
            new ColumnDefinition('id', 'ID'),
            new ColumnDefinition('name', 'Name'),
        ];

        $rows = collect([
            (object) ['id' => '1', 'name' => 'Item 1'],
            (object) ['id' => '2', 'name' => 'Item 2'],
        ]);

        $summary = ['total' => 2];
        $generatedAt = Carbon::now();

        $data = new ReportData(
            title: 'Test Report',
            columns: $columns,
            rows: $rows,
            summary: $summary,
            generatedAt: $generatedAt,
        );

        expect($data->title)->toBe('Test Report')
            ->and($data->columns)->toBe($columns)
            ->and($data->rows)->toBe($rows)
            ->and($data->summary)->toBe($summary)
            ->and($data->generatedAt)->toBe($generatedAt);
    });

    it('gets column by key', function (): void {
        $columns = [
            new ColumnDefinition('id', 'ID'),
            new ColumnDefinition('name', 'Name', ColumnDefinition::TYPE_STRING),
        ];

        $data = new ReportData(
            title: 'Test',
            columns: $columns,
            rows: collect([]),
        );

        $column = $data->getColumn('name');

        expect($column)->toBeArray()
            ->toHaveKey('key')
            ->toHaveKey('label')
            ->toHaveKey('type');

        expect($column['key'])->toBe('name')
            ->and($column['label'])->toBe('Name')
            ->and($column['type'])->toBe('string');
    });

    it('returns null for unknown column', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [new ColumnDefinition('id', 'ID')],
            rows: collect([]),
        );

        expect($data->getColumn('unknown'))->toBeNull();
    });

    it('gets summary value', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [],
            rows: collect([]),
            summary: [
                'total' => 100,
                'average' => 50.5,
                'label' => 'Test Label',
            ],
        );

        expect($data->getSummary('total'))->toBe(100)
            ->and($data->getSummary('average'))->toBe(50.5)
            ->and($data->getSummary('label'))->toBe('Test Label')
            ->and($data->getSummary('unknown'))->toBeNull();
    });

    it('converts to array', function (): void {
        $generatedAt = Carbon::parse('2026-01-15 10:00:00');

        $data = new ReportData(
            title: 'Test Report',
            columns: [
                new ColumnDefinition('id', 'ID'),
            ],
            rows: collect([
                (object) ['id' => '1'],
            ]),
            summary: ['total' => 1],
            generatedAt: $generatedAt,
        );

        $array = $data->toArray();

        expect($array)->toBeArray()
            ->toHaveKey('title')
            ->toHaveKey('columns')
            ->toHaveKey('rows')
            ->toHaveKey('summary')
            ->toHaveKey('generated_at');

        expect($array['title'])->toBe('Test Report')
            ->and($array['columns'])->toHaveCount(1)
            ->and($array['rows'])->toHaveCount(1)
            ->and($array['summary'])->toBe(['total' => 1])
            ->and($array['generated_at'])->toBe($generatedAt->toIso8601String());
    });

    it('handles null generated_at in toArray', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [],
            rows: collect([]),
        );

        $array = $data->toArray();

        expect($array['generated_at'])->toBeNull();
    });

    it('gets column keys', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('id', 'ID'),
                new ColumnDefinition('name', 'Name'),
                new ColumnDefinition('value', 'Value'),
            ],
            rows: collect([]),
        );

        $keys = $data->getColumnKeys();

        expect($keys)->toBe(['id', 'name', 'value']);
    });

    it('gets column labels', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('id', 'ID'),
                new ColumnDefinition('name', 'Full Name'),
                new ColumnDefinition('value', 'Total Value'),
            ],
            rows: collect([]),
        );

        $labels = $data->getColumnLabels();

        expect($labels)->toBe(['ID', 'Full Name', 'Total Value']);
    });

    it('checks if empty', function (): void {
        $emptyData = new ReportData(
            title: 'Empty',
            columns: [],
            rows: collect([]),
        );

        $nonEmptyData = new ReportData(
            title: 'Non-Empty',
            columns: [],
            rows: collect([['id' => 1]]),
        );

        expect($emptyData->isEmpty())->toBeTrue()
            ->and($nonEmptyData->isEmpty())->toBeFalse();
    });
});
