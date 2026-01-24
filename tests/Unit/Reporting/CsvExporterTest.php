<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Exporters\CsvExporter;

describe('CsvExporter', function () {
    it('exports basic data to CSV', function () {
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
        );

        $exporter = new CsvExporter();
        $csv = $exporter->export($data);

        $lines = explode("\n", trim($csv));

        expect($lines)->toHaveCount(3)
            ->and($lines[0])->toBe('ID,Name')
            ->and($lines[1])->toBe('1,"Item One"')
            ->and($lines[2])->toBe('2,"Item Two"');
    });

    it('exports without headers when configured', function () {
        $data = new ReportData(
            title: 'Test',
            columns: [new ColumnDefinition('id', 'ID')],
            rows: collect([
                (object) ['id' => '1'],
            ]),
        );

        $exporter = new CsvExporter(includeHeaders: false);
        $csv = $exporter->export($data);

        expect(trim($csv))->toBe('1');
    });

    it('uses custom delimiter', function () {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('id', 'ID'),
                new ColumnDefinition('name', 'Name'),
            ],
            rows: collect([
                (object) ['id' => '1', 'name' => 'Test'],
            ]),
        );

        $exporter = new CsvExporter(delimiter: ';');
        $csv = $exporter->export($data);

        expect($csv)->toContain('ID;Name')
            ->toContain('1;Test');
    });

    it('formats currency values', function () {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('amount', 'Amount', ColumnDefinition::TYPE_CURRENCY),
            ],
            rows: collect([
                (object) ['amount' => 1234.5],
                (object) ['amount' => 99.999],
                (object) ['amount' => 0],
            ]),
        );

        $exporter = new CsvExporter();
        $csv = $exporter->export($data);

        expect($csv)->toContain('1234.50')
            ->toContain('100.00')
            ->toContain('0.00');
    });

    it('formats number values', function () {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('qty', 'Quantity', ColumnDefinition::TYPE_NUMBER),
            ],
            rows: collect([
                (object) ['qty' => 100.0],
                (object) ['qty' => 50.5],
                (object) ['qty' => 25.1234],
            ]),
        );

        $exporter = new CsvExporter();
        $csv = $exporter->export($data);

        expect($csv)->toContain('100')
            ->toContain('50.5')
            ->toContain('25.1234');
    });

    it('formats date values', function () {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('date', 'Date', ColumnDefinition::TYPE_DATE),
            ],
            rows: collect([
                (object) ['date' => Carbon::parse('2026-01-15 14:30:00')],
                (object) ['date' => '2026-02-20 10:00:00'],
            ]),
        );

        $exporter = new CsvExporter();
        $csv = $exporter->export($data);

        expect($csv)->toContain('2026-01-15')
            ->toContain('2026-02-20');
    });

    it('formats datetime values', function () {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('timestamp', 'Timestamp', ColumnDefinition::TYPE_DATETIME),
            ],
            rows: collect([
                (object) ['timestamp' => Carbon::parse('2026-01-15 14:30:00')],
                (object) ['timestamp' => '2026-02-20 10:15:30'],
            ]),
        );

        $exporter = new CsvExporter();
        $csv = $exporter->export($data);

        expect($csv)->toContain('2026-01-15 14:30:00')
            ->toContain('2026-02-20 10:15:30');
    });

    it('handles null values', function () {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('id', 'ID'),
                new ColumnDefinition('optional', 'Optional'),
            ],
            rows: collect([
                (object) ['id' => '1', 'optional' => null],
                (object) ['id' => '2', 'optional' => 'value'],
            ]),
        );

        $exporter = new CsvExporter();
        $csv = $exporter->export($data);

        $lines = explode("\n", trim($csv));

        expect($lines[1])->toBe('1,')
            ->and($lines[2])->toBe('2,value');
    });

    it('handles missing columns in rows', function () {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('id', 'ID'),
                new ColumnDefinition('missing', 'Missing Column'),
            ],
            rows: collect([
                (object) ['id' => '1'], // missing 'missing' key
            ]),
        );

        $exporter = new CsvExporter();
        $csv = $exporter->export($data);

        $lines = explode("\n", trim($csv));

        expect($lines[1])->toBe('1,');
    });

    it('escapes special characters', function () {
        $data = new ReportData(
            title: 'Test',
            columns: [
                new ColumnDefinition('text', 'Text'),
            ],
            rows: collect([
                (object) ['text' => 'Contains "quotes"'],
                (object) ['text' => 'Contains, comma'],
                (object) ['text' => "Contains\nnewline"],
            ]),
        );

        $exporter = new CsvExporter();
        $csv = $exporter->export($data);

        expect($csv)->toContain('"Contains ""quotes"""')
            ->toContain('"Contains, comma"');
    });

    it('returns correct content type', function () {
        $exporter = new CsvExporter();

        expect($exporter->getContentType())->toBe('text/csv');
    });

    it('returns correct file extension', function () {
        $exporter = new CsvExporter();

        expect($exporter->getFileExtension())->toBe('csv');
    });
});
