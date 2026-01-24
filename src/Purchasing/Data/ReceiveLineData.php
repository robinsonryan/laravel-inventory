<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Data;

final readonly class ReceiveLineData
{
    public function __construct(
        public string $purchaseOrderLineId,
        public float $quantity,
        public string $locationId,
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
            purchaseOrderLineId: $data['purchase_order_line_id'],
            quantity: (float) $data['quantity'],
            locationId: $data['location_id'],
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
            'purchase_order_line_id' => $this->purchaseOrderLineId,
            'quantity' => $this->quantity,
            'location_id' => $this->locationId,
            'lot_number' => $this->lotNumber,
            'serial_number' => $this->serialNumber,
            'notes' => $this->notes,
        ], fn ($value) => $value !== null);
    }
}
