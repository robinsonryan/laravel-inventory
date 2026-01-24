<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Purchasing\Services\PurchaseOrderService;

describe('PurchaseOrderService', function () {
    it('can be resolved from container', function () {
        $service = App::make(PurchaseOrderService::class);

        expect($service)->toBeInstanceOf(PurchaseOrderService::class);
    });

    it('generates PO number with correct format', function () {
        $service = App::make(PurchaseOrderService::class);
        $poNumber = $service->generatePoNumber();

        expect($poNumber)->toMatch('/^PO-\d{8}-\d{4}$/');
    });

    it('generates receipt number with correct format', function () {
        $service = App::make(PurchaseOrderService::class);
        $receiptNumber = $service->generateReceiptNumber();

        expect($receiptNumber)->toMatch('/^RCV-\d{8}-\d{4}$/');
    });
});
