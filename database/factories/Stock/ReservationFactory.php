<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Stock;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Models\Stock;

/**
 * @extends Factory<Reservation>
 */
final class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_type' => 'work_order',
            'job_id' => $this->faker->uuid(),
            'quantity' => $this->faker->randomFloat(4, 1, 50),
            'reserved_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHours(72),
        ];
    }

    public function forItem(string $itemId): self
    {
        return $this->state(fn (): array => [
            'item_id' => $itemId,
        ]);
    }

    public function forStock(Stock $stock): self
    {
        return $this->state(fn (): array => [
            'stock_id' => $stock->id,
            'item_id' => $stock->item_id,
            'location_id' => $stock->location_id,
        ]);
    }

    public function forLocation(Location $location): self
    {
        return $this->state(fn (): array => [
            'location_id' => $location->id,
        ]);
    }

    public function forJob(string $type, string $id): self
    {
        return $this->state(fn (): array => [
            'job_type' => $type,
            'job_id' => $id,
        ]);
    }

    public function withQuantity(float $quantity): self
    {
        return $this->state(fn (): array => [
            'quantity' => $quantity,
        ]);
    }

    public function withLineId(string $lineId): self
    {
        return $this->state(fn (): array => [
            'line_id' => $lineId,
        ]);
    }

    public function expiresIn(int $hours): self
    {
        return $this->state(fn (): array => [
            'expires_at' => Carbon::now()->addHours($hours),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => Carbon::now()->subHour(),
        ])->afterCreating(function (Reservation $reservation): void {
            $reservation->status = 'expired';
        });
    }

    public function pending(): self
    {
        return $this->afterCreating(function (Reservation $reservation): void {
            $reservation->status = 'pending';
        });
    }

    public function reserved(): self
    {
        return $this->afterCreating(function (Reservation $reservation): void {
            $reservation->status = 'reserved';
        });
    }

    public function fulfilled(): self
    {
        return $this->state(fn (): array => [
            'fulfilled_at' => Carbon::now(),
        ])->afterCreating(function (Reservation $reservation): void {
            $reservation->status = 'fulfilled';
        });
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'cancelled_at' => Carbon::now(),
        ])->afterCreating(function (Reservation $reservation): void {
            $reservation->status = 'cancelled';
        });
    }

    public function partial(): self
    {
        return $this->afterCreating(function (Reservation $reservation): void {
            $reservation->status = 'partial';
        });
    }
}
