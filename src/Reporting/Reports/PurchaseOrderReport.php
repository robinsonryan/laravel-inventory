<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\TagDefinitions\PurchaseOrderStatusDefinition;
use RobinsonRyan\Inventory\Reporting\Contracts\ReportInterface;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Support\TagRowValues;
use RobinsonRyan\Inventory\Support\ModelResolver;
use RobinsonRyan\Inventory\Support\TableResolver;
use RobinsonRyan\Inventory\Support\TagQuery;
use RobinsonRyan\Vendor\Models\Vendor;

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
        $purchaseOrdersTable = TableResolver::resolve('purchase_orders', 'purchase_orders');
        $purchaseOrderLinesTable = TableResolver::resolve('purchase_order_lines', 'purchase_order_lines');
        $receiptsTable = TableResolver::resolve('receipts', 'receipts');
        $receiptLinesTable = TableResolver::resolve('receipt_lines', 'receipt_lines');

        // Received value per purchase order. The counted quantity comes from the
        // receipt line — that is all the receiving desk records — and the price
        // comes from the order line it satisfies, because price is agreed when
        // ordering and is not something receiving staff know or capture.
        $receivedSubquery = DB::table($receiptLinesTable)
            ->join($receiptsTable, "{$receiptLinesTable}.receipt_id", '=', "{$receiptsTable}.id")
            ->join(
                $purchaseOrderLinesTable,
                "{$receiptLinesTable}.purchase_order_line_id",
                '=',
                "{$purchaseOrderLinesTable}.id",
            )
            ->select([
                "{$receiptsTable}.purchase_order_id",
                DB::raw("CAST(SUM({$receiptLinesTable}.quantity * {$purchaseOrderLinesTable}.unit_cost) AS DECIMAL(15,2)) as received_value"),
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
            ->leftJoinSub($receivedSubquery, 'received', function ($join) use ($purchaseOrdersTable): void {
                $join->on("{$purchaseOrdersTable}.id", '=', 'received.purchase_order_id');
            })
            ->leftJoinSub($linesSubquery, 'lines', function ($join) use ($purchaseOrdersTable): void {
                $join->on("{$purchaseOrdersTable}.id", '=', 'lines.purchase_order_id');
            })
            ->select([
                "{$purchaseOrdersTable}.id as po_id",
                "{$purchaseOrdersTable}.po_number",
                "{$purchaseOrdersTable}.vendor_id",
                "{$purchaseOrdersTable}.order_date",
                "{$purchaseOrdersTable}.expected_date",
                DB::raw('COALESCE(lines.line_count, 0) as line_count'),
                DB::raw("CAST({$purchaseOrdersTable}.total AS DECIMAL(15,2)) as total"),
                DB::raw('CAST(COALESCE(received.received_value, 0) AS DECIMAL(15,2)) as received_value'),
                DB::raw("CAST({$purchaseOrdersTable}.total - COALESCE(received.received_value, 0) AS DECIMAL(15,2)) as pending_value"),
            ])
            ->orderByDesc("{$purchaseOrdersTable}.order_date");

        if ($this->fromDate instanceof Carbon) {
            $query->where("{$purchaseOrdersTable}.order_date", '>=', $this->fromDate->startOfDay());
        }

        if ($this->toDate instanceof Carbon) {
            $query->where("{$purchaseOrdersTable}.order_date", '<=', $this->toDate->endOfDay());
        }

        if ($this->vendorId !== null) {
            $query->where("{$purchaseOrdersTable}.vendor_id", $this->vendorId);
        }

        // The status is a tag, so it is filtered through the ids taxon says
        // carry that value rather than through a column that does not exist.
        if ($this->status !== null) {
            $query->whereIn("{$purchaseOrdersTable}.id", TagQuery::filterIdsByTagValue(
                ModelResolver::resolve('purchase_order', PurchaseOrder::class),
                PurchaseOrderStatusDefinition::class,
                $this->status,
            ));
        }

        // Normalise the raw stdClass result rows to arrays once, here, rather
        // than leaving every exporter to cast defensively.
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $query->get()->map(fn (object $row): array => (array) $row);

        $rows = TagRowValues::attach(
            $rows,
            ModelResolver::resolve('purchase_order', PurchaseOrder::class),
            PurchaseOrderStatusDefinition::class,
            'po_id',
            'status',
        );

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
        return array_map(fn (ColumnDefinition $col): array => $col->toArray(), $this->getColumnDefinitions());
    }

    /**
     * @return array<int, ColumnDefinition>
     */
    private function getColumnDefinitions(): array
    {
        return [
            new ColumnDefinition('po_id', 'PO ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('po_number', 'PO Number', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('status_label', 'Status', ColumnDefinition::TYPE_STRING),
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
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function attachVendorNames(Collection $rows): Collection
    {
        $vendors = $this->vendorNames($rows);

        /** @var Collection<int, array<string, mixed>> $mapped */
        $mapped = $rows->map(function (array $row) use ($vendors): array {
            $vendorId = $row['vendor_id'] ?? null;
            $row['vendor_name'] = is_scalar($vendorId) ? ($vendors[$vendorId] ?? null) : null;

            return $row;
        });

        return $mapped;
    }

    /**
     * Look up vendor names keyed by vendor ID, or an empty map when the vendor
     * package is absent or no row carries a vendor.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<array-key, mixed>
     */
    private function vendorNames(Collection $rows): Collection
    {
        if (! class_exists(Vendor::class)) {
            return collect();
        }

        $vendorIds = $rows->pluck('vendor_id')->unique()->filter()->values();

        if ($vendorIds->isEmpty()) {
            return collect();
        }

        return DB::table('vendors')
            ->whereIn('id', $vendorIds)
            ->pluck('name', 'id');
    }
}
