<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Data;

final readonly class AddStockData
{
    public function __construct(
        public string $itemId,
        public string $locationId,
        public float $quantity,
        public string $type = 'purchase',
        public ?float $cost = null,
        public ?string $referenceType = null,
        public ?string $referenceId = null,
        public ?string $lotNumber = null,
        public ?string $serialNumber = null,
        public ?string $notes = null,
        public ?array $metadata = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['item_id'],
            locationId: $data['location_id'],
            quantity: (float) $data['quantity'],
            type: $data['type'] ?? 'purchase',
            cost: isset($data['cost']) ? (float) $data['cost'] : null,
            referenceType: $data['reference_type'] ?? null,
            referenceId: $data['reference_id'] ?? null,
            lotNumber: $data['lot_number'] ?? null,
            serialNumber: $data['serial_number'] ?? null,
            notes: $data['notes'] ?? null,
            metadata: $data['metadata'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'quantity' => $this->quantity,
            'type' => $this->type,
            'cost' => $this->cost,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'lot_number' => $this->lotNumber,
            'serial_number' => $this->serialNumber,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
        ], fn ($value) => $value !== null);
    }
}
