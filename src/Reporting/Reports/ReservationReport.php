<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Reporting\Contracts\ReportInterface;
use RobinsonRyan\Inventory\Reporting\Data\ColumnDefinition;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;

final class ReservationReport implements ReportInterface
{
    public function __construct(
        public ?string $status = null,
        public ?string $jobType = null,
        public ?string $itemId = null,
        public bool $includeExpired = false,
    ) {}

    public function generate(): ReportData
    {
        $reservationsTable = config('inventory.tables.reservations', 'reservations');
        $itemsTable = config('inventory.tables.items', 'items');
        $locationsTable = config('inventory.tables.locations', 'locations');

        $query = DB::table($reservationsTable)
            ->join($itemsTable, "{$reservationsTable}.item_id", '=', "{$itemsTable}.id")
            ->leftJoin($locationsTable, "{$reservationsTable}.location_id", '=', "{$locationsTable}.id")
            ->select([
                "{$reservationsTable}.id as reservation_id",
                "{$reservationsTable}.status",
                "{$reservationsTable}.reserved_at",
                "{$reservationsTable}.expires_at",
                "{$reservationsTable}.item_id",
                "{$itemsTable}.sku as item_sku",
                "{$itemsTable}.name as item_name",
                DB::raw("CAST({$reservationsTable}.quantity AS DECIMAL(15,4)) as quantity"),
                "{$reservationsTable}.job_type",
                "{$reservationsTable}.job_id",
                "{$reservationsTable}.line_id",
                "{$reservationsTable}.location_id",
                "{$locationsTable}.code as location_code",
                "{$reservationsTable}.fulfilled_at",
                "{$reservationsTable}.cancelled_at",
            ])
            ->orderByDesc("{$reservationsTable}.reserved_at");

        if ($this->status !== null) {
            $query->where("{$reservationsTable}.status", $this->status);
        }

        if ($this->jobType !== null) {
            $query->where("{$reservationsTable}.job_type", $this->jobType);
        }

        if ($this->itemId !== null) {
            $query->where("{$reservationsTable}.item_id", $this->itemId);
        }

        if (! $this->includeExpired) {
            $query->where(function ($q) use ($reservationsTable) {
                $q->whereNull("{$reservationsTable}.expires_at")
                    ->orWhere("{$reservationsTable}.expires_at", '>', Carbon::now());
            });
        }

        $rows = $query->get();

        // Calculate summary
        $totalReservations = $rows->count();
        $totalQuantity = $rows->sum('quantity');
        $activeCount = $rows->whereNull('fulfilled_at')->whereNull('cancelled_at')->count();
        $fulfilledCount = $rows->whereNotNull('fulfilled_at')->count();
        $cancelledCount = $rows->whereNotNull('cancelled_at')->count();

        return new ReportData(
            title: $this->getTitle(),
            columns: $this->getColumnDefinitions(),
            rows: $rows,
            summary: [
                'total_reservations' => $totalReservations,
                'total_quantity' => (float) $totalQuantity,
                'active_count' => $activeCount,
                'fulfilled_count' => $fulfilledCount,
                'cancelled_count' => $cancelledCount,
            ],
            generatedAt: Carbon::now(),
        );
    }

    public function getTitle(): string
    {
        return 'Reservation Report';
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
            new ColumnDefinition('reservation_id', 'Reservation ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('status', 'Status', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('reserved_at', 'Reserved At', ColumnDefinition::TYPE_DATETIME),
            new ColumnDefinition('expires_at', 'Expires At', ColumnDefinition::TYPE_DATETIME),
            new ColumnDefinition('item_id', 'Item ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('item_sku', 'SKU', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('item_name', 'Item Name', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('quantity', 'Quantity', ColumnDefinition::TYPE_NUMBER, format: '0.00'),
            new ColumnDefinition('job_type', 'Job Type', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('job_id', 'Job ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('line_id', 'Line ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('location_id', 'Location ID', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('location_code', 'Location Code', ColumnDefinition::TYPE_STRING),
            new ColumnDefinition('fulfilled_at', 'Fulfilled At', ColumnDefinition::TYPE_DATETIME),
            new ColumnDefinition('cancelled_at', 'Cancelled At', ColumnDefinition::TYPE_DATETIME),
        ];
    }
}
