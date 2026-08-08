<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Stock\Data\RemoveStockData;
use RobinsonRyan\Inventory\Stock\Data\ReservationData;
use RobinsonRyan\Inventory\Stock\Models\Reservation;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final readonly class ReservationService
{
    public function __construct(
        private StockService $stockService,
    ) {}

    /**
     * Create a reservation for an item.
     */
    public function reserve(ReservationData $data): Reservation
    {
        return DB::transaction(function () use ($data): Reservation {
            if (! $this->canReserve($data->itemId, $data->quantity, $data->locationId)) {
                throw new InvalidArgumentException(
                    'Insufficient available stock to create reservation.',
                );
            }

            $expiryHours = $data->expiryHours ?? config('inventory.rules.reservation_expiry_hours', 72);
            $expiresAt = Carbon::now()->addHours($expiryHours);

            // Find stock record to reserve from
            $stock = $this->findAvailableStock($data->itemId, $data->quantity, $data->locationId);

            /** @var Reservation */
            $reservation = $this->getReservationModel()::query()->create([
                'item_id' => $data->itemId,
                'stock_id' => $stock?->id,
                'location_id' => $data->locationId ?? $stock?->location_id,
                'job_type' => $data->jobType,
                'job_id' => $data->jobId,
                'line_id' => $data->lineId,
                'quantity' => $data->quantity,
                'reserved_at' => Carbon::now(),
                'expires_at' => $expiresAt,
                'notes' => $data->notes,
            ]);

            $reservation->status = 'reserved';

            // Update reserved quantity on stock
            if ($stock instanceof Stock) {
                $stock->increment('reserved_quantity', $data->quantity);
            }

            return $reservation;
        });
    }

    /**
     * Release a reservation (cancel it without consuming).
     */
    public function release(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            if ($reservation->cancelled_at !== null || $reservation->fulfilled_at !== null) {
                return;
            }

            // Release reserved quantity on stock
            if ($reservation->stock !== null) {
                $reservation->stock->decrement('reserved_quantity', (float) $reservation->quantity);
            }

            $reservation->cancelled_at = Carbon::now();
            $reservation->status = 'cancelled';
            $reservation->save();
        });
    }

    /**
     * Fulfill a reservation (consume the stock).
     */
    public function fulfill(Reservation $reservation): StockMovement
    {
        return DB::transaction(function () use ($reservation): StockMovement {
            if ($reservation->fulfilled_at !== null) {
                throw new InvalidArgumentException('Reservation has already been fulfilled.');
            }

            if ($reservation->cancelled_at !== null) {
                throw new InvalidArgumentException('Cannot fulfill a cancelled reservation.');
            }

            $locationId = $reservation->location_id ?? $reservation->stock?->location_id;

            if ($locationId === null) {
                throw new InvalidArgumentException('Cannot fulfill reservation without a location.');
            }

            // Create consumption movement
            $movement = $this->stockService->removeStock(new RemoveStockData(
                itemId: $reservation->item_id,
                locationId: $locationId,
                quantity: (float) $reservation->quantity,
                type: 'consumption',
                referenceType: $reservation->job_type,
                referenceId: $reservation->job_id,
                notes: "Fulfilled reservation {$reservation->id}",
            ));

            // Release reserved quantity (it's now consumed)
            if ($reservation->stock !== null) {
                $reservation->stock->decrement('reserved_quantity', (float) $reservation->quantity);
            }

            $reservation->fulfilled_at = Carbon::now();
            $reservation->status = 'fulfilled';
            $reservation->save();

            return $movement;
        });
    }

    /**
     * Partially fulfill a reservation.
     */
    public function partialFulfill(Reservation $reservation, float $quantity): StockMovement
    {
        return DB::transaction(function () use ($reservation, $quantity): StockMovement {
            if ($reservation->fulfilled_at !== null) {
                throw new InvalidArgumentException('Reservation has already been fulfilled.');
            }

            if ($reservation->cancelled_at !== null) {
                throw new InvalidArgumentException('Cannot fulfill a cancelled reservation.');
            }

            if ($quantity > (float) $reservation->quantity) {
                throw new InvalidArgumentException('Partial quantity exceeds reservation quantity.');
            }

            $locationId = $reservation->location_id ?? $reservation->stock?->location_id;

            if ($locationId === null) {
                throw new InvalidArgumentException('Cannot fulfill reservation without a location.');
            }

            // Create consumption movement
            $movement = $this->stockService->removeStock(new RemoveStockData(
                itemId: $reservation->item_id,
                locationId: $locationId,
                quantity: $quantity,
                type: 'consumption',
                referenceType: $reservation->job_type,
                referenceId: $reservation->job_id,
                notes: "Partially fulfilled reservation {$reservation->id}",
            ));

            // Release reserved quantity for the consumed portion
            if ($reservation->stock !== null) {
                $reservation->stock->decrement('reserved_quantity', $quantity);
            }

            // Update reservation
            $remainingQuantity = (float) $reservation->quantity - $quantity;
            $reservation->quantity = $remainingQuantity;

            if ($remainingQuantity <= 0) {
                $reservation->fulfilled_at = Carbon::now();
                $reservation->status = 'fulfilled';
            } else {
                $reservation->status = 'partial';
            }

            $reservation->save();

            return $movement;
        });
    }

    /**
     * Expire old reservations past their expiry date.
     *
     * @return int Number of reservations expired
     */
    public function expireOld(): int
    {
        return DB::transaction(function (): int {
            $expiredReservations = $this->getReservationModel()::query()
                ->whereNull('fulfilled_at')
                ->whereNull('cancelled_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', Carbon::now())
                ->get();

            foreach ($expiredReservations as $reservation) {
                // Release reserved quantity on stock
                if ($reservation->stock !== null) {
                    $reservation->stock->decrement('reserved_quantity', (float) $reservation->quantity);
                }

                $reservation->status = 'expired';
                $reservation->save();
            }

            return $expiredReservations->count();
        });
    }

    /**
     * Get all reservations for a job.
     *
     * @return Collection<int, Reservation>
     */
    public function getForJob(string $jobType, string $jobId): Collection
    {
        return $this->getReservationModel()::query()
            ->where('job_type', $jobType)
            ->where('job_id', $jobId)
            ->with(['item', 'stock', 'location'])
            ->get();
    }

    /**
     * Check if an item has sufficient available stock to reserve.
     */
    public function canReserve(string $itemId, float $quantity, ?string $locationId = null): bool
    {
        $available = $this->stockService->getAvailable($itemId, $locationId);

        return $available >= $quantity;
    }

    /**
     * Find a stock record with sufficient available quantity.
     */
    private function findAvailableStock(string $itemId, float $quantity, ?string $locationId = null): ?Stock
    {
        $query = $this->getStockModel()::query()
            ->where('item_id', $itemId)
            ->whereRaw('quantity - reserved_quantity >= ?', [$quantity]);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        /** @var Stock|null */
        return $query->first();
    }

    /**
     * @return class-string<Reservation>
     */
    private function getReservationModel(): string
    {
        return config('inventory.models.reservation', Reservation::class);
    }

    /**
     * @return class-string<Stock>
     */
    private function getStockModel(): string
    {
        return config('inventory.models.stock', Stock::class);
    }
}
