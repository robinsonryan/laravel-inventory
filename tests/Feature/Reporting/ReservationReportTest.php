<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Reports\ReservationReport;
use RobinsonRyan\Inventory\Reporting\Services\ReportService;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;
use RobinsonRyan\Inventory\Stock\Services\StockService;

beforeEach(function (): void {
    $this->reportService = App::make(ReportService::class);
    $this->stockService = App::make(StockService::class);
    $this->reservationService = App::make(ReservationService::class);
    $this->location = Location::factory()->warehouse()->create(['code' => 'WH1']);
    $this->item = Item::factory()->create(['sku' => 'TEST-001', 'name' => 'Test Item']);

    // Add initial stock
    $this->stockService->addStock(new AddStockData(
        itemId: $this->item->id,
        locationId: $this->location->id,
        quantity: 100,
    ));
});

describe('ReservationReport', function (): void {
    it('generates reservation report', function (): void {
        $this->reservationService->reserve(new ReservationData(
            itemId: $this->item->id,
            jobType: 'WorkOrder',
            jobId: 'wo-123',
            quantity: 10,
            locationId: $this->location->id,
        ));

        $report = new ReservationReport;
        $data = $this->reportService->generate($report);

        expect($data)->toBeInstanceOf(ReportData::class)
            ->and($data->title)->toBe('Reservation Report')
            ->and($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('TEST-001')
            ->and((float) $data->rows->first()['quantity'])->toBe(10.0);
    });

    it('filters by job type', function (): void {
        $this->reservationService->reserve(new ReservationData(
            itemId: $this->item->id,
            jobType: 'WorkOrder',
            jobId: 'wo-123',
            quantity: 10,
        ));

        $this->reservationService->reserve(new ReservationData(
            itemId: $this->item->id,
            jobType: 'Quote',
            jobId: 'q-456',
            quantity: 5,
        ));

        $report = new ReservationReport(jobType: 'WorkOrder');
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['job_type'])->toBe('WorkOrder');
    });

    it('filters by status, and carries both the wording and the stored value', function (): void {
        Reservation::factory()
            ->forItem($this->item->id)
            ->forLocation($this->location)
            ->reserved()
            ->create();

        Reservation::factory()
            ->forItem($this->item->id)
            ->forLocation($this->location)
            ->fulfilled()
            ->create();

        $report = new ReservationReport(status: 'fulfilled');
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['status_value'])->toBe('fulfilled')
            ->and($data->rows->first()['status_label'])->toBe('Fulfilled');
    });

    it('filters by item', function (): void {
        $item2 = Item::factory()->create(['sku' => 'TEST-002']);

        $this->stockService->addStock(new AddStockData(
            itemId: $item2->id,
            locationId: $this->location->id,
            quantity: 50,
        ));

        $this->reservationService->reserve(new ReservationData(
            itemId: $this->item->id,
            jobType: 'WorkOrder',
            jobId: 'wo-123',
            quantity: 10,
        ));

        $this->reservationService->reserve(new ReservationData(
            itemId: $item2->id,
            jobType: 'WorkOrder',
            jobId: 'wo-456',
            quantity: 5,
        ));

        $report = new ReservationReport(itemId: $this->item->id);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['item_sku'])->toBe('TEST-001');
    });

    it('excludes expired reservations by default', function (): void {
        // Create a reservation that will expire
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->item->id,
            jobType: 'WorkOrder',
            jobId: 'wo-123',
            quantity: 10,
            expiryHours: 1,
        ));

        // Manually set expiry in the past
        Reservation::where('id', $reservation->id)->update([
            'expires_at' => Carbon::now()->subHour(),
        ]);

        $report = new ReservationReport(includeExpired: false);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(0);
    });

    it('includes expired reservations when requested', function (): void {
        $reservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->item->id,
            jobType: 'WorkOrder',
            jobId: 'wo-123',
            quantity: 10,
            expiryHours: 1,
        ));

        // Manually set expiry in the past
        Reservation::where('id', $reservation->id)->update([
            'expires_at' => Carbon::now()->subHour(),
        ]);

        $report = new ReservationReport(includeExpired: true);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1);
    });

    it('calculates summary counts correctly', function (): void {
        // Create active reservation
        $this->reservationService->reserve(new ReservationData(
            itemId: $this->item->id,
            jobType: 'WorkOrder',
            jobId: 'wo-123',
            quantity: 10,
        ));

        // Create and fulfill a reservation
        $fulfilledReservation = $this->reservationService->reserve(new ReservationData(
            itemId: $this->item->id,
            jobType: 'WorkOrder',
            jobId: 'wo-456',
            quantity: 5,
        ));
        $this->reservationService->fulfill($fulfilledReservation);

        $report = new ReservationReport;
        $data = $this->reportService->generate($report);

        expect($data->getSummary('total_reservations'))->toBe(2)
            ->and($data->getSummary('active_count'))->toBe(1)
            ->and($data->getSummary('fulfilled_count'))->toBe(1)
            ->and($data->getSummary('total_quantity'))->toBe(15.0);
    });
});
