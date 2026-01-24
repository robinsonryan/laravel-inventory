<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Purchasing;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use Symfony\Component\Uid\Uuid;

/**
 * @extends Factory<PurchaseOrder>
 */
final class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => (string) Uuid::v7(),
            'po_number' => $this->faker->unique()->bothify('PO-########-####'),
            'order_date' => $this->faker->date(),
            'expected_date' => $this->faker->optional()->dateTimeBetween('+1 week', '+4 weeks')?->format('Y-m-d'),
            'subtotal' => $this->faker->randomFloat(2, 100, 10000),
            'tax' => $this->faker->randomFloat(2, 0, 500),
            'shipping' => $this->faker->randomFloat(2, 0, 200),
            'total' => function (array $attributes) {
                return (float) $attributes['subtotal'] + (float) $attributes['tax'] + (float) $attributes['shipping'];
            },
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function draft(): self
    {
        return $this->afterCreating(function (PurchaseOrder $po): void {
            $po->status = 'draft';
        });
    }

    public function submitted(): self
    {
        return $this->state(fn (): array => [
            'submitted_at' => now(),
        ])->afterCreating(function (PurchaseOrder $po): void {
            $po->status = 'submitted';
        });
    }

    public function partial(): self
    {
        return $this->state(fn (): array => [
            'submitted_at' => now()->subDays($this->faker->numberBetween(1, 10)),
        ])->afterCreating(function (PurchaseOrder $po): void {
            $po->status = 'partial';
        });
    }

    public function received(): self
    {
        return $this->state(fn (): array => [
            'submitted_at' => now()->subDays($this->faker->numberBetween(10, 30)),
        ])->afterCreating(function (PurchaseOrder $po): void {
            $po->status = 'received';
        });
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'cancelled_at' => now(),
        ])->afterCreating(function (PurchaseOrder $po): void {
            $po->status = 'cancelled';
        });
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

    public function withShippingAddress(): self
    {
        return $this->state(fn (): array => [
            'shipping_address' => [
                'line1' => $this->faker->streetAddress(),
                'line2' => $this->faker->optional()->secondaryAddress(),
                'city' => $this->faker->city(),
                'state' => $this->faker->stateAbbr(),
                'postal' => $this->faker->postcode(),
                'country' => 'US',
            ],
        ]);
    }
}
