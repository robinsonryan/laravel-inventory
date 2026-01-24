<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Data;

final readonly class ReservationData
{
    public function __construct(
        public string $itemId,
        public string $jobType,
        public string $jobId,
        public float $quantity,
        public ?string $locationId = null,
        public ?string $lineId = null,
        public ?int $expiryHours = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['item_id'],
            jobType: $data['job_type'],
            jobId: $data['job_id'],
            quantity: (float) $data['quantity'],
            locationId: $data['location_id'] ?? null,
            lineId: $data['line_id'] ?? null,
            expiryHours: isset($data['expiry_hours']) ? (int) $data['expiry_hours'] : null,
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
            'job_type' => $this->jobType,
            'job_id' => $this->jobId,
            'quantity' => $this->quantity,
            'location_id' => $this->locationId,
            'line_id' => $this->lineId,
            'expiry_hours' => $this->expiryHours,
            'notes' => $this->notes,
        ], fn ($value) => $value !== null);
    }
}
