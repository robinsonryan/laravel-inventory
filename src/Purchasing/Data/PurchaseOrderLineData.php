<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Data;

final readonly class PurchaseOrderLineData
{
    public function __construct(
        public string $itemId,
        public float $quantity,
        public float $unitCost,
        public ?string $description = null,
        public ?string $destinationLocationId = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['item_id'],
            quantity: (float) $data['quantity'],
            unitCost: (float) $data['unit_cost'],
            description: $data['description'] ?? null,
            destinationLocationId: $data['destination_location_id'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'item_id' => $this->itemId,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unitCost,
            'description' => $this->description,
            'destination_location_id' => $this->destinationLocationId,
            'notes' => $this->notes,
        ], fn ($value) => $value !== null);
    }

    /**
     * Calculate total for this line.
     */
    public function getTotal(): float
    {
        return $this->quantity * $this->unitCost;
    }
}
