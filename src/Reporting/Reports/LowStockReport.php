<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Reporting\Contracts\ReportInterface;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;

final class LowStockReport implements ReportInterface
{
    public function __construct(
        public ?string $category = null,
        public bool $includeBelowMin = true,
        public bool $includeZero = true,
    ) {}

    public function generate(): ReportData
    {
        $stockTable = config('inventory.tables.stock', 'stock');
        $itemsTable = config('inventory.tables.items', 'items');
        $movementsTable = config('inventory.tables.stock_movements', 'stock_movements');

        // Subquery to get average daily usage over last 30 days
        $avgUsageSubquery = DB::table($movementsTable)
            ->select([
                'item_id',
                DB::raw('CAST(SUM(quantity) / 30.0 AS DECIMAL(15,4)) as avg_daily_usage'),
            ])
            ->where('type', 'consumption')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->groupBy('item_id');

        // Main query: items with stock aggregated
        $query = DB::table($itemsTable)
            ->leftJoin($stockTable, "{$itemsTable}.id", '=', "{$stockTable}.item_id")
            ->leftJoinSub($avgUsageSubquery, 'usage', function ($join) use ($itemsTable) {
                $join->on("{$itemsTable}.id", '=', 'usage.item_id');
            })
            ->select([
                "{$itemsTable}.id as item_id",
                "{$itemsTable}.sku as item_sku",
                "{$itemsTable}.name as item_name",
                "{$itemsTable}.category",
                "{$itemsTable}.min_stock_level",
                DB::raw("CAST(COALESCE(SUM({$stockTable}.quantity), 0) AS DECIMAL(15,4)) as current_quantity"),
                DB::raw("CAST(COALESCE(SUM({$stockTable}.quantity - {$stockTable}.reserved_quantity), 0) AS DECIMAL(15,4)) as available_quantity"),
                DB::raw('CAST(COALESCE(usage.avg_daily_usage, 0) AS DECIMAL(15,4)) as average_daily_usage'),
            ])
            ->whereNotNull("{$itemsTable}.min_stock_level")
            ->where("{$itemsTable}.min_stock_level", '>', 0)
            ->where("{$itemsTable}.is_active", true)
            ->groupBy([
                "{$itemsTable}.id",
                "{$itemsTable}.sku",
                "{$itemsTable}.name",
                "{$itemsTable}.category",
                "{$itemsTable}.min_stock_level",
                'usage.avg_daily_usage',
            ]);

        if ($this->category !== null) {
            $query->where("{$itemsTable}.category", $this->category);
        }

        $rows = $query->get();

        // Filter and calculate additional fields
        $rows = $rows->map(function ($row) {
            $available = (float) $row->available_quantity;
            $minLevel = (int) $row->min_stock_level;
            $avgUsage = (float) $row->average_daily_usage;

            $row->shortfall = max(0, $minLevel - $available);
            $row->days_of_stock = $avgUsage > 0 ? (int) floor($available / $avgUsage) : null;

            return $row;
        });

        // Filter based on flags
        $rows = $rows->filter(function ($row) {
            $available = (float) $row->available_quantity;
            $minLevel = (int) $row->min_stock_level;

            // Only include items below minimum or at zero based on flags
            if ($available <= 0) {
                return $this->includeZero;
            }

            if ($available < $minLevel) {
                return $this->includeBelowMin;
            }

            return false;
        })->values();

        // Calculate summary
        $totalItems = $rows->count();
        $zeroStockCount = $rows->where('available_quantity', '<=', 0)->count();
        $belowMinCount = $rows->where('available_quantity', '>', 0)->count();
        $totalShortfall = $rows->sum('shortfall');

        return new ReportData(
            title: $this->getTitle(),
            columns: $this->getColumnDefinitions(),
            rows: $rows,
            summary: [
                'total_items' => $totalItems,
                'zero_stock_count' => $zeroStockCount,
                'below_min_count' => $belowMinCount,
                'total_shortfall' => (float) $totalShortfall,
            ],
            generatedAt: Carbon::now(),
        );
    }

    public function getTitle(): string
    {
        return 'Low Stock Report';
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
            new ColumnDefinition('item_id', 'Item ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('item_sku', 'SKU', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('item_name', 'Item Name', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('category', 'Category', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('min_stock_level', 'Min Stock Level', ColumnDefinition::TYPE_NUMBER),
            new ColumnDefinition('current_quantity', 'Current Qty', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('available_quantity', 'Available Qty', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('shortfall', 'Shortfall', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('average_daily_usage', 'Avg Daily Usage', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('days_of_stock', 'Days of Stock', ColumnDefinition::TYPE_NUMBER),
        ];
    }
}
