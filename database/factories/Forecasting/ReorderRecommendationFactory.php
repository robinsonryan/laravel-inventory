<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Forecasting;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Forecasting\Models\ReorderRecommendation;

/**
 * @extends Factory<ReorderRecommendation>
 */
final class ReorderRecommendationFactory extends Factory
{
    protected $model = ReorderRecommendation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currentQuantity = $this->faker->randomFloat(4, 0, 50);
        $reservedQuantity = $this->faker->randomFloat(4, 0, min($currentQuantity, 20));
        $availableQuantity = $currentQuantity - $reservedQuantity;

        return [
            'current_quantity' => $currentQuantity,
            'reserved_quantity' => $reservedQuantity,
            'available_quantity' => $availableQuantity,
            'min_stock_level' => $this->faker->numberBetween(10, 100),
            'recommended_quantity' => $this->faker->numberBetween(50, 500),
            'estimated_stockout_date' => $this->faker->optional()->dateTimeBetween('now', '+30 days'),
            'average_daily_usage' => $this->faker->randomFloat(4, 0.5, 10),
            'lead_time_days' => $this->faker->numberBetween(1, 14),
            'generated_at' => now(),
        ];
    }

    public function forItem(string $itemId): self
    {
        return $this->state(fn (): array => [
            'item_id' => $itemId,
        ]);
    }

    public function forVendor(string $vendorId): self
    {
        return $this->state(fn (): array => [
            'vendor_id' => $vendorId,
        ]);
    }

    public function forTenant(string $tenantId): self
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenantId,
        ]);
    }

    public function pending(): self
    {
        return $this->state(fn (): array => [
            'actioned_at' => null,
            'dismissed_at' => null,
            'purchase_order_id' => null,
        ]);
    }

    public function actioned(string $purchaseOrderId): self
    {
        return $this->state(fn (): array => [
            'actioned_at' => now(),
            'dismissed_at' => null,
            'purchase_order_id' => $purchaseOrderId,
        ]);
    }

    public function dismissed(): self
    {
        return $this->state(fn (): array => [
            'dismissed_at' => now(),
            'actioned_at' => null,
            'purchase_order_id' => null,
        ]);
    }

    public function criticalStockout(): self
    {
        return $this->state(fn (): array => [
            'current_quantity' => $this->faker->randomFloat(4, 0, 5),
            'available_quantity' => $this->faker->randomFloat(4, 0, 3),
            'estimated_stockout_date' => $this->faker->dateTimeBetween('now', '+3 days'),
        ]);
    }

    public function withNotes(string $notes): self
    {
        return $this->state(fn (): array => [
            'notes' => $notes,
        ]);
    }

    public function withMetadata(array $metadata): self
    {
        return $this->state(fn (): array => [
            'metadata' => $metadata,
        ]);
    }
}
