<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Purchasing\Data\PurchaseOrderData;
use RobinsonRyan\Inventory\Purchasing\Data\PurchaseOrderLineData;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine;
use RobinsonRyan\Inventory\Purchasing\Services\PurchaseOrderService;
use RobinsonRyan\Inventory\Purchasing\TagDefinitions\PurchaseOrderStatusDefinition;

final class UpdatePurchaseOrder
{
    public function __construct(
        private readonly PurchaseOrderService $service,
    ) {}

    /**
     * Update a purchase order.
     */
    public function execute(PurchaseOrder $po, PurchaseOrderData $data): PurchaseOrder
    {
        if (in_array($po->status, PurchaseOrderStatusDefinition::TERMINAL, true)) {
            throw new InvalidArgumentException('Cannot update a purchase order that is received or cancelled.');
        }

        return DB::transaction(function () use ($po, $data): PurchaseOrder {
            // Update PO attributes
            $po->update([
                'vendor_id' => $data->vendorId,
                'expected_date' => $data->expectedDate,
                'tax' => $data->tax ?? $po->tax,
                'shipping' => $data->shipping ?? $po->shipping,
                'shipping_address' => $data->shippingAddress ?? $po->shipping_address,
                'notes' => $data->notes ?? $po->notes,
                'metadata' => $data->metadata ?? $po->metadata,
            ]);

            // Update lines if provided
            if (! empty($data->lines)) {
                // Remove existing lines (only if PO is in draft)
                if ($po->status === 'draft') {
                    $po->lines()->delete();

                    // Create new lines
                    $sortOrder = 0;
                    foreach ($data->lines as $lineData) {
                        if (is_array($lineData)) {
                            $lineData = PurchaseOrderLineData::fromArray($lineData);
                        }

                        $this->getPurchaseOrderLineModel()::query()->create([
                            'purchase_order_id' => $po->id,
                            'item_id' => $lineData->itemId,
                            'description' => $lineData->description,
                            'quantity' => $lineData->quantity,
                            'unit_cost' => $lineData->unitCost,
                            'total' => $lineData->getTotal(),
                            'destination_location_id' => $lineData->destinationLocationId,
                            'notes' => $lineData->notes,
                            'sort_order' => $sortOrder++,
                        ]);
                    }
                }
            }

            // Recalculate totals
            $this->service->calculateTotals($po);

            return $po->fresh(['lines', 'vendor']);
        });
    }

    /**
     * @return class-string<PurchaseOrderLine>
     */
    private function getPurchaseOrderLineModel(): string
    {
        return config('inventory.models.purchase_order_line', PurchaseOrderLine::class);
    }
}
