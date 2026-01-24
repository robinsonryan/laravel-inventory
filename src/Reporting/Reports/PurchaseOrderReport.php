<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Reporting\Contracts\ReportInterface;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;

final class PurchaseOrderReport implements ReportInterface
{
    public function __construct(
        public ?Carbon $fromDate = null,
        public ?Carbon $toDate = null,
        public ?string $vendorId = null,
        public ?string $status = null,
    ) {}

    public function generate(): ReportData
    {
        $purchaseOrdersTable = config('inventory.tables.purchase_orders', 'purchase_orders');
        $purchaseOrderLinesTable = config('inventory.tables.purchase_order_lines', 'purchase_order_lines');
        $receiptsTable = config('inventory.tables.receipts', 'receipts');
        $receiptLinesTable = config('inventory.tables.receipt_lines', 'receipt_lines');

        // Subquery to get received value per PO
        $receivedSubquery = DB::table($receiptLinesTable)
            ->join($receiptsTable, "{$receiptLinesTable}.receipt_id", '=', "{$receiptsTable}.id")
            ->select([
                "{$receiptsTable}.purchase_order_id",
                DB::raw("CAST(SUM({$receiptLinesTable}.received_quantity * {$receiptLinesTable}.unit_cost) AS DECIMAL(15,2)) as received_value"),
            ])
            ->groupBy("{$receiptsTable}.purchase_order_id");

        // Subquery to get line count per PO
        $linesSubquery = DB::table($purchaseOrderLinesTable)
            ->select([
                'purchase_order_id',
                DB::raw('COUNT(*) as line_count'),
            ])
            ->groupBy('purchase_order_id');

        $query = DB::table($purchaseOrdersTable)
            ->leftJoinSub($receivedSubquery, 'received', function ($join) use ($purchaseOrdersTable) {
                $join->on("{$purchaseOrdersTable}.id", '=', 'received.purchase_order_id');
            })
            ->leftJoinSub($linesSubquery, 'lines', function ($join) use ($purchaseOrdersTable) {
                $join->on("{$purchaseOrdersTable}.id", '=', 'lines.purchase_order_id');
            })
            ->select([
                "{$purchaseOrdersTable}.id as po_id",
                "{$purchaseOrdersTable}.po_number",
                "{$purchaseOrdersTable}.status",
                "{$purchaseOrdersTable}.vendor_id",
                "{$purchaseOrdersTable}.order_date",
                "{$purchaseOrdersTable}.expected_date",
                DB::raw('COALESCE(lines.line_count, 0) as line_count'),
                DB::raw("CAST({$purchaseOrdersTable}.total AS DECIMAL(15,2)) as total"),
                DB::raw('CAST(COALESCE(received.received_value, 0) AS DECIMAL(15,2)) as received_value'),
                DB::raw("CAST({$purchaseOrdersTable}.total - COALESCE(received.received_value, 0) AS DECIMAL(15,2)) as pending_value"),
            ])
            ->orderByDesc("{$purchaseOrdersTable}.order_date");

        if ($this->fromDate !== null) {
            $query->where("{$purchaseOrdersTable}.order_date", '>=', $this->fromDate->startOfDay());
        }

        if ($this->toDate !== null) {
            $query->where("{$purchaseOrdersTable}.order_date", '<=', $this->toDate->endOfDay());
        }

        if ($this->vendorId !== null) {
            $query->where("{$purchaseOrdersTable}.vendor_id", $this->vendorId);
        }

        if ($this->status !== null) {
            $query->where("{$purchaseOrdersTable}.status", $this->status);
        }

        $rows = $query->get();

        // Try to get vendor names if vendor model is available
        $rows = $this->attachVendorNames($rows);

        // Calculate summary
        $totalOrders = $rows->count();
        $totalValue = $rows->sum('total');
        $totalReceived = $rows->sum('received_value');
        $totalPending = $rows->sum('pending_value');
        $averageOrderValue = $totalOrders > 0 ? $totalValue / $totalOrders : 0;

        return new ReportData(
            title: $this->getTitle(),
            columns: $this->getColumnDefinitions(),
            rows: $rows,
            summary: [
                'total_orders' => $totalOrders,
                'total_value' => (float) $totalValue,
                'total_received' => (float) $totalReceived,
                'total_pending' => (float) $totalPending,
                'average_order_value' => (float) $averageOrderValue,
            ],
            generatedAt: Carbon::now(),
        );
    }

    public function getTitle(): string
    {
        return 'Purchase Order Report';
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, sortable: bool, format: string|null}>
     */
    public function getColumns(): array
    {
        return array_map(fn (ColumnDefinition $col) => $col->toArray(), $this->getColumnDefinitions());
    }

    /**
     * @return array<int, ColumnDefinition>
     */
    private function getColumnDefinitions(): array
    {
        return [
            new ColumnDefinition('po_id', 'PO ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('po_number', 'PO Number', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('status', 'Status', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('vendor_id', 'Vendor ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('vendor_name', 'Vendor Name', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('order_date', 'Order Date', ColumnDefinition::TYPE_DATE),
            new ColumnDefinition('expected_date', 'Expected Date', ColumnDefinition::TYPE_DATE),
            new ColumnDefinition('line_count', 'Lines', ColumnDefinition::TYPE_NUMBER),
            new ColumnDefinition('total', 'Total', ColumnDefinition::TYPE_CURRENCY, format: '0.00'),
            new ColumnDefinition('received_value', 'Received', ColumnDefinition::TYPE_CURRENCY, format: '0.00'),
            new ColumnDefinition('pending_value', 'Pending', ColumnDefinition::TYPE_CURRENCY, format: '0.00'),
        ];
    }

    /**
     * Attach vendor names to the rows if the vendor package is available.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $rows
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function attachVendorNames($rows)
    {
        // Check if vendor model exists
        if (! class_exists(\RobinsonRyan\Vendor\Models\Vendor::class)) {
            return $rows->map(function ($row) {
                $row->vendor_name = null;

                return $row;
            });
        }

        $vendorIds = $rows->pluck('vendor_id')->unique()->filter()->values();

        if ($vendorIds->isEmpty()) {
            return $rows->map(function ($row) {
                $row->vendor_name = null;

                return $row;
            });
        }

        $vendors = DB::table('vendors')
            ->whereIn('id', $vendorIds)
            ->pluck('name', 'id');

        return $rows->map(function ($row) use ($vendors) {
            $row->vendor_name = $vendors[$row->vendor_id] ?? null;

            return $row;
        });
    }
}
