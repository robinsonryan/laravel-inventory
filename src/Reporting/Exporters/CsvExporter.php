<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Exporters;

use DateTime;
use DateTimeInterface;
use Exception;
use RobinsonRyan\Inventory\Reporting\Contracts\ExporterInterface;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RuntimeException;

final readonly class CsvExporter implements ExporterInterface
{
    public function __construct(
        private string $delimiter = ',',
        private string $enclosure = '"',
        private bool $includeHeaders = true,
    ) {}

    public function export(ReportData $data): string
    {
        $output = fopen('php://temp', 'r+');

        if ($output === false) {
            throw new RuntimeException('Unable to create temporary file for CSV export');
        }

        // Write header row
        if ($this->includeHeaders) {
            fputcsv($output, $data->getColumnLabels(), $this->delimiter, $this->enclosure);
        }

        // Get column keys for consistent ordering
        $columnKeys = $data->getColumnKeys();
        $columnTypes = $this->getColumnTypes($data);

        // Write data rows
        foreach ($data->rows as $row) {
            $rowArray = (array) $row;
            $formattedRow = [];

            foreach ($columnKeys as $key) {
                $value = $rowArray[$key] ?? '';
                $formattedRow[] = $this->formatValue($value, $columnTypes[$key] ?? ColumnDefinition::TYPE_STRING);
            }

            fputcsv($output, $formattedRow, $this->delimiter, $this->enclosure);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv !== false ? $csv : '';
    }

    public function getContentType(): string
    {
        return 'text/csv';
    }

    public function getFileExtension(): string
    {
        return 'csv';
    }

    /**
     * Get column types indexed by key.
     *
     * @return array<string, string>
     */
    private function getColumnTypes(ReportData $data): array
    {
        $types = [];
        foreach ($data->columns as $column) {
            $types[$column->key] = $column->type;
        }

        return $types;
    }

    /**
     * Format a value based on its column type.
     */
    private function formatValue(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            ColumnDefinition::TYPE_CURRENCY => $this->formatCurrency($value),
            ColumnDefinition::TYPE_NUMBER => $this->formatNumber($value),
            ColumnDefinition::TYPE_DATE => $this->formatDate($value),
            ColumnDefinition::TYPE_DATETIME => $this->formatDatetime($value),
            default => (string) $value,
        };
    }

    private function formatCurrency(mixed $value): string
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function formatNumber(mixed $value): string
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }

        // Preserve precision for numbers
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function formatDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && $value !== '') {
            try {
                return (new DateTime($value))->format('Y-m-d');
            } catch (Exception) {
                return $value;
            }
        }

        return (string) $value;
    }

    private function formatDatetime(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value) && $value !== '') {
            try {
                return (new DateTime($value))->format('Y-m-d H:i:s');
            } catch (Exception) {
                return $value;
            }
        }

        return (string) $value;
    }
}
