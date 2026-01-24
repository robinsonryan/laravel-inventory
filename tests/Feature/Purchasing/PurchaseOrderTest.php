<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Purchasing\Actions\CancelPurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Actions\CreatePurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Actions\SubmitPurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Actions\UpdatePurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Data\PurchaseOrderData;
use RobinsonRyan\Inventory\Purchasing\Data\PurchaseOrderLineData;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine;
use RobinsonRyan\Inventory\Purchasing\Services\PurchaseOrderService;
use RobinsonRyan\Inventory\Purchasing\TagDefinitions\PurchaseOrderStatusDefinition;
use RobinsonRyan\Vendor\Models\Vendor;

beforeEach(function () {
    $this->service = App::make(PurchaseOrderService::class);
    $this->vendor = Vendor::factory()->create();
    $this->item = Item::factory()->create();
});

describe('CreatePurchaseOrder', function () {
    it('creates a purchase order', function () {
        $data = new PurchaseOrderData(
            vendorId: $this->vendor->id,
            notes: 'Test purchase order',
        );

        $action = App::make(CreatePurchaseOrder::class);
        $po = $action->execute($data);

        expect($po)->toBeInstanceOf(PurchaseOrder::class)
            ->and($po->vendor_id)->toBe($this->vendor->id)
            ->and($po->status)->toBe('draft')
            ->and($po->notes)->toBe('Test purchase order')
            ->and($po->po_number)->toStartWith('PO-');
    });

    it('creates a purchase order with lines', function () {
        $data = new PurchaseOrderData(
            vendorId: $this->vendor->id,
            lines: [
                new PurchaseOrderLineData(
                    itemId: $this->item->id,
                    quantity: 10,
                    unitCost: 25.50,
                    description: 'Test item',
                ),
            ],
        );

        $action = App::make(CreatePurchaseOrder::class);
        $po = $action->execute($data);

        expect($po->lines)->toHaveCount(1)
            ->and($po->lines->first()->item_id)->toBe($this->item->id)
            ->and((float) $po->lines->first()->quantity)->toBe(10.0)
            ->and((float) $po->lines->first()->unit_cost)->toBe(25.50)
            ->and((float) $po->lines->first()->total)->toBe(255.0);
    });

    it('calculates totals correctly', function () {
        $data = new PurchaseOrderData(
            vendorId: $this->vendor->id,
            tax: 50.00,
            shipping: 25.00,
            lines: [
                new PurchaseOrderLineData(
                    itemId: $this->item->id,
                    quantity: 10,
                    unitCost: 100.00,
                ),
            ],
        );

        $action = App::make(CreatePurchaseOrder::class);
        $po = $action->execute($data);

        expect((float) $po->subtotal)->toBe(1000.00)
            ->and((float) $po->tax)->toBe(50.00)
            ->and((float) $po->shipping)->toBe(25.00)
            ->and((float) $po->total)->toBe(1075.00);
    });

    it('auto-generates PO number if not provided', function () {
        $data = new PurchaseOrderData(vendorId: $this->vendor->id);

        $action = App::make(CreatePurchaseOrder::class);
        $po = $action->execute($data);

        expect($po->po_number)->toMatch('/^PO-\d{8}-\d{4}$/');
    });

    it('uses custom PO number if provided', function () {
        $data = new PurchaseOrderData(
            vendorId: $this->vendor->id,
            poNumber: 'CUSTOM-PO-001',
        );

        $action = App::make(CreatePurchaseOrder::class);
        $po = $action->execute($data);

        expect($po->po_number)->toBe('CUSTOM-PO-001');
    });
});

describe('UpdatePurchaseOrder', function () {
    it('updates a draft purchase order', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->draft()
            ->create();

        $vendor2 = Vendor::factory()->create();

        $data = new PurchaseOrderData(
            vendorId: $vendor2->id,
            notes: 'Updated notes',
        );

        $action = App::make(UpdatePurchaseOrder::class);
        $updated = $action->execute($po, $data);

        expect($updated->vendor_id)->toBe($vendor2->id)
            ->and($updated->notes)->toBe('Updated notes');
    });

    it('cannot update a received purchase order', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->received()
            ->create();

        $data = new PurchaseOrderData(vendorId: $this->vendor->id);
        $action = App::make(UpdatePurchaseOrder::class);

        expect(fn () => $action->execute($po, $data))
            ->toThrow(InvalidArgumentException::class);
    });

    it('cannot update a cancelled purchase order', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->cancelled()
            ->create();

        $data = new PurchaseOrderData(vendorId: $this->vendor->id);
        $action = App::make(UpdatePurchaseOrder::class);

        expect(fn () => $action->execute($po, $data))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('SubmitPurchaseOrder', function () {
    it('submits a draft purchase order', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->draft()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id), 'lines')
            ->create();

        $action = App::make(SubmitPurchaseOrder::class);
        $submitted = $action->execute($po);

        expect($submitted->status)->toBe('submitted')
            ->and($submitted->submitted_at)->not->toBeNull();
    });

    it('cannot submit without lines', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->draft()
            ->create();

        $action = App::make(SubmitPurchaseOrder::class);

        expect(fn () => $action->execute($po))
            ->toThrow(InvalidArgumentException::class, 'no lines');
    });

    it('cannot submit already submitted order', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->create();

        $action = App::make(SubmitPurchaseOrder::class);

        expect(fn () => $action->execute($po))
            ->toThrow(InvalidArgumentException::class, 'draft');
    });
});

describe('CancelPurchaseOrder', function () {
    it('cancels a draft purchase order', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->draft()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id), 'lines')
            ->create();

        $action = App::make(CancelPurchaseOrder::class);
        $cancelled = $action->execute($po, 'Changed mind');

        expect($cancelled->status)->toBe('cancelled')
            ->and($cancelled->cancelled_at)->not->toBeNull()
            ->and($cancelled->metadata['cancellation_reason'])->toBe('Changed mind');
    });

    it('cancels remaining quantities on lines', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->state(['quantity' => 100, 'quantity_received' => 25]),
                'lines'
            )
            ->create();

        $action = App::make(CancelPurchaseOrder::class);
        $cancelled = $action->execute($po);

        expect((float) $cancelled->lines->first()->quantity_cancelled)->toBe(75.0);
    });

    it('cannot cancel a received order', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->received()
            ->create();

        $action = App::make(CancelPurchaseOrder::class);

        expect(fn () => $action->execute($po))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('PurchaseOrderService', function () {
    it('generates sequential PO numbers', function () {
        $po1 = $this->service->generatePoNumber();
        $po2 = $this->service->generatePoNumber();

        expect($po1)->toMatch('/^PO-\d{8}-0001$/')
            ->and($po2)->toMatch('/^PO-\d{8}-0001$/'); // Still 0001 since no PO was created yet

        // Create a PO with the first number
        PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->state(['po_number' => $po1])
            ->create();

        $po3 = $this->service->generatePoNumber();
        expect($po3)->toMatch('/^PO-\d{8}-0002$/');
    });

    it('checks if PO is fully received', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->fullyReceived(),
                'lines'
            )
            ->create();

        expect($this->service->isFullyReceived($po))->toBeTrue();
    });

    it('checks if PO is not fully received', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(
                PurchaseOrderLine::factory()
                    ->forItem($this->item->id)
                    ->partiallyReceived(0.5),
                'lines'
            )
            ->create();

        expect($this->service->isFullyReceived($po))->toBeFalse();
    });

    it('gets open orders for vendor', function () {
        // Create various POs for this vendor
        PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->draft()
            ->create();

        PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->create();

        PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->received()
            ->create();

        $open = $this->service->getOpenForVendor($this->vendor->id);

        expect($open)->toHaveCount(2);
    });

    it('gets pending lines', function () {
        $po = PurchaseOrder::factory()
            ->forVendor($this->vendor->id)
            ->submitted()
            ->has(PurchaseOrderLine::factory()->forItem($this->item->id)->fullyReceived(), 'lines')
            ->has(PurchaseOrderLine::factory()->forItem(Item::factory()->create()->id), 'lines')
            ->create();

        $pending = $this->service->getPendingLines($po);

        expect($pending)->toHaveCount(1);
    });
});

describe('PurchaseOrderLine Model', function () {
    it('calculates pending quantity', function () {
        $line = PurchaseOrderLine::factory()->state([
            'quantity' => 100,
            'quantity_received' => 25,
            'quantity_cancelled' => 10,
        ])->make();

        expect($line->getQuantityPending())->toBe(65.0);
    });

    it('identifies fully received line', function () {
        $line = PurchaseOrderLine::factory()->state([
            'quantity' => 100,
            'quantity_received' => 100,
        ])->make();

        expect($line->isFullyReceived())->toBeTrue();
    });
});

describe('PurchaseOrderStatusDefinition', function () {
    it('has correct slug', function () {
        expect(PurchaseOrderStatusDefinition::$slug)->toBe('purchase-order-status');
    });

    it('is single select', function () {
        expect(PurchaseOrderStatusDefinition::$singleSelect)->toBeTrue();
    });

    it('is global', function () {
        expect(PurchaseOrderStatusDefinition::$global)->toBeTrue();
    });

    it('has expected statuses', function () {
        expect(PurchaseOrderStatusDefinition::DEFAULT_VALUES)
            ->toContain('draft')
            ->toContain('submitted')
            ->toContain('partial')
            ->toContain('received')
            ->toContain('cancelled');
    });

    it('defines open statuses', function () {
        expect(PurchaseOrderStatusDefinition::OPEN)
            ->toContain('draft')
            ->toContain('submitted')
            ->toContain('partial')
            ->not->toContain('received')
            ->not->toContain('cancelled');
    });

    it('defines terminal statuses', function () {
        expect(PurchaseOrderStatusDefinition::TERMINAL)
            ->toContain('received')
            ->toContain('cancelled')
            ->not->toContain('draft')
            ->not->toContain('submitted');
    });
});
