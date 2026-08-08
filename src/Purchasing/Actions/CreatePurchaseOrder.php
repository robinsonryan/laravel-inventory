<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Purchasing\Data\PurchaseOrderData;
use RobinsonRyan\Inventory\Purchasing\Data\PurchaseOrderLineData;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine;
use RobinsonRyan\Inventory\Purchasing\Services\PurchaseOrderService;

final readonly class CreatePurchaseOrder
{
    public function __construct(
        private PurchaseOrderService $service,
    ) {}

    /**
     * Create a new purchase order.
     */
    public function execute(PurchaseOrderData $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $poNumber = $data->poNumber ?? $this->service->generatePoNumber();

            /** @var PurchaseOrder */
            $po = $this->getPurchaseOrderModel()::query()->create([
                'vendor_id' => $data->vendorId,
                'po_number' => $poNumber,
                'order_date' => $data->orderDate ?? now()->toDateString(),
                'expected_date' => $data->expectedDate,
                'tax' => $data->tax ?? 0,
                'shipping' => $data->shipping ?? 0,
                'shipping_address' => $data->shippingAddress,
                'notes' => $data->notes,
                'metadata' => $data->metadata,
                'created_by' => $this->getActorId(),
            ]);

            $po->status = 'draft';

            // Create lines
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

            // Calculate totals
            $this->service->calculateTotals($po);

            return $po->fresh(['lines', 'vendor']) ?? $po;
        });
    }

    private function getActorId(): ?string
    {
        $guard = config('inventory.actor.guard', 'web');
        $user = Auth::guard($guard)->user();

        return $user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null;
    }

    /**
     * @return class-string<PurchaseOrder>
     */
    private function getPurchaseOrderModel(): string
    {
        return config('inventory.models.purchase_order', PurchaseOrder::class);
    }

    /**
     * @return class-string<PurchaseOrderLine>
     */
    private function getPurchaseOrderLineModel(): string
    {
        return config('inventory.models.purchase_order_line', PurchaseOrderLine::class);
    }
}
