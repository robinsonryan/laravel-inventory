<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Purchasing\Data\ReceiveLineData;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine;
use RobinsonRyan\Inventory\Purchasing\Models\Receipt;
use RobinsonRyan\Inventory\Purchasing\Models\ReceiptLine;
use RobinsonRyan\Inventory\Purchasing\Services\PurchaseOrderService;
use RobinsonRyan\Inventory\Stock\Actions\AddStock;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;

final readonly class ReceiveLine
{
    public function __construct(
        private PurchaseOrderService $service,
        private AddStock $addStock,
    ) {}

    /**
     * Receive a single line from a purchase order.
     */
    public function execute(ReceiveLineData $data, ?Receipt $receipt = null): ReceiptLine
    {
        return DB::transaction(function () use ($data, $receipt): ReceiptLine {
            // Get the PO line
            /** @var PurchaseOrderLine */
            $poLine = $this->getPurchaseOrderLineModel()::query()->findOrFail($data->purchaseOrderLineId);

            /** @var PurchaseOrder */
            $po = $poLine->purchaseOrder;

            // Validate PO status
            if (! in_array($po->status, ['submitted', 'partial'], true)) {
                throw new InvalidArgumentException('Can only receive lines from submitted or partial purchase orders.');
            }

            // Validate quantity
            $pending = $poLine->getQuantityPending();
            if ($data->quantity > $pending) {
                throw new InvalidArgumentException(
                    "Cannot receive {$data->quantity} units. Only {$pending} pending.",
                );
            }

            // Create receipt if not provided
            if (! $receipt instanceof Receipt) {
                /** @var Receipt */
                $receipt = $this->getReceiptModel()::query()->create([
                    'tenant_id' => $po->tenant_id,
                    'purchase_order_id' => $po->id,
                    'receipt_number' => $this->service->generateReceiptNumber($po->tenant_id),
                    'received_at' => now(),
                ]);
            }

            // Create stock movement
            $movement = $this->addStock->execute(new AddStockData(
                itemId: $poLine->item_id,
                locationId: $data->locationId,
                quantity: $data->quantity,
                type: 'purchase',
                cost: (float) $poLine->unit_cost,
                referenceType: 'purchase_order_line',
                referenceId: $poLine->id,
                lotNumber: $data->lotNumber,
                serialNumber: $data->serialNumber,
                notes: $data->notes,
            ));

            // Create receipt line
            /** @var ReceiptLine */
            $receiptLine = ReceiptLine::query()->create([
                'receipt_id' => $receipt->id,
                'purchase_order_line_id' => $poLine->id,
                'item_id' => $poLine->item_id,
                'location_id' => $data->locationId,
                'quantity' => $data->quantity,
                'lot_number' => $data->lotNumber,
                'serial_number' => $data->serialNumber,
                'stock_movement_id' => $movement->id,
                'notes' => $data->notes,
            ]);

            // Update PO line received quantity
            $poLine->quantity_received = (string) ((float) $poLine->quantity_received + $data->quantity);
            $poLine->save();

            // Update PO status
            $this->service->updateStatus($po);

            return $receiptLine->load(['receipt', 'purchaseOrderLine', 'stockMovement']);
        });
    }

    /**
     * @return class-string<PurchaseOrderLine>
     */
    private function getPurchaseOrderLineModel(): string
    {
        return config('inventory.models.purchase_order_line', PurchaseOrderLine::class);
    }

    /**
     * @return class-string<Receipt>
     */
    private function getReceiptModel(): string
    {
        return config('inventory.models.receipt', Receipt::class);
    }
}
