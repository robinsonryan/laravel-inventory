<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Data;

final readonly class CycleCountData
{
    /**
     * @param  list<string>|null  $itemIds  Specific items to count
     */
    public function __construct(
        public ?string $locationId = null,
        public ?array $itemIds = null,
        public ?string $scheduledDate = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            locationId: $data['location_id'] ?? null,
            itemIds: $data['item_ids'] ?? null,
            scheduledDate: $data['scheduled_date'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'location_id' => $this->locationId,
            'item_ids' => $this->itemIds,
            'scheduled_date' => $this->scheduledDate,
            'notes' => $this->notes,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
