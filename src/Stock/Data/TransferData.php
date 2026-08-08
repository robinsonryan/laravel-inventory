<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Data;

final readonly class TransferData
{
    public function __construct(
        public string $itemId,
        public string $fromLocationId,
        public string $toLocationId,
        public float $quantity,
        public ?string $lotNumber = null,
        public ?string $serialNumber = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['item_id'],
            fromLocationId: $data['from_location_id'],
            toLocationId: $data['to_location_id'],
            quantity: (float) $data['quantity'],
            lotNumber: $data['lot_number'] ?? null,
            serialNumber: $data['serial_number'] ?? null,
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
            'from_location_id' => $this->fromLocationId,
            'to_location_id' => $this->toLocationId,
            'quantity' => $this->quantity,
            'lot_number' => $this->lotNumber,
            'serial_number' => $this->serialNumber,
            'notes' => $this->notes,
        ], fn (float|string|null $value): bool => $value !== null);
    }
}
