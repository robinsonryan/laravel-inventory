<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Exporters\JsonExporter;

describe('JsonExporter', function (): void {
    it('exports basic data to JSON', function (): void {
        $data = new ReportData(
            title: 'Test Report',
            columns: [
                new ColumnDefinition('id', 'ID'),
                new ColumnDefinition('name', 'Name'),
            ],
            rows: collect([
                (object) ['id' => '1', 'name' => 'Item One'],
                (object) ['id' => '2', 'name' => 'Item Two'],
            ]),
            summary: ['count' => 2],
            generatedAt: Carbon::parse('2026-01-15 10:00:00'),
        );

        $exporter = new JsonExporter;
        $json = $exporter->export($data);
        $decoded = json_decode($json, true);

        expect($decoded)->toBeArray()
            ->and($decoded['title'])->toBe('Test Report')
            ->and($decoded['rows'])->toHaveCount(2)
            ->and($decoded['rows'][0]['id'])->toBe('1')
            ->and($decoded['rows'][0]['name'])->toBe('Item One')
            ->and($decoded['summary']['count'])->toBe(2);
    });

    it('includes metadata by default', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [new ColumnDefinition('id', 'ID')],
            rows: collect([]),
            summary: ['total' => 0],
            generatedAt: Carbon::now(),
        );

        $exporter = new JsonExporter;
        $json = $exporter->export($data);
        $decoded = json_decode($json, true);

        expect($decoded)->toHaveKey('title')
            ->toHaveKey('columns')
            ->toHaveKey('summary')
            ->toHaveKey('generated_at')
            ->toHaveKey('rows');
    });

    it('excludes metadata when configured', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [new ColumnDefinition('id', 'ID')],
            rows: collect([(object) ['id' => '1']]),
            summary: ['total' => 1],
            generatedAt: Carbon::now(),
        );

        $exporter = new JsonExporter(includeMetadata: false);
        $json = $exporter->export($data);
        $decoded = json_decode($json, true);

        expect($decoded)->not->toHaveKey('title')
            ->not->toHaveKey('columns')
            ->not->toHaveKey('summary')
            ->not->toHaveKey('generated_at')
            ->toHaveKey('rows');

        expect($decoded['rows'])->toHaveCount(1);
    });

    it('pretty prints when configured', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [],
            rows: collect([]),
            generatedAt: Carbon::now(),
        );

        $compactExporter = new JsonExporter(prettyPrint: false);
        $prettyExporter = new JsonExporter(prettyPrint: true);

        $compactJson = $compactExporter->export($data);
        $prettyJson = $prettyExporter->export($data);

        // Pretty printed should be longer due to whitespace
        expect(strlen($prettyJson))->toBeGreaterThan(strlen($compactJson))
            ->and($prettyJson)->toContain("\n");
    });

    it('handles empty rows', function (): void {
        $data = new ReportData(
            title: 'Empty Report',
            columns: [new ColumnDefinition('id', 'ID')],
            rows: collect([]),
        );

        $exporter = new JsonExporter;
        $json = $exporter->export($data);
        $decoded = json_decode($json, true);

        expect($decoded['rows'])->toBeArray()
            ->toBeEmpty();
    });

    it('converts row objects to arrays', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('id', 'ID'),
                new ColumnDefinition('data', 'Data'),
            ],
            rows: collect([
                (object) ['id' => '1', 'data' => ['nested' => 'value']],
            ]),
        );

        $exporter = new JsonExporter(includeMetadata: false);
        $json = $exporter->export($data);
        $decoded = json_decode($json, true);

        expect($decoded['rows'][0])->toBeArray()
            ->and($decoded['rows'][0]['id'])->toBe('1')
            ->and($decoded['rows'][0]['data'])->toBe(['nested' => 'value']);
    });

    it('includes column definitions with metadata', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('id', 'ID', ColumnDefinition::TYPE_STRING),
                new ColumnDefinition('amount', 'Amount', ColumnDefinition::TYPE_CURRENCY, true, '0.00'),
            ],
            rows: collect([]),
        );

        $exporter = new JsonExporter(includeMetadata: true);
        $json = $exporter->export($data);
        $decoded = json_decode($json, true);

        expect($decoded['columns'])->toHaveCount(2)
            ->and($decoded['columns'][0]['key'])->toBe('id')
            ->and($decoded['columns'][0]['label'])->toBe('ID')
            ->and($decoded['columns'][0]['type'])->toBe('string')
            ->and($decoded['columns'][1]['type'])->toBe('currency')
            ->and($decoded['columns'][1]['format'])->toBe('0.00');
    });

    it('handles null generated_at', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [],
            rows: collect([]),
        );

        $exporter = new JsonExporter;
        $json = $exporter->export($data);
        $decoded = json_decode($json, true);

        expect($decoded['generated_at'])->toBeNull();
    });

    it('produces valid JSON', function (): void {
        $data = new ReportData(
            title: 'Test with "quotes" and special chars',
            columns: [new ColumnDefinition('text', 'Text')],
            rows: collect([
                (object) ['text' => 'Line 1\nLine 2'],
                (object) ['text' => 'Tab\there'],
                (object) ['text' => 'Unicode: '],
            ]),
        );

        $exporter = new JsonExporter;
        $json = $exporter->export($data);

        // Should not throw
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded)->toBeArray();
    });

    it('returns correct content type', function (): void {
        $exporter = new JsonExporter;

        expect($exporter->getContentType())->toBe('application/json');
    });

    it('returns correct file extension', function (): void {
        $exporter = new JsonExporter;

        expect($exporter->getFileExtension())->toBe('json');
    });
});
