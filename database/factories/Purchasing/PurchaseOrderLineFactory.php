<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Purchasing;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine;
use Symfony\Component\Uid\Uuid;

/**
 * @extends Factory<PurchaseOrderLine>
 */
final class PurchaseOrderLineFactory extends Factory
{
    protected $model = PurchaseOrderLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = $this->faker->randomFloat(4, 1, 100);
        $unitCost = $this->faker->randomFloat(4, 1, 500);

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'item_id' => (string) Uuid::v7(),
            'description' => $this->faker->optional()->sentence(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total' => $quantity * $unitCost,
            'quantity_received' => 0,
            'quantity_cancelled' => 0,
            'notes' => $this->faker->optional()->sentence(),
            'sort_order' => 0,
        ];
    }

    public function forPurchaseOrder(PurchaseOrder $po): self
    {
        return $this->state(fn (): array => [
            'purchase_order_id' => $po->id,
        ]);
    }

    public function forItem(string $itemId): self
    {
        return $this->state(fn (): array => [
            'item_id' => $itemId,
        ]);
    }

    public function withDestination(string $locationId): self
    {
        return $this->state(fn (): array => [
            'destination_location_id' => $locationId,
        ]);
    }

    public function partiallyReceived(float $receivedPercentage = 0.5): self
    {
        return $this->state(function (array $attributes) use ($receivedPercentage): array {
            $quantity = (float) $attributes['quantity'];

            return [
                'quantity_received' => $quantity * $receivedPercentage,
            ];
        });
    }

    public function fullyReceived(): self
    {
        return $this->state(fn (array $attributes): array => [
            'quantity_received' => $attributes['quantity'],
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'quantity_cancelled' => $attributes['quantity'],
        ]);
    }
}
