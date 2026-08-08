<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Data;

final readonly class AdjustStockData
{
    public function __construct(
        public string $itemId,
        public string $locationId,
        public float $newQuantity,
        public string $reason,
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
            locationId: $data['location_id'],
            newQuantity: (float) $data['new_quantity'],
            reason: $data['reason'],
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
            'location_id' => $this->locationId,
            'new_quantity' => $this->newQuantity,
            'reason' => $this->reason,
            'lot_number' => $this->lotNumber,
            'serial_number' => $this->serialNumber,
            'notes' => $this->notes,
        ], fn (float|string|null $value): bool => $value !== null);
    }
}
