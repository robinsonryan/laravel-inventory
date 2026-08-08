<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Services;

use InvalidArgumentException;
use RobinsonRyan\Inventory\Reporting\Contracts\ExporterInterface;
use RobinsonRyan\Inventory\Reporting\Contracts\ReportInterface;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Exporters\AccountingExporter;
use RobinsonRyan\Inventory\Reporting\Exporters\CsvExporter;
use RobinsonRyan\Inventory\Reporting\Exporters\JsonExporter;
use RobinsonRyan\Inventory\Reporting\Reports\LowStockReport;
use RobinsonRyan\Inventory\Reporting\Reports\MovementHistoryReport;
use RobinsonRyan\Inventory\Reporting\Reports\PurchaseOrderReport;
use RobinsonRyan\Inventory\Reporting\Reports\ReservationReport;
use RobinsonRyan\Inventory\Reporting\Reports\StockValuationReport;
use RobinsonRyan\Inventory\Reporting\Reports\VarianceReport;

final class ReportService
{
    /**
     * @var array<string, class-string<ExporterInterface>>
     */
    private array $exporters = [
        'csv' => CsvExporter::class,
        'json' => JsonExporter::class,
        'iif' => AccountingExporter::class,
        'qif' => AccountingExporter::class,
    ];

    /**
     * @var array<string, class-string<ReportInterface>>
     */
    private array $reports = [
        'stock-valuation' => StockValuationReport::class,
        'movement-history' => MovementHistoryReport::class,
        'reservation' => ReservationReport::class,
        'variance' => VarianceReport::class,
        'low-stock' => LowStockReport::class,
        'purchase-order' => PurchaseOrderReport::class,
    ];

    /**
     * Generate report data from a report instance.
     */
    public function generate(ReportInterface $report): ReportData
    {
        return $report->generate();
    }

    /**
     * Export report data to a specific format.
     */
    public function export(ReportData $data, string $format = 'csv'): string
    {
        $exporter = $this->getExporter($format);

        return $exporter->export($data);
    }

    /**
     * Generate and export a report in one step.
     */
    public function generateAndExport(ReportInterface $report, string $format = 'csv'): string
    {
        $data = $this->generate($report);

        return $this->export($data, $format);
    }

    /**
     * Get available report types.
     *
     * @return array<string, array{name: string, description: string}>
     */
    public function getAvailableReports(): array
    {
        return [
            'stock-valuation' => [
                'name' => 'Stock Valuation Report',
                'description' => 'Current stock value by location and/or item',
            ],
            'movement-history' => [
                'name' => 'Movement History Report',
                'description' => 'Stock movement audit trail',
            ],
            'reservation' => [
                'name' => 'Reservation Report',
                'description' => 'Current reservations status',
            ],
            'variance' => [
                'name' => 'Variance Report',
                'description' => 'Cycle count variance summary',
            ],
            'low-stock' => [
                'name' => 'Low Stock Report',
                'description' => 'Items below reorder point',
            ],
            'purchase-order' => [
                'name' => 'Purchase Order Report',
                'description' => 'Purchase order summary',
            ],
        ];
    }

    /**
     * Get available export formats.
     *
     * @return array<string, array{name: string, content_type: string, extension: string}>
     */
    public function getAvailableFormats(): array
    {
        return [
            'csv' => [
                'name' => 'CSV',
                'content_type' => 'text/csv',
                'extension' => 'csv',
            ],
            'json' => [
                'name' => 'JSON',
                'content_type' => 'application/json',
                'extension' => 'json',
            ],
            'iif' => [
                'name' => 'QuickBooks IIF',
                'content_type' => 'application/x-iif',
                'extension' => 'iif',
            ],
            'qif' => [
                'name' => 'Quicken QIF',
                'content_type' => 'application/x-qif',
                'extension' => 'qif',
            ],
        ];
    }

    /**
     * Get an exporter instance for the given format.
     */
    public function getExporter(string $format): ExporterInterface
    {
        $format = strtolower($format);

        if (! isset($this->exporters[$format])) {
            throw new InvalidArgumentException("Unknown export format: {$format}");
        }

        $exporterClass = $this->exporters[$format];

        // Handle accounting formats that need configuration
        if ($format === 'iif' || $format === 'qif') {
            return new AccountingExporter($format);
        }

        return new $exporterClass;
    }

    /**
     * Register a custom exporter for a format.
     *
     * @param  class-string<ExporterInterface>  $exporterClass
     */
    public function registerExporter(string $format, string $exporterClass): void
    {
        $this->exporters[strtolower($format)] = $exporterClass;
    }

    /**
     * Register a custom report type.
     *
     * @param  class-string<ReportInterface>  $reportClass
     */
    public function registerReport(string $key, string $reportClass): void
    {
        $this->reports[$key] = $reportClass;
    }

    /**
     * Resolve the class registered for a report key.
     *
     * @return class-string<ReportInterface>
     */
    public function getReportClass(string $key): string
    {
        if (! isset($this->reports[$key])) {
            throw new InvalidArgumentException("Unknown report type: {$key}");
        }

        return $this->reports[$key];
    }

    /**
     * Check if a report type is registered.
     */
    public function supportsReport(string $key): bool
    {
        return isset($this->reports[$key]);
    }

    /**
     * Check if a format is supported.
     */
    public function supportsFormat(string $format): bool
    {
        return isset($this->exporters[strtolower($format)]);
    }

    /**
     * Get the content type for a format.
     */
    public function getContentType(string $format): string
    {
        return $this->getExporter($format)->getContentType();
    }

    /**
     * Get the file extension for a format.
     */
    public function getFileExtension(string $format): string
    {
        return $this->getExporter($format)->getFileExtension();
    }
}
