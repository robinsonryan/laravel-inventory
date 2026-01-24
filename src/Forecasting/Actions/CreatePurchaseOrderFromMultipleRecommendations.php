<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Forecasting\Actions;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Forecasting\Models\ReorderRecommendation;

final class CreatePurchaseOrderFromMultipleRecommendations
{
    /**
     * Create a PO from multiple recommendations (same vendor).
     *
     * @param  array<int, string>  $recommendationIds
     *
     * @throws \InvalidArgumentException If any recommendation is invalid
     */
    public function execute(array $recommendationIds, string $vendorId): Model
    {
        if (empty($recommendationIds)) {
            throw new \InvalidArgumentException('At least one recommendation ID must be provided.');
        }

        $recommendations = $this->getRecommendationModel()::query()
            ->whereIn('id', $recommendationIds)
            ->get();

        if ($recommendations->count() !== count($recommendationIds)) {
            throw new \InvalidArgumentException('One or more recommendation IDs are invalid.');
        }

        // Validate all recommendations are pending
        foreach ($recommendations as $recommendation) {
            if ($recommendation->isActioned()) {
                throw new \InvalidArgumentException(
                    "Recommendation {$recommendation->id} has already been actioned."
                );
            }

            if ($recommendation->isDismissed()) {
                throw new \InvalidArgumentException(
                    "Recommendation {$recommendation->id} has been dismissed."
                );
            }
        }

        return DB::transaction(function () use ($recommendations, $vendorId) {
            // Create the purchase order
            /** @var Model */
            $purchaseOrder = $this->getPurchaseOrderModel()::query()->create([
                'vendor_id' => $vendorId,
                'notes' => 'Auto-generated from multiple reorder recommendations',
            ]);

            // Create purchase order lines for each recommendation
            foreach ($recommendations as $recommendation) {
                $this->getPurchaseOrderLineModel()::query()->create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'item_id' => $recommendation->item_id,
                    'quantity_ordered' => $recommendation->recommended_quantity,
                ]);

                // Mark recommendation as actioned
                $recommendation->actioned_at = Carbon::now();
                $recommendation->purchase_order_id = $purchaseOrder->id;
                $recommendation->save();
            }

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

    /**
     * @return class-string<ReorderRecommendation>
     */
    private function getRecommendationModel(): string
    {
        return config('inventory.models.reorder_recommendation', ReorderRecommendation::class);
    }
}
