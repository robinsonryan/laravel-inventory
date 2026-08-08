<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Reporting\Contracts\ReportInterface;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Support\TableResolver;

final class VarianceReport implements ReportInterface
{
    public function __construct(
        public ?Carbon $fromDate = null,
        public ?Carbon $toDate = null,
        public ?string $locationId = null,
        public ?string $itemId = null,
    ) {}

    public function generate(): ReportData
    {
        $cycleCountsTable = TableResolver::resolve('cycle_counts', 'cycle_counts');
        $cycleCountLinesTable = TableResolver::resolve('cycle_count_lines', 'cycle_count_lines');
        $itemsTable = TableResolver::resolve('items', 'items');
        $locationsTable = TableResolver::resolve('locations', 'locations');

        $query = DB::table($cycleCountLinesTable)
            ->join($cycleCountsTable, "{$cycleCountLinesTable}.cycle_count_id", '=', "{$cycleCountsTable}.id")
            ->join($itemsTable, "{$cycleCountLinesTable}.item_id", '=', "{$itemsTable}.id")
            ->join($locationsTable, "{$cycleCountsTable}.location_id", '=', "{$locationsTable}.id")
            ->select([
                "{$cycleCountsTable}.id as cycle_count_id",
                "{$cycleCountsTable}.count_number",
                "{$cycleCountsTable}.approved_at",
                "{$cycleCountLinesTable}.item_id",
                "{$itemsTable}.sku as item_sku",
                "{$itemsTable}.name as item_name",
                "{$cycleCountsTable}.location_id",
                "{$locationsTable}.code as location_code",
                DB::raw("CAST({$cycleCountLinesTable}.expected_quantity AS DECIMAL(15,4)) as expected_quantity"),
                DB::raw("CAST({$cycleCountLinesTable}.counted_quantity AS DECIMAL(15,4)) as counted_quantity"),
                DB::raw("CAST({$cycleCountLinesTable}.counted_quantity - {$cycleCountLinesTable}.expected_quantity AS DECIMAL(15,4)) as variance"),
                DB::raw("CAST(({$cycleCountLinesTable}.counted_quantity - {$cycleCountLinesTable}.expected_quantity) * COALESCE({$itemsTable}.cost, 0) AS DECIMAL(15,4)) as cost_impact"),
                "{$cycleCountLinesTable}.reason",
            ])
            ->whereNotNull("{$cycleCountsTable}.approved_at")
            ->orderByDesc("{$cycleCountsTable}.approved_at");

        if ($this->fromDate instanceof Carbon) {
            $query->where("{$cycleCountsTable}.approved_at", '>=', $this->fromDate->startOfDay());
        }

        if ($this->toDate instanceof Carbon) {
            $query->where("{$cycleCountsTable}.approved_at", '<=', $this->toDate->endOfDay());
        }

        if ($this->locationId !== null) {
            $query->where("{$cycleCountsTable}.location_id", $this->locationId);
        }

        if ($this->itemId !== null) {
            $query->where("{$cycleCountLinesTable}.item_id", $this->itemId);
        }

        // Normalise the raw stdClass result rows to arrays once, here, rather
        // than leaving every exporter to cast defensively.
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $query->get()->map(fn (object $row): array => (array) $row);

        // Calculate summary
        $totalVariances = $rows->count();
        $positiveVariances = $rows->where('variance', '>', 0)->count();
        $negativeVariances = $rows->where('variance', '<', 0)->count();
        $zeroVariances = $rows->where('variance', '=', 0)->count();
        $totalCostImpact = $rows->sum('cost_impact');
        $netVariance = $rows->sum('variance');

        return new ReportData(
            title: $this->getTitle(),
            columns: $this->getColumnDefinitions(),
            rows: $rows,
            summary: [
                'total_variances' => $totalVariances,
                'positive_variances' => $positiveVariances,
                'negative_variances' => $negativeVariances,
                'zero_variances' => $zeroVariances,
                'net_variance' => (float) $netVariance,
                'total_cost_impact' => (float) $totalCostImpact,
            ],
            generatedAt: Carbon::now(),
        );
    }

    public function getTitle(): string
    {
        return 'Variance Report';
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
            new ColumnDefinition('cycle_count_id', 'Cycle Count ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('count_number', 'Count Number', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('approved_at', 'Approved At', ColumnDefinition::TYPE_DATETIME),
            new ColumnDefinition('item_id', 'Item ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('item_sku', 'SKU', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('item_name', 'Item Name', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('location_id', 'Location ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('location_code', 'Location Code', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('expected_quantity', 'Expected Qty', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('counted_quantity', 'Counted Qty', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('variance', 'Variance', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('cost_impact', 'Cost Impact', ColumnDefinition::TYPE_CURRENCY, format: '0.00'),
            new ColumnDefinition('reason', 'Reason', ColumnDefinition::TYPE_STRING, sortable: false),
        ];
    }
}
