<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Exporters\AccountingExporter;
use RobinsonRyan\Inventory\Reporting\Exporters\CsvExporter;
use RobinsonRyan\Inventory\Reporting\Exporters\JsonExporter;
use RobinsonRyan\Inventory\Reporting\Reports\StockValuationReport;
use RobinsonRyan\Inventory\Reporting\Services\ReportService;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;

beforeEach(function (): void {
    $this->reportService = App::make(ReportService::class);
});

describe('CsvExporter', function (): void {
    it('exports to CSV format', function (): void {
        $location = Location::factory()->warehouse()->create(['code' => 'WH1', 'name' => 'Warehouse 1']);
        $item = Item::factory()->create(['sku' => 'TEST-001', 'name' => 'Test Item']);

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($location)
            ->withQuantity(100)
            ->withCost(10.00)
            ->create();

        $report = new StockValuationReport;
        $data = $this->reportService->generate($report);
        $csv = $this->reportService->export($data, 'csv');

        expect($csv)->toBeString()
            // fputcsv() encloses any field containing a space, so the two-word
            // headings arrive quoted.
            ->toContain('"Item ID",SKU,"Item Name"')
            ->toContain('TEST-001')
            ->toContain('Test Item')
            ->toContain('100');
    });

    it('handles empty reports', function (): void {
        $report = new StockValuationReport;
        $data = $this->reportService->generate($report);
        $csv = $this->reportService->export($data, 'csv');

        // Should only have headers
        expect($csv)->toBeString()
            // fputcsv() encloses any field containing a space, so the two-word
            // headings arrive quoted.
            ->toContain('"Item ID",SKU,"Item Name"');

        $lines = explode("\n", trim($csv));
        expect(count($lines))->toBe(1); // Just header row
    });

    it('formats currency values', function (): void {
        $data = new ReportData(
            title: 'Test Report',
            columns: [
                new ColumnDefinition('amount', 'Amount', ColumnDefinition::TYPE_CURRENCY),
            ],
            rows: collect([
                (object) ['amount' => 1234.567],
                (object) ['amount' => 99.9],
            ]),
            generatedAt: Carbon::now(),
        );

        $exporter = new CsvExporter;
        $csv = $exporter->export($data);

        expect($csv)->toContain('1234.57')
            ->toContain('99.90');
    });

    it('formats date values', function (): void {
        $data = new ReportData(
            title: 'Test Report',
            columns: [
                new ColumnDefinition('date', 'Date', ColumnDefinition::TYPE_DATE),
                new ColumnDefinition('datetime', 'DateTime', ColumnDefinition::TYPE_DATETIME),
            ],
            rows: collect([
                (object) [
                    'date' => '2026-01-15 14:30:00',
                    'datetime' => '2026-01-15 14:30:00',
                ],
            ]),
            generatedAt: Carbon::now(),
        );

        $exporter = new CsvExporter;
        $csv = $exporter->export($data);

        expect($csv)->toContain('2026-01-15')
            ->toContain('2026-01-15 14:30:00');
    });

    it('handles null values', function (): void {
        $data = new ReportData(
            title: 'Test Report',
            columns: [
                new ColumnDefinition('name', 'Name'),
                new ColumnDefinition('value', 'Value'),
            ],
            rows: collect([
                (object) ['name' => 'Test', 'value' => null],
            ]),
            generatedAt: Carbon::now(),
        );

        $exporter = new CsvExporter;
        $csv = $exporter->export($data);

        // Should have empty value for null
        expect($csv)->toContain('Test,');
    });

    it('uses correct content type and extension', function (): void {
        $exporter = new CsvExporter;

        expect($exporter->getContentType())->toBe('text/csv')
            ->and($exporter->getFileExtension())->toBe('csv');
    });
});

describe('JsonExporter', function (): void {
    it('exports to JSON format', function (): void {
        $location = Location::factory()->warehouse()->create(['code' => 'WH1']);
        $item = Item::factory()->create(['sku' => 'TEST-001']);

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($location)
            ->withQuantity(100)
            ->withCost(10.00)
            ->create();

        $report = new StockValuationReport;
        $data = $this->reportService->generate($report);
        $json = $this->reportService->export($data, 'json');

        $decoded = json_decode($json, true);

        expect($decoded)->toBeArray()
            ->toHaveKey('title')
            ->toHaveKey('rows')
            ->toHaveKey('columns')
            ->toHaveKey('summary');

        expect($decoded['title'])->toBe('Stock Valuation Report')
            ->and($decoded['rows'])->toHaveCount(1)
            ->and($decoded['rows'][0]['item_sku'])->toBe('TEST-001');
    });

    it('handles empty reports', function (): void {
        $report = new StockValuationReport;
        $data = $this->reportService->generate($report);
        $json = $this->reportService->export($data, 'json');

        $decoded = json_decode($json, true);

        expect($decoded['rows'])->toBeArray()
            ->toBeEmpty();
    });

    it('carries the stable value of a tag field that the spreadsheet leaves out', function (): void {
        $location = Location::factory()->warehouse()->create();
        $item = Item::factory()->part()->create(['sku' => 'TEST-001']);

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($location)
            ->withQuantity(100)
            ->create();

        $report = new StockValuationReport;
        $data = $this->reportService->generate($report);

        $decoded = json_decode($this->reportService->export($data, 'json'), true);
        $csv = $this->reportService->export($data, 'csv');

        // The feed gets the wording and the slug; the spreadsheet gets only the
        // wording, under its ordinary heading. An integration keys off the slug
        // so that rewording the label cannot break it.
        expect($decoded['rows'][0]['category_label'])->toBe('Part')
            ->and($decoded['rows'][0]['category_value'])->toBe('part');

        expect(array_column($decoded['columns'], 'key'))->not->toContain('category_value');
        expect($csv)->toContain('Category')
            ->not->toContain('category_value');
    });

    it('includes metadata when requested', function (): void {
        $data = new ReportData(
            title: 'Test Report',
            columns: [new ColumnDefinition('id', 'ID')],
            rows: collect([]),
            summary: ['total' => 0],
            generatedAt: Carbon::parse('2026-01-15 10:00:00'),
        );

        $exporter = new JsonExporter(includeMetadata: true);
        $json = $exporter->export($data);
        $decoded = json_decode($json, true);

        expect($decoded)->toHaveKey('title')
            ->toHaveKey('columns')
            ->toHaveKey('summary')
            ->toHaveKey('generated_at');
    });

    it('excludes metadata when not requested', function (): void {
        $data = new ReportData(
            title: 'Test Report',
            columns: [new ColumnDefinition('id', 'ID')],
            rows: collect([]),
            generatedAt: Carbon::now(),
        );

        $exporter = new JsonExporter(includeMetadata: false);
        $json = $exporter->export($data);
        $decoded = json_decode($json, true);

        expect($decoded)->not->toHaveKey('title')
            ->not->toHaveKey('columns')
            ->toHaveKey('rows');
    });

    it('supports pretty print', function (): void {
        $data = new ReportData(
            title: 'Test Report',
            columns: [new ColumnDefinition('id', 'ID')],
            rows: collect([]),
            generatedAt: Carbon::now(),
        );

        $exporter = new JsonExporter(prettyPrint: true);
        $json = $exporter->export($data);

        // Pretty printed JSON has newlines
        expect($json)->toContain("\n");
    });

    it('uses correct content type and extension', function (): void {
        $exporter = new JsonExporter;

        expect($exporter->getContentType())->toBe('application/json')
            ->and($exporter->getFileExtension())->toBe('json');
    });
});

describe('AccountingExporter', function (): void {
    it('exports to IIF format', function (): void {
        $data = new ReportData(
            title: 'Stock Valuation',
            columns: [
                new ColumnDefinition('item_name', 'Item Name'),
                new ColumnDefinition('total_value', 'Total Value', ColumnDefinition::TYPE_CURRENCY),
            ],
            rows: collect([
                (object) [
                    'item_name' => 'Test Item',
                    'item_sku' => 'TEST-001',
                    'total_value' => 1000.00,
                    'quantity' => 100,
                ],
            ]),
            generatedAt: Carbon::now(),
        );

        $exporter = new AccountingExporter(AccountingExporter::FORMAT_IIF);
        $output = $exporter->export($data);

        expect($output)->toContain('!TRNS')
            ->toContain('!SPL')
            ->toContain('!ENDTRNS')
            ->toContain('INVENTORY')
            ->toContain('Test Item')
            ->toContain('1000.00');
    });

    it('separates every IIF field with a real tab', function (): void {
        // IIF is a tab-separated format and QuickBooks rejects a file that is
        // not. A backslash and a "t" look close enough in a diff to survive
        // review, so the header is asserted character for character.
        $data = new ReportData(
            title: 'Stock Valuation',
            columns: [new ColumnDefinition('item_name', 'Item Name')],
            rows: collect([
                (object) [
                    'item_name' => 'Test Item',
                    'item_sku' => 'TEST-001',
                    'total_value' => 1000.00,
                    'quantity' => 100,
                ],
            ]),
            generatedAt: Carbon::now(),
        );

        $lines = explode("\r\n", (new AccountingExporter(AccountingExporter::FORMAT_IIF))->export($data));

        expect($lines[0])->toBe("!TRNS\tTRNSTYPE\tDATE\tACCNT\tNAME\tAMOUNT\tMEMO")
            ->and($lines[1])->toBe("!SPL\tTRNSTYPE\tDATE\tACCNT\tNAME\tAMOUNT\tMEMO")
            ->and(explode("\t", $lines[3]))->toHaveCount(7)
            ->and(explode("\t", $lines[4]))->toHaveCount(7);
    });

    it('exports to QIF format', function (): void {
        $data = new ReportData(
            title: 'Stock Valuation',
            columns: [
                new ColumnDefinition('item_name', 'Item Name'),
                new ColumnDefinition('total_value', 'Total Value', ColumnDefinition::TYPE_CURRENCY),
            ],
            rows: collect([
                (object) [
                    'item_name' => 'Test Item',
                    'total_value' => 500.00,
                ],
            ]),
            generatedAt: Carbon::now(),
        );

        $exporter = new AccountingExporter(AccountingExporter::FORMAT_QIF);
        $output = $exporter->export($data);

        expect($output)->toContain('!Type:Oth A')
            ->toContain('D')
            ->toContain('T500.00')
            ->toContain('PTest Item')
            ->toContain('^');
    });

    it('uses correct content type for IIF', function (): void {
        $exporter = new AccountingExporter(AccountingExporter::FORMAT_IIF);

        expect($exporter->getContentType())->toBe('application/x-iif')
            ->and($exporter->getFileExtension())->toBe('iif');
    });

    it('uses correct content type for QIF', function (): void {
        $exporter = new AccountingExporter(AccountingExporter::FORMAT_QIF);

        expect($exporter->getContentType())->toBe('application/x-qif')
            ->and($exporter->getFileExtension())->toBe('qif');
    });

    it('handles empty reports', function (): void {
        $data = new ReportData(
            title: 'Empty Report',
            columns: [new ColumnDefinition('id', 'ID')],
            rows: collect([]),
            generatedAt: Carbon::now(),
        );

        $exporter = new AccountingExporter(AccountingExporter::FORMAT_IIF);
        $output = $exporter->export($data);

        // An IIF file opens with three lines declaring the record format, and
        // `!ENDTRNS` is one of them — the bang marks it as part of the
        // declaration, not as the end of a transaction. With no rows there is
        // no transaction, so no bare ENDTRNS line may follow.
        $lines = explode("\r\n", $output);

        expect($output)->toContain('!TRNS');
        expect($lines)->toHaveCount(3)
            ->not->toContain('ENDTRNS');
    });
});

describe('ReportService', function (): void {
    it('generates and exports in one step', function (): void {
        $location = Location::factory()->warehouse()->create();
        $item = Item::factory()->create(['sku' => 'TEST-001']);

        Stock::factory()
            ->forItem($item->id)
            ->forLocation($location)
            ->withQuantity(50)
            ->create();

        $report = new StockValuationReport;
        $csv = $this->reportService->generateAndExport($report, 'csv');

        expect($csv)->toBeString()
            ->toContain('TEST-001');
    });

    it('returns available formats', function (): void {
        $formats = $this->reportService->getAvailableFormats();

        expect($formats)->toBeArray()
            ->toHaveKey('csv')
            ->toHaveKey('json')
            ->toHaveKey('iif')
            ->toHaveKey('qif');

        expect($formats['csv']['content_type'])->toBe('text/csv');
    });

    it('returns available reports', function (): void {
        $reports = $this->reportService->getAvailableReports();

        expect($reports)->toBeArray()
            ->toHaveKey('stock-valuation')
            ->toHaveKey('movement-history')
            ->toHaveKey('reservation')
            ->toHaveKey('variance')
            ->toHaveKey('low-stock')
            ->toHaveKey('purchase-order');
    });

    it('checks if format is supported', function (): void {
        expect($this->reportService->supportsFormat('csv'))->toBeTrue()
            ->and($this->reportService->supportsFormat('json'))->toBeTrue()
            ->and($this->reportService->supportsFormat('unknown'))->toBeFalse();
    });

    it('throws exception for unknown format', function (): void {
        $data = new ReportData(
            title: 'Test',
            columns: [],
            rows: collect([]),
        );

        expect(fn () => $this->reportService->export($data, 'unknown'))
            ->toThrow(InvalidArgumentException::class);
    });
});
