<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Purchasing\Data\ReceiveLineData;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\Receipt;
use RobinsonRyan\Inventory\Purchasing\Services\PurchaseOrderService;

final class ReceiveMultipleLines
{
    public function __construct(
        private readonly PurchaseOrderService $service,
        private readonly ReceiveLine $receiveLine,
    ) {}

    /**
     * Receive multiple lines from a purchase order in a single receipt.
     *
     * @param  array<int, ReceiveLineData|array<string, mixed>>  $lines
     */
    public function execute(PurchaseOrder $po, array $lines): Receipt
    {
        if (empty($lines)) {
            throw new InvalidArgumentException('No lines provided for receiving.');
        }

        // Validate PO status
        if (! in_array($po->status, ['submitted', 'partial'], true)) {
            throw new InvalidArgumentException('Can only receive lines from submitted or partial purchase orders.');
        }

        return DB::transaction(function () use ($po, $lines): Receipt {
            // Create receipt
            /** @var Receipt */
            $receipt = $this->getReceiptModel()::query()->create([
                'tenant_id' => $po->tenant_id,
                'purchase_order_id' => $po->id,
                'receipt_number' => $this->service->generateReceiptNumber($po->tenant_id),
                'received_at' => now(),
            ]);

            // Process each line
            foreach ($lines as $lineData) {
                if (is_array($lineData)) {
                    $lineData = ReceiveLineData::fromArray($lineData);
                }

                $this->receiveLine->execute($lineData, $receipt);
            }

            // Update PO status
            $this->service->updateStatus($po);

            return $receipt->fresh(['lines', 'purchaseOrder']);
        });
    }

    /**
     * @return class-string<Receipt>
     */
    private function getReceiptModel(): string
    {
        return config('inventory.models.receipt', Receipt::class);
    }
}
