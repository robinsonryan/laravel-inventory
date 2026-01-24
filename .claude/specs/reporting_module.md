# Reporting Module Specification

## Overview

The Reporting module provides inventory reports and data export capabilities. It includes stock valuation, movement history, reservation status, and variance reports with CSV and JSON export options.

## Reports

### StockValuationReport

Current stock value by location and/or item.

```php
namespace RobinsonRyan\Inventory\Reporting\Reports;

final class StockValuationReport implements ReportInterface
{
    public function __construct(
        public ?string $locationId = null,
        public ?string $itemId = null,
        public ?string $category = null,
    ) {}

    public function generate(): ReportData;

    // Output columns:
    // - item_id, item_sku, item_name, category
    // - location_id, location_code, location_name
    // - quantity, unit_cost, total_value
    // - reserved_quantity, available_quantity
}
```

### MovementHistoryReport

Stock movement audit trail.

```php
namespace RobinsonRyan\Inventory\Reporting\Reports;

final class MovementHistoryReport implements ReportInterface
{
    public function __construct(
        public ?Carbon $fromDate = null,
        public ?Carbon $toDate = null,
        public ?string $itemId = null,
        public ?string $locationId = null,
        public ?string $movementType = null,
        public ?string $referenceType = null,
        public ?string $referenceId = null,
    ) {}

    public function generate(): ReportData;

    // Output columns:
    // - movement_id, created_at, type
    // - item_id, item_sku, item_name
    // - from_location, to_location
    // - quantity, cost
    // - reference_type, reference_id
    // - actor_type, actor_id
    // - notes
}
```

### ReservationReport

Current reservations status.

```php
namespace RobinsonRyan\Inventory\Reporting\Reports;

final class ReservationReport implements ReportInterface
{
    public function __construct(
        public ?string $status = null,
        public ?string $jobType = null,
        public ?string $itemId = null,
        public bool $includeExpired = false,
    ) {}

    public function generate(): ReportData;

    // Output columns:
    // - reservation_id, status, reserved_at, expires_at
    // - item_id, item_sku, item_name
    // - quantity, job_type, job_id, line_id
    // - location_id, location_code
    // - fulfilled_at, cancelled_at
}
```

### VarianceReport

Cycle count variance summary.

```php
namespace RobinsonRyan\Inventory\Reporting\Reports;

final class VarianceReport implements ReportInterface
{
    public function __construct(
        public ?Carbon $fromDate = null,
        public ?Carbon $toDate = null,
        public ?string $locationId = null,
        public ?string $itemId = null,
    ) {}

    public function generate(): ReportData;

    // Output columns:
    // - cycle_count_id, count_number, approved_at
    // - item_id, item_sku, item_name
    // - location_id, location_code
    // - expected_quantity, counted_quantity, variance
    // - cost_impact, reason
}
```

### LowStockReport

Items below reorder point.

```php
namespace RobinsonRyan\Inventory\Reporting\Reports;

final class LowStockReport implements ReportInterface
{
    public function __construct(
        public ?string $category = null,
        public bool $includeBelowMin = true,
        public bool $includeZero = true,
    ) {}

    public function generate(): ReportData;

    // Output columns:
    // - item_id, item_sku, item_name, category
    // - min_stock_level, current_quantity, available_quantity
    // - shortfall (min - available)
    // - average_daily_usage, days_of_stock
}
```

### PurchaseOrderReport

Purchase order summary.

```php
namespace RobinsonRyan\Inventory\Reporting\Reports;

final class PurchaseOrderReport implements ReportInterface
{
    public function __construct(
        public ?Carbon $fromDate = null,
        public ?Carbon $toDate = null,
        public ?string $vendorId = null,
        public ?string $status = null,
    ) {}

    public function generate(): ReportData;

    // Output columns:
    // - po_id, po_number, status
    // - vendor_id, vendor_name
    // - order_date, expected_date
    // - line_count, total
    // - received_value, pending_value
}
```

## Interfaces

### ReportInterface

```php
namespace RobinsonRyan\Inventory\Reporting\Contracts;

interface ReportInterface
{
    public function generate(): ReportData;
    public function getTitle(): string;
    public function getColumns(): array;
}
```

### ExporterInterface

```php
namespace RobinsonRyan\Inventory\Reporting\Contracts;

interface ExporterInterface
{
    public function export(ReportData $data): string;
    public function getContentType(): string;
    public function getFileExtension(): string;
}
```

## Data Objects

### ReportData

```php
namespace RobinsonRyan\Inventory\Reporting\Data;

final readonly class ReportData
{
    public function __construct(
        public string $title,
        public array $columns,
        public Collection $rows,
        public array $summary = [],
        public ?Carbon $generatedAt = null,
    ) {}

    public function getColumn(string $key): ?array;
    public function getSummary(string $key): mixed;
    public function toArray(): array;
}
```

### ColumnDefinition

```php
namespace RobinsonRyan\Inventory\Reporting\Data;

final readonly class ColumnDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'string', // string, number, currency, date, datetime
        public bool $sortable = true,
        public ?string $format = null,
    ) {}
}
```

## Exporters

### CsvExporter

```php
namespace RobinsonRyan\Inventory\Reporting\Exporters;

final class CsvExporter implements ExporterInterface
{
    public function export(ReportData $data): string;
    public function getContentType(): string; // 'text/csv'
    public function getFileExtension(): string; // 'csv'
}
```

### JsonExporter

```php
namespace RobinsonRyan\Inventory\Reporting\Exporters;

final class JsonExporter implements ExporterInterface
{
    public function export(ReportData $data): string;
    public function getContentType(): string; // 'application/json'
    public function getFileExtension(): string; // 'json'
}
```

### AccountingExporter

Export for accounting system integration (QuickBooks, etc.).

```php
namespace RobinsonRyan\Inventory\Reporting\Exporters;

final class AccountingExporter implements ExporterInterface
{
    public function __construct(
        public string $format = 'iif' // QuickBooks IIF format
    ) {}

    public function export(ReportData $data): string;
    public function getContentType(): string;
    public function getFileExtension(): string;
}
```

## Services

### ReportService

```php
namespace RobinsonRyan\Inventory\Reporting\Services;

final class ReportService
{
    // Generate and optionally export a report
    public function generate(ReportInterface $report): ReportData;

    // Export report data
    public function export(
        ReportData $data,
        string $format = 'csv'
    ): string;

    // Get available report types
    public function getAvailableReports(): array;

    // Get available export formats
    public function getAvailableFormats(): array;
}
```

## Tests

### Feature Tests

- `tests/Feature/Reporting/StockValuationReportTest.php`
  - it generates stock valuation report
  - it filters by location
  - it filters by item category
  - it calculates totals correctly

- `tests/Feature/Reporting/MovementHistoryReportTest.php`
  - it generates movement history
  - it filters by date range
  - it filters by movement type
  - it includes actor information

- `tests/Feature/Reporting/ExporterTest.php`
  - it exports to CSV format
  - it exports to JSON format
  - it handles empty reports
  - it formats currency values

### Unit Tests

- `tests/Unit/Reporting/ReportDataTest.php`
- `tests/Unit/Reporting/CsvExporterTest.php`
- `tests/Unit/Reporting/JsonExporterTest.php`

## Directory Structure

```
src/Reporting/
├── Contracts/
│   ├── ReportInterface.php
│   └── ExporterInterface.php
├── Reports/
│   ├── StockValuationReport.php
│   ├── MovementHistoryReport.php
│   ├── ReservationReport.php
│   ├── VarianceReport.php
│   ├── LowStockReport.php
│   └── PurchaseOrderReport.php
├── Exporters/
│   ├── CsvExporter.php
│   ├── JsonExporter.php
│   └── AccountingExporter.php
├── Services/
│   └── ReportService.php
└── Data/
    ├── ReportData.php
    └── ColumnDefinition.php

tests/Feature/Reporting/
├── StockValuationReportTest.php
├── MovementHistoryReportTest.php
├── ReservationReportTest.php
├── VarianceReportTest.php
├── LowStockReportTest.php
├── PurchaseOrderReportTest.php
└── ExporterTest.php

tests/Unit/Reporting/
├── ReportDataTest.php
├── CsvExporterTest.php
└── JsonExporterTest.php
```

## Usage Examples

### Generate and Export Report

```php
$report = new StockValuationReport(
    locationId: 'loc-123',
    category: 'part'
);

$service = app(ReportService::class);
$data = $service->generate($report);

// Export to CSV
$csv = $service->export($data, 'csv');

// Or download
return response($csv, 200, [
    'Content-Type' => 'text/csv',
    'Content-Disposition' => 'attachment; filename="stock-valuation.csv"',
]);
```

### Generate Report with Date Range

```php
$report = new MovementHistoryReport(
    fromDate: now()->subMonth(),
    toDate: now(),
    movementType: 'consumption',
);

$data = $service->generate($report);
```
