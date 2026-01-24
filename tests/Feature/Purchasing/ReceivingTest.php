<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Purchasing\Actions\ReceiveLine;
use RobinsonRyan\Inventory\Purchasing\Actions\ReceiveMultipleLines;
use RobinsonRyan\Inventory\Purchasing\Data\ReceiveLineData;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine;
use RobinsonRyan\Inventory\Purchasing\Models\Receipt;
use RobinsonRyan\Inventory\Purchasing\Models\ReceiptLine;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;
use RobinsonRyan\Vendor\Models\Vendor;

beforeEach(function () {
    $this->vendor = Vendor::factory()->create();
    $this->item = Item::factory()->create();
    $this->location = Location::factory()->warehouse()->create();
});

describe('ReceiveLine', function () {
    it('receives a line item', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->state(['quantity' => 100, 'unit_cost' => 10.00]),
                'lines'
            )
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 50,
            locationId: $this->location->id,
        );

        $action = App::make(ReceiveLine::class);
        $receiptLine = $action->execute($data);

        expect($receiptLine)->toBeInstanceOf(ReceiptLine::class)
            ->and((float) $receiptLine->quantity)->toBe(50.0)
            ->and($receiptLine->item_id)->toBe($this->item->id)
            ->and($receiptLine->location_id)->toBe($this->location->id);
    });

    it('creates stock movement on receive', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->state(['quantity' => 100, 'unit_cost' => 10.00]),
                'lines'
            )
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 50,
            locationId: $this->location->id,
        );

        $action = App::make(ReceiveLine::class);
        $receiptLine = $action->execute($data);

        expect($receiptLine->stockMovement)->toBeInstanceOf(StockMovement::class)
            ->and($receiptLine->stockMovement->type)->toBe('purchase')
            ->and((float) $receiptLine->stockMovement->quantity)->toBe(50.0)
            ->and((float) $receiptLine->stockMovement->cost)->toBe(10.0);
    });

    it('updates stock levels on receive', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->state(['quantity' => 100, 'unit_cost' => 10.00]),
                'lines'
            )
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 50,
            locationId: $this->location->id,
        );

        $action = App::make(ReceiveLine::class);
        $action->execute($data);

        /** @var Stock|null */
        $stock = Stock::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();

        expect($stock)->not->toBeNull()
            ->and((float) $stock->quantity)->toBe(50.0);
    });

    it('updates PO status to partial', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->state(['quantity' => 100]),
                'lines'
            )
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 50,
            locationId: $this->location->id,
        );

        $action = App::make(ReceiveLine::class);
        $action->execute($data);

        $po->refresh();
        expect($po->status)->toBe('partial');
    });

    it('updates PO status to received when complete', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->state(['quantity' => 100]),
                'lines'
            )
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 100,
            locationId: $this->location->id,
        );

        $action = App::make(ReceiveLine::class);
        $action->execute($data);

        $po->refresh();
        expect($po->status)->toBe('received');
    });

    it('creates receipt with lot number', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id), 'lines')
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 10,
            locationId: $this->location->id,
            lotNumber: 'LOT-2026-001',
        );

        $action = App::make(ReceiveLine::class);
        $receiptLine = $action->execute($data);

        expect($receiptLine->lot_number)->toBe('LOT-2026-001')
            ->and($receiptLine->stockMovement->lot_number)->toBe('LOT-2026-001');
    });

    it('creates receipt with serial number', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id), 'lines')
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 1,
            locationId: $this->location->id,
            serialNumber: 'SN-12345',
        );

        $action = App::make(ReceiveLine::class);
        $receiptLine = $action->execute($data);

        expect($receiptLine->serial_number)->toBe('SN-12345')
            ->and($receiptLine->stockMovement->serial_number)->toBe('SN-12345');
    });

    it('prevents over-receiving', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->state(['quantity' => 100]),
                'lines'
            )
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 150,
            locationId: $this->location->id,
        );

        $action = App::make(ReceiveLine::class);

        expect(fn () => $action->execute($data))
            ->toThrow(InvalidArgumentException::class, 'pending');
    });

    it('cannot receive from draft PO', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->draft()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id), 'lines')
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 10,
            locationId: $this->location->id,
        );

        $action = App::make(ReceiveLine::class);

        expect(fn () => $action->execute($data))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('ReceiveMultipleLines', function () {
    it('receives multiple lines at once', function () {
        $item2 = Item::factory()->create();

        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->state(['quantity' => 100]),
                'lines'
            )
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($item2->id)
                    ->state(['quantity' => 50]),
                'lines'
            )
            ->create();

        $lines = $po->lines;

        $action = App::make(ReceiveMultipleLines::class);
        $receipt = $action->execute($po, [
            new ReceiveLineData(
                purchaseOrderLineId: $lines[0]->id,
                quantity: 100,
                locationId: $this->location->id,
            ),
            new ReceiveLineData(
                purchaseOrderLineId: $lines[1]->id,
                quantity: 50,
                locationId: $this->location->id,
            ),
        ]);

        expect($receipt)->toBeInstanceOf(Receipt::class)
            ->and($receipt->lines)->toHaveCount(2)
            ->and($receipt->receipt_number)->toStartWith('RCV-');

        $po->refresh();
        expect($po->status)->toBe('received');
    });

    it('creates single receipt for multiple lines', function () {
        $item2 = Item::factory()->create();

        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id), 'lines')
            ->has(PurchaseOrderLine::factory()->forItem($item2->id), 'lines')
            ->create();

        $lines = $po->lines;

        $action = App::make(ReceiveMultipleLines::class);
        $receipt = $action->execute($po, [
            new ReceiveLineData(
                purchaseOrderLineId: $lines[0]->id,
                quantity: 10,
                locationId: $this->location->id,
            ),
            new ReceiveLineData(
                purchaseOrderLineId: $lines[1]->id,
                quantity: 10,
                locationId: $this->location->id,
            ),
        ]);

        expect(Receipt::count())->toBe(1)
            ->and($receipt->lines)->toHaveCount(2);
    });

    it('receives from array format', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id)->state(['quantity' => 100]), 'lines')
            ->create();

        $poLine = $po->lines->first();

        $action = App::make(ReceiveMultipleLines::class);
        $receipt = $action->execute($po, [
            [
                'purchase_order_line_id' => $poLine->id,
                'quantity' => 50,
                'location_id' => $this->location->id,
            ],
        ]);

        expect($receipt->lines)->toHaveCount(1)
            ->and((float) $receipt->lines->first()->quantity)->toBe(50.0);
    });

    it('fails with empty lines', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->create();

        $action = App::make(ReceiveMultipleLines::class);

        expect(fn () => $action->execute($po, []))
            ->toThrow(InvalidArgumentException::class, 'No lines');
    });
});

describe('Receipt Model', function () {
    it('belongs to purchase order', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id), 'lines')
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 10,
            locationId: $this->location->id,
        );

        $action = App::make(ReceiveLine::class);
        $receiptLine = $action->execute($data);

        $receipt = $receiptLine->receipt;

        expect($receipt->purchaseOrder)->toBeInstanceOf(PurchaseOrder::class)
            ->and($receipt->purchase_order_id)->toBe($po->id);
    });
});

describe('ReceiptLine Model', function () {
    it('has all required relationships', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id), 'lines')
            ->create();

        $poLine = $po->lines->first();

        $data = new ReceiveLineData(
            purchaseOrderLineId: $poLine->id,
            quantity: 10,
            locationId: $this->location->id,
        );

        $action = App::make(ReceiveLine::class);
        $receiptLine = $action->execute($data);

        expect($receiptLine->receipt)->toBeInstanceOf(Receipt::class)
            ->and($receiptLine->purchaseOrderLine)->toBeInstanceOf(PurchaseOrderLine::class)
            ->and($receiptLine->location)->toBeInstanceOf(Location::class)
            ->and($receiptLine->stockMovement)->toBeInstanceOf(StockMovement::class);
    });
});
