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
use RobinsonRyan\Inventory\Support\TableResolver;
use RobinsonRyan\Inventory\Support\TagQuery;

final class StockValuationReport implements ReportInterface
{
    public function __construct(
        public ?string $locationId = null,
        public ?string $itemId = null,
        public ?string $category = null,
    ) {}

    public function generate(): ReportData
    {
        $stockTable = TableResolver::resolve('stock', 'stock');
        $itemsTable = TableResolver::resolve('items', 'items');
        $locationsTable = TableResolver::resolve('locations', 'locations');

        $query = DB::table($stockTable)
            ->join($itemsTable, "{$stockTable}.item_id", '=', "{$itemsTable}.id")
            ->join($locationsTable, "{$stockTable}.location_id", '=', "{$locationsTable}.id")
            ->select([
                "{$stockTable}.item_id",
                "{$itemsTable}.sku as item_sku",
                "{$itemsTable}.name as item_name",
                "{$stockTable}.location_id",
                "{$locationsTable}.code as location_code",
                "{$locationsTable}.name as location_name",
                DB::raw("CAST({$stockTable}.quantity AS DECIMAL(15,4)) as quantity"),
                DB::raw("CAST({$stockTable}.cost_basis AS DECIMAL(15,4)) as unit_cost"),
                DB::raw("CAST({$stockTable}.quantity * COALESCE({$stockTable}.cost_basis, 0) AS DECIMAL(15,4)) as total_value"),
                DB::raw("CAST({$stockTable}.reserved_quantity AS DECIMAL(15,4)) as reserved_quantity"),
                DB::raw("CAST({$stockTable}.quantity - {$stockTable}.reserved_quantity AS DECIMAL(15,4)) as available_quantity"),
            ]);

        if ($this->locationId !== null) {
            $query->where("{$stockTable}.location_id", $this->locationId);
        }

        if ($this->itemId !== null) {
            $query->where("{$stockTable}.item_id", $this->itemId);
        }

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

        $rows = TagRowValues::attach(
            $rows,
            ModelResolver::resolveAny('item', Item::class),
            ItemCategoryDefinition::class,
            'item_id',
            'category',
        );

        // Calculate summary totals
        $totalQuantity = $rows->sum('quantity');
        $totalValue = $rows->sum('total_value');
        $totalReserved = $rows->sum('reserved_quantity');
        $totalAvailable = $rows->sum('available_quantity');

        return new ReportData(
            title: $this->getTitle(),
            columns: $this->getColumnDefinitions(),
            rows: $rows,
            summary: [
                'total_quantity' => (float) $totalQuantity,
                'total_value' => (float) $totalValue,
                'total_reserved' => (float) $totalReserved,
                'total_available' => (float) $totalAvailable,
                'unique_items' => $rows->unique('item_id')->count(),
                'unique_locations' => $rows->unique('location_id')->count(),
            ],
            generatedAt: Carbon::now(),
        );
    }

    public function getTitle(): string
    {
        return 'Stock Valuation Report';
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
            new ColumnDefinition('location_id', 'Location ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('location_code', 'Location Code', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('location_name', 'Location Name', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('quantity', 'Quantity', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('unit_cost', 'Unit Cost', ColumnDefinition::TYPE_CURRENCY, format: '0.00'),
            new ColumnDefinition('total_value', 'Total Value', ColumnDefinition::TYPE_CURRENCY, format: '0.00'),
            new ColumnDefinition('reserved_quantity', 'Reserved', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('available_quantity', 'Available', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
        ];
    }
}
