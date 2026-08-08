<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Forecasting\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Forecasting\Models\ReorderRecommendation;

final class CreatePurchaseOrderFromRecommendation
{
    /**
     * Create a PO from a recommendation.
     *
     * @throws InvalidArgumentException If recommendation is already actioned or dismissed
     */
    public function execute(
        ReorderRecommendation $recommendation,
        ?string $vendorId = null,
    ): Model {
        if ($recommendation->isActioned()) {
            throw new InvalidArgumentException('Cannot create PO from a recommendation that has already been actioned.');
        }

        if ($recommendation->isDismissed()) {
            throw new InvalidArgumentException('Cannot create PO from a dismissed recommendation.');
        }

        $effectiveVendorId = $vendorId ?? $recommendation->vendor_id;

        if ($effectiveVendorId === null) {
            throw new InvalidArgumentException('A vendor ID must be provided to create a purchase order.');
        }

        return DB::transaction(function () use ($recommendation, $effectiveVendorId) {
            // Create the purchase order
            /** @var Model */
            $purchaseOrder = $this->getPurchaseOrderModel()::query()->create([
                'vendor_id' => $effectiveVendorId,
                'notes' => "Auto-generated from reorder recommendation for item: {$recommendation->item_id}",
            ]);

            // Create the purchase order line
            $this->getPurchaseOrderLineModel()::query()->create([
                'purchase_order_id' => $purchaseOrder->getKey(),
                'item_id' => $recommendation->item_id,
                'quantity_ordered' => $recommendation->recommended_quantity,
            ]);

            // Mark recommendation as actioned
            $recommendation->actioned_at = Carbon::now();
            $recommendation->purchase_order_id = (string) $purchaseOrder->getKey();
            $recommendation->save();

            return $purchaseOrder;
        });
    }

    /**
     * @return class-string<Model>
     */
    private function getPurchaseOrderModel(): string
    {
        return config('inventory.models.purchase_order');
    }

    /**
     * @return class-string<Model>
     */
    private function getPurchaseOrderLineModel(): string
    {
        return config('inventory.models.purchase_order_line');
    }
}
