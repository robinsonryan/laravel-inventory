<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Exporters;

use RobinsonRyan\Inventory\Reporting\Contracts\ExporterInterface;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;

final class AccountingExporter implements ExporterInterface
{
    public const FORMAT_IIF = 'iif';

    public const FORMAT_QIF = 'qif';

    public function __construct(
        public string $format = self::FORMAT_IIF,
    ) {}

    public function export(ReportData $data): string
    {
        return match ($this->format) {
            self::FORMAT_IIF => $this->exportIif($data),
            self::FORMAT_QIF => $this->exportQif($data),
            default => throw new \InvalidArgumentException("Unsupported accounting format: {$this->format}"),
        };
    }

    public function getContentType(): string
    {
        return match ($this->format) {
            self::FORMAT_IIF => 'application/x-iif',
            self::FORMAT_QIF => 'application/x-qif',
            default => 'text/plain',
        };
    }

    public function getFileExtension(): string
    {
        return $this->format;
    }

    /**
     * Export to QuickBooks IIF (Intuit Interchange Format).
     */
    private function exportIif(ReportData $data): string
    {
        $lines = [];

        // IIF header
        $lines[] = '!TRNS\tTRNSTYPE\tDATE\tACCNT\tNAME\tAMOUNT\tMEMO';
        $lines[] = '!SPL\tTRNSTYPE\tDATE\tACCNT\tNAME\tAMOUNT\tMEMO';
        $lines[] = '!ENDTRNS';

        // Determine if this is a stock valuation report for proper formatting
        $hasValue = $this->hasColumn($data, 'total_value');
        $hasQuantity = $this->hasColumn($data, 'quantity');

        foreach ($data->rows as $row) {
            $rowArray = (array) $row;

            // Get identifiers
            $itemName = $rowArray['item_name'] ?? $rowArray['item_sku'] ?? 'Unknown Item';
            $date = $this->extractDate($rowArray);
            $amount = $this->extractAmount($rowArray);
            $memo = $this->buildMemo($rowArray);

            // Main transaction line
            $lines[] = sprintf(
                "TRNS\tINVENTORY\t%s\tInventory Asset\t%s\t%.2f\t%s",
                $date,
                $this->escapeIifValue($itemName),
                $amount,
                $this->escapeIifValue($memo)
            );

            // Split line (offset)
            $lines[] = sprintf(
                "SPL\tINVENTORY\t%s\tCost of Goods Sold\t%s\t%.2f\t%s",
                $date,
                $this->escapeIifValue($itemName),
                -$amount,
                $this->escapeIifValue($memo)
            );

            $lines[] = 'ENDTRNS';
        }

        return implode("\r\n", $lines);
    }

    /**
     * Export to Quicken QIF format.
     */
    private function exportQif(ReportData $data): string
    {
        $lines = [];

        // QIF header for asset account
        $lines[] = '!Type:Oth A';

        foreach ($data->rows as $row) {
            $rowArray = (array) $row;

            $date = $this->extractDate($rowArray);
            $amount = $this->extractAmount($rowArray);
            $payee = $rowArray['item_name'] ?? $rowArray['item_sku'] ?? 'Inventory';
            $memo = $this->buildMemo($rowArray);

            // Date
            $lines[] = 'D' . $date;
            // Amount
            $lines[] = 'T' . number_format($amount, 2, '.', '');
            // Payee
            $lines[] = 'P' . $payee;
            // Memo
            if ($memo !== '') {
                $lines[] = 'M' . $memo;
            }
            // End of record
            $lines[] = '^';
        }

        return implode("\r\n", $lines);
    }

    /**
     * Check if the report has a specific column.
     */
    private function hasColumn(ReportData $data, string $key): bool
    {
        foreach ($data->columns as $column) {
            if ($column->key === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract date from row data.
     */
    private function extractDate(array $row): string
    {
        $dateFields = ['order_date', 'created_at', 'approved_at', 'reserved_at'];

        foreach ($dateFields as $field) {
            if (isset($row[$field]) && $row[$field] !== null) {
                $value = $row[$field];

                if ($value instanceof \DateTimeInterface) {
                    return $value->format('m/d/Y');
                }

                try {
                    return (new \DateTime((string) $value))->format('m/d/Y');
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return date('m/d/Y');
    }

    /**
     * Extract amount from row data.
     */
    private function extractAmount(array $row): float
    {
        $amountFields = ['total_value', 'total', 'cost_impact', 'amount', 'value'];

        foreach ($amountFields as $field) {
            if (isset($row[$field]) && is_numeric($row[$field])) {
                return (float) $row[$field];
            }
        }

        // If we have quantity and unit_cost, calculate
        if (isset($row['quantity'], $row['unit_cost']) && is_numeric($row['quantity']) && is_numeric($row['unit_cost'])) {
            return (float) $row['quantity'] * (float) $row['unit_cost'];
        }

        return 0.0;
    }

    /**
     * Build a memo string from row data.
     */
    private function buildMemo(array $row): string
    {
        $parts = [];

        if (isset($row['item_sku'])) {
            $parts[] = 'SKU: ' . $row['item_sku'];
        }

        if (isset($row['location_code'])) {
            $parts[] = 'Location: ' . $row['location_code'];
        }

        if (isset($row['quantity'])) {
            $parts[] = 'Qty: ' . $row['quantity'];
        }

        if (isset($row['notes']) && $row['notes'] !== null && $row['notes'] !== '') {
            $parts[] = $row['notes'];
        }

        return implode('; ', $parts);
    }

    /**
     * Escape a value for IIF format (tab-separated).
     */
    private function escapeIifValue(string $value): string
    {
        // Remove tabs and newlines
        return str_replace(["\t", "\r", "\n"], ' ', $value);
    }
}
