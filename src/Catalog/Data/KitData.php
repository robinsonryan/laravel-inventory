<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Data;

final readonly class KitData
{
    /**
     * @param  array<int, array{item_id: string, quantity?: float, is_optional?: bool, sort_order?: int}>  $items
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $itemId = null,
        public array $items = [],
        public bool $isActive = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? null,
            itemId: $data['item_id'] ?? null,
            items: $data['items'] ?? [],
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'item_id' => $this->itemId,
            'items' => $this->items,
            'is_active' => $this->isActive,
        ];
    }
}
