<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Database\Factories\Reconciliation;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Stock\Models\Location;

/**
 * @extends Factory<CycleCount>
 */
final class CycleCountFactory extends Factory
{
    protected $model = CycleCount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'count_number' => $this->faker->unique()->bothify('CC-########-####'),
            'location_id' => Location::factory(),
            'scheduled_date' => $this->faker->optional()->dateTimeBetween('now', '+1 week')?->format('Y-m-d'),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function draft(): self
    {
        return $this->afterCreating(function (CycleCount $count): void {
            $count->status = 'draft';
        });
    }

    public function inProgress(): self
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subHours($this->faker->numberBetween(1, 24)),
        ])->afterCreating(function (CycleCount $count): void {
            $count->status = 'in_progress';
        });
    }

    public function pending(): self
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subDays($this->faker->numberBetween(1, 3)),
            'completed_at' => now()->subHours($this->faker->numberBetween(1, 12)),
        ])->afterCreating(function (CycleCount $count): void {
            $count->status = 'pending';
        });
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subDays($this->faker->numberBetween(3, 7)),
            'completed_at' => now()->subDays($this->faker->numberBetween(1, 3)),
            'approved_at' => now(),
        ])->afterCreating(function (CycleCount $count): void {
            $count->status = 'approved';
        });
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'metadata' => [
                'cancellation_reason' => $this->faker->sentence(),
                'cancelled_at' => now()->toIso8601String(),
            ],
        ])->afterCreating(function (CycleCount $count): void {
            $count->status = 'cancelled';
        });
    }

    public function forLocation(string|Location $location): self
    {
        $locationId = $location instanceof Location ? $location->id : $location;

        return $this->state(fn (): array => [
            'location_id' => $locationId,
        ]);
    }

    public function forTenant(string $tenantId): self
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenantId,
        ]);
    }

    public function scheduled(DateTimeInterface $date): self
    {
        return $this->state(fn (): array => [
            'scheduled_date' => $date,
        ]);
    }

    public function withNotes(string $notes): self
    {
        return $this->state(fn (): array => [
            'notes' => $notes,
        ]);
    }
}
