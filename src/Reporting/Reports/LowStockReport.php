<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\TagDefinitions\ItemCategoryDefinition;
use RobinsonRyan\Inventory\Reporting\Contracts\ReportInterface;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Support\TagRowValues;
use RobinsonRyan\Inventory\Support\ModelResolver;
use RobinsonRyan\Inventory\Support\MovementScopes;
use RobinsonRyan\Inventory\Support\TableResolver;
use RobinsonRyan\Inventory\Support\TagQuery;

final class LowStockReport implements ReportInterface
{
    public function __construct(
        public ?string $category = null,
        public bool $includeBelowMin = true,
        public bool $includeZero = true,
    ) {}

    public function generate(): ReportData
    {
        $stockTable = TableResolver::resolve('stock', 'stock');
        $itemsTable = TableResolver::resolve('items', 'items');
        $movementsTable = TableResolver::resolve('stock_movements', 'stock_movements');

        // Average daily usage over the last 30 days. "Usage" is stock that left
        // and did not come back — a sale, a job, scrap — decided by the shape of
        // the movement rather than by its type tag, so that this report and the
        // demand analyser cannot drift apart. A transfer between two owned
        // locations names both ends and is therefore not usage.
        $avgUsageSubquery = MovementScopes::depleting(
            DB::table($movementsTable)
                ->select([
                    'item_id',
                    DB::raw('CAST(SUM(quantity) / 30.0 AS DECIMAL(15,4)) as avg_daily_usage'),
                ])
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->groupBy('item_id'),
        );

        // Main query: items with stock aggregated
        $query = DB::table($itemsTable)
            ->leftJoin($stockTable, "{$itemsTable}.id", '=', "{$stockTable}.item_id")
            ->leftJoinSub($avgUsageSubquery, 'usage', function ($join) use ($itemsTable): void {
                $join->on("{$itemsTable}.id", '=', 'usage.item_id');
            })
            ->select([
                "{$itemsTable}.id as item_id",
                "{$itemsTable}.sku as item_sku",
                "{$itemsTable}.name as item_name",
                "{$itemsTable}.min_stock_level",
                DB::raw("CAST(COALESCE(SUM({$stockTable}.quantity), 0) AS DECIMAL(15,4)) as current_quantity"),
                DB::raw("CAST(COALESCE(SUM({$stockTable}.quantity - {$stockTable}.reserved_quantity), 0) AS DECIMAL(15,4)) as available_quantity"),
                DB::raw('CAST(COALESCE(usage.avg_daily_usage, 0) AS DECIMAL(15,4)) as average_daily_usage'),
            ])
            ->whereNotNull("{$itemsTable}.min_stock_level")
            ->where("{$itemsTable}.min_stock_level", '>', 0)
            ->where("{$itemsTable}.is_active", true)
            // Grouping on the item's primary key already makes every group a
            // single item, so the remaining item columns are along for the ride
            // only to satisfy strict GROUP BY. Dropping the category from the
            // list therefore changes no grouping.
            ->groupBy([
                "{$itemsTable}.id",
                "{$itemsTable}.sku",
                "{$itemsTable}.name",
                "{$itemsTable}.min_stock_level",
                'usage.avg_daily_usage',
            ]);

        // The category is a tag, so it is filtered through the ids taxon says
        // carry that value rather than through a column that does not exist.
        if ($this->category !== null) {
            $query->whereIn("{$itemsTable}.id", TagQuery::filterIdsByTagValue(
                ModelResolver::resolveAny('item', Item::class),
                ItemCategoryDefinition::class,
                $this->category,
            ));
        }

        // Normalise the raw stdClass result rows to arrays once, here, rather
        // than leaving every exporter to cast defensively.
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $query->get()->map(fn (object $row): array => (array) $row);

        // Filter and calculate additional fields
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $rows->map(function (array $row): array {
            $available = (float) $row['available_quantity'];
            $minLevel = (int) $row['min_stock_level'];
            $avgUsage = (float) $row['average_daily_usage'];

            $row['shortfall'] = max(0, $minLevel - $available);
            $row['days_of_stock'] = $avgUsage > 0 ? (int) floor($available / $avgUsage) : null;

            return $row;
        });

        // Filter based on flags
        $rows = $rows->filter(function (array $row): bool {
            $available = (float) $row['available_quantity'];
            $minLevel = (int) $row['min_stock_level'];

            // Only include items below minimum or at zero based on flags
            if ($available <= 0) {
                return $this->includeZero;
            }

            if ($available < $minLevel) {
                return $this->includeBelowMin;
            }

            return false;
        })->values();

        // Only the surviving rows need their category looked up.
        $rows = TagRowValues::attach(
            $rows,
            ModelResolver::resolveAny('item', Item::class),
            ItemCategoryDefinition::class,
            'item_id',
            'category',
        );

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
        return array_map(fn (ColumnDefinition $col): array => $col->toArray(), $this->getColumnDefinitions());
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
            new ColumnDefinition('category_label', 'Category', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('min_stock_level', 'Min Stock Level', ColumnDefinition::TYPE_NUMBER),
            new ColumnDefinition('current_quantity', 'Current Qty', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('available_quantity', 'Available Qty', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('shortfall', 'Shortfall', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('average_daily_usage', 'Avg Daily Usage', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('days_of_stock', 'Days of Stock', ColumnDefinition::TYPE_NUMBER),
        ];
    }
}
