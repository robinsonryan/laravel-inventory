<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;
use RobinsonRyan\Inventory\Reporting\Reports\PurchaseOrderReport;
use RobinsonRyan\Inventory\Reporting\Services\ReportService;
use Symfony\Component\Uid\Uuid;

beforeEach(function (): void {
    $this->reportService = App::make(ReportService::class);
    $this->vendorId = (string) Uuid::v7();
});

describe('PurchaseOrderReport', function (): void {
    it('generates purchase order report', function (): void {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendorId)
            ->create([
                'po_number' => 'PO-001',
                'order_date' => Carbon::parse('2026-01-15'),
                'total' => 1000.00,
            ]);

        PurchaseOrderLine::factory()
            ->forPurchaseOrder($po)
            ->count(3)
            ->create();

        $report = new PurchaseOrderReport;
        $data = $this->reportService->generate($report);

        expect($data)->toBeInstanceOf(ReportData::class)
            ->and($data->title)->toBe('Purchase Order Report')
            ->and($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['po_number'])->toBe('PO-001')
            ->and((float) $data->rows->first()['total'])->toBe(1000.00)
            ->and((int) $data->rows->first()['line_count'])->toBe(3);
    });

    it('filters by date range', function (): void {
        // Old PO
        PurchaseOrder::factory()
            ->forVendor($this->vendorId)
            ->create([
                'po_number' => 'PO-OLD',
                'order_date' => Carbon::parse('2026-01-01'),
            ]);

        // Recent PO
        PurchaseOrder::factory()
            ->forVendor($this->vendorId)
            ->create([
                'po_number' => 'PO-RECENT',
                'order_date' => Carbon::parse('2026-01-15'),
            ]);

        $report = new PurchaseOrderReport(
            fromDate: Carbon::parse('2026-01-10'),
            toDate: Carbon::parse('2026-01-20'),
        );
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['po_number'])->toBe('PO-RECENT');
    });

    it('filters by vendor', function (): void {
        $vendorId2 = (string) Uuid::v7();

        PurchaseOrder::factory()
            ->forVendor($this->vendorId)
            ->create(['po_number' => 'PO-V1']);

        PurchaseOrder::factory()
            ->forVendor($vendorId2)
            ->create(['po_number' => 'PO-V2']);

        $report = new PurchaseOrderReport(vendorId: $this->vendorId);
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['po_number'])->toBe('PO-V1');
    });

    it('filters by status', function (): void {
        PurchaseOrder::factory()
            ->forVendor($this->vendorId)
            ->draft()
            ->create(['po_number' => 'PO-DRAFT']);

        PurchaseOrder::factory()
            ->forVendor($this->vendorId)
            ->submitted()
            ->create(['po_number' => 'PO-SUBMITTED']);

        $report = new PurchaseOrderReport(status: 'submitted');
        $data = $this->reportService->generate($report);

        expect($data->rows)->toHaveCount(1)
            ->and($data->rows->first()['po_number'])->toBe('PO-SUBMITTED');
    });

    it('calculates received and pending values', function (): void {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendorId)
            ->create([
                'po_number' => 'PO-001',
                'total' => 1000.00,
            ]);

        // Create lines and receipts
        $line1 = PurchaseOrderLine::factory()
            ->forPurchaseOrder($po)
            ->create([
                'quantity' => 10,
                'unit_cost' => 50.00, // $500 value
            ]);

        $line2 = PurchaseOrderLine::factory()
            ->forPurchaseOrder($po)
            ->create([
                'quantity' => 10,
                'unit_cost' => 50.00, // $500 value
            ]);

        // Create receipt for partial receipt
        $receiptsTable = config('inventory.tables.receipts', 'receipts');
        $receiptLinesTable = config('inventory.tables.receipt_lines', 'receipt_lines');

        $receiptId = (string) Uuid::v7();
        Illuminate\Support\Facades\DB::table($receiptsTable)->insert([
            'id' => $receiptId,
            'purchase_order_id' => $po->id,
            'receipt_number' => 'REC-001',
            'received_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        Illuminate\Support\Facades\DB::table($receiptLinesTable)->insert([
            'id' => (string) Uuid::v7(),
            'receipt_id' => $receiptId,
            'purchase_order_line_id' => $line1->id,
            'received_quantity' => 5,
            'unit_cost' => 50.00, // $250 received
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $report = new PurchaseOrderReport;
        $data = $this->reportService->generate($report);

        expect((float) $data->rows->first()['received_value'])->toBe(250.00)
            ->and((float) $data->rows->first()['pending_value'])->toBe(750.00);
    });

    it('calculates summary totals', function (): void {
        PurchaseOrder::factory()
            ->forVendor($this->vendorId)
            ->create(['total' => 1000.00]);

        PurchaseOrder::factory()
            ->forVendor($this->vendorId)
            ->create(['total' => 500.00]);

        $report = new PurchaseOrderReport;
        $data = $this->reportService->generate($report);

        expect($data->getSummary('total_orders'))->toBe(2)
            ->and($data->getSummary('total_value'))->toBe(1500.00)
            ->and($data->getSummary('average_order_value'))->toBe(750.00);
    });

    it('includes all required columns', function (): void {
        $report = new PurchaseOrderReport;
        $columns = $report->getColumns();

        $columnKeys = array_column($columns, 'key');

        expect($columnKeys)->toContain('po_id')
            ->toContain('po_number')
            ->toContain('status')
            ->toContain('vendor_id')
            ->toContain('vendor_name')
            ->toContain('order_date')
            ->toContain('expected_date')
            ->toContain('line_count')
            ->toContain('total')
            ->toContain('received_value')
            ->toContain('pending_value');
    });
});
