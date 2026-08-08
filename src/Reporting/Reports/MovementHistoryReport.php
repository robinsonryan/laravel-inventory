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

    public function generate(): ReportData
    {
        $movementsTable = TableResolver::resolve('stock_movements', 'stock_movements');
        $itemsTable = TableResolver::resolve('items', 'items');
        $locationsTable = TableResolver::resolve('locations', 'locations');

        $query = DB::table($movementsTable)
            ->join($itemsTable, "{$movementsTable}.item_id", '=', "{$itemsTable}.id")
            ->leftJoin("{$locationsTable} as from_loc", "{$movementsTable}.from_location_id", '=', 'from_loc.id')
            ->leftJoin("{$locationsTable} as to_loc", "{$movementsTable}.to_location_id", '=', 'to_loc.id')
            ->select([
                "{$movementsTable}.id as movement_id",
                "{$movementsTable}.created_at",
                "{$movementsTable}.type",
                "{$movementsTable}.item_id",
                "{$itemsTable}.sku as item_sku",
                "{$itemsTable}.name as item_name",
                "{$movementsTable}.from_location_id",
                'from_loc.code as from_location',
                "{$movementsTable}.to_location_id",
                'to_loc.code as to_location',
                DB::raw("CAST({$movementsTable}.quantity AS DECIMAL(15,4)) as quantity"),
                DB::raw("CAST({$movementsTable}.cost AS DECIMAL(15,4)) as cost"),
                "{$movementsTable}.reference_type",
                "{$movementsTable}.reference_id",
                "{$movementsTable}.actor_type",
                "{$movementsTable}.actor_id",
                "{$movementsTable}.notes",
            ])
            ->orderByDesc("{$movementsTable}.created_at");

        if ($this->fromDate instanceof Carbon) {
            $query->where("{$movementsTable}.created_at", '>=', $this->fromDate->startOfDay());
        }

        if ($this->toDate instanceof Carbon) {
            $query->where("{$movementsTable}.created_at", '<=', $this->toDate->endOfDay());
        }

        if ($this->itemId !== null) {
            $query->where("{$movementsTable}.item_id", $this->itemId);
        }

        if ($this->locationId !== null) {
            $query->where(function ($q) use ($movementsTable): void {
                $q->where("{$movementsTable}.from_location_id", $this->locationId)
                    ->orWhere("{$movementsTable}.to_location_id", $this->locationId);
            });
        }

        if ($this->movementType !== null) {
            $query->where("{$movementsTable}.type", $this->movementType);
        }

        if ($this->referenceType !== null) {
            $query->where("{$movementsTable}.reference_type", $this->referenceType);
        }

        if ($this->referenceId !== null) {
            $query->where("{$movementsTable}.reference_id", $this->referenceId);
        }

        // Normalise the raw stdClass result rows to arrays once, here, rather
        // than leaving every exporter to cast defensively.
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $query->get()->map(fn (object $row): array => (array) $row);

        // Calculate summary
        $totalMovements = $rows->count();
        $totalQuantityIn = $rows->whereNotNull('to_location_id')->sum('quantity');
        $totalQuantityOut = $rows->whereNotNull('from_location_id')->sum('quantity');
        $totalCost = $rows->sum(fn (array $row): float => (float) $row['quantity'] * (float) ($row['cost'] ?? 0));

        return new ReportData(
            title: $this->getTitle(),
            columns: $this->getColumnDefinitions(),
            rows: $rows,
            summary: [
                'total_movements' => $totalMovements,
                'total_quantity_in' => (float) $totalQuantityIn,
                'total_quantity_out' => (float) $totalQuantityOut,
                'total_cost' => $totalCost,
            ],
            generatedAt: Carbon::now(),
        );
    }

    public function getTitle(): string
    {
        return 'Movement History Report';
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
            new ColumnDefinition('movement_id', 'Movement ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('created_at', 'Date/Time', ColumnDefinition::TYPE_DATETIME),
            new ColumnDefinition('type', 'Type', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('item_id', 'Item ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('item_sku', 'SKU', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('item_name', 'Item Name', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('from_location', 'From Location', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('to_location', 'To Location', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('quantity', 'Quantity', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('cost', 'Cost', ColumnDefinition::TYPE_CURRENCY, format: '0.00'),
            new ColumnDefinition('reference_type', 'Reference Type', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('reference_id', 'Reference ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('actor_type', 'Actor Type', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('actor_id', 'Actor ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('notes', 'Notes', ColumnDefinition::TYPE_STRING, sortable: false),
        ];
    }
}
