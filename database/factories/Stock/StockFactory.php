<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Stock;

/**
 * @extends Factory<Stock>
 */
final class StockFactory extends Factory
{
    protected $model = Stock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'quantity' => $this->faker->randomFloat(4, 0, 1000),
            'reserved_quantity' => 0,
            'cost_basis' => $this->faker->randomFloat(4, 1, 100),
        ];
    }

    public function forItem(string $itemId): self
    {
        return $this->state(fn (): array => [
            'item_id' => $itemId,
        ]);
    }

    public function forLocation(Location $location): self
    {
        return $this->state(fn (): array => [
            'location_id' => $location->id,
        ]);
    }

    public function withQuantity(float $quantity): self
    {
        return $this->state(fn (): array => [
            'quantity' => $quantity,
        ]);
    }

    public function withReserved(float $reserved): self
    {
        return $this->state(fn (): array => [
            'reserved_quantity' => $reserved,
        ]);
    }

    public function withCost(float $cost): self
    {
        return $this->state(fn (): array => [
            'cost_basis' => $cost,
        ]);
    }

    public function withLotNumber(string $lotNumber): self
    {
        return $this->state(fn (): array => [
            'lot_number' => $lotNumber,
        ]);
    }

    public function withSerialNumber(string $serialNumber): self
    {
        return $this->state(fn (): array => [
            'serial_number' => $serialNumber,
            'quantity' => 1,
        ]);
    }

    public function withExpiry(\DateTimeInterface $date): self
    {
        return $this->state(fn (): array => [
            'expiry_date' => $date,
        ]);
    }

    public function empty(): self
    {
        return $this->state(fn (): array => [
            'quantity' => 0,
            'reserved_quantity' => 0,
        ]);
    }

    public function fullyReserved(): self
    {
        return $this->state(function (): array {
            $quantity = $this->faker->randomFloat(4, 10, 100);

            return [
                'quantity' => $quantity,
                'reserved_quantity' => $quantity,
            ];
        });
    }
}
