<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Data;

final readonly class ItemData
{
    /**
     * @param  array{length?: float, width?: float, height?: float, unit?: string}|null  $dimensions
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $sku,
        public string $name,
        public ?string $tenantId = null,
        public ?string $description = null,
        public string $unitOfMeasure = 'each',
        public string $category = 'part',
        public ?float $cost = null,
        public ?float $price = null,
        public ?int $minStockLevel = null,
        public ?int $maxStockLevel = null,
        public ?int $leadTimeDays = null,
        public ?float $weight = null,
        public ?array $dimensions = null,
        public bool $isSerialized = false,
        public bool $isActive = true,
        public ?array $metadata = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sku: $data['sku'],
            name: $data['name'],
            tenantId: $data['tenant_id'] ?? null,
            description: $data['description'] ?? null,
            unitOfMeasure: $data['unit_of_measure'] ?? 'each',
            category: $data['category'] ?? 'part',
            cost: isset($data['cost']) ? (float) $data['cost'] : null,
            price: isset($data['price']) ? (float) $data['price'] : null,
            minStockLevel: isset($data['min_stock_level']) ? (int) $data['min_stock_level'] : null,
            maxStockLevel: isset($data['max_stock_level']) ? (int) $data['max_stock_level'] : null,
            leadTimeDays: isset($data['lead_time_days']) ? (int) $data['lead_time_days'] : null,
            weight: isset($data['weight']) ? (float) $data['weight'] : null,
            dimensions: $data['dimensions'] ?? null,
            isSerialized: (bool) ($data['is_serialized'] ?? false),
            isActive: (bool) ($data['is_active'] ?? true),
            metadata: $data['metadata'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'tenant_id' => $this->tenantId,
            'description' => $this->description,
            'unit_of_measure' => $this->unitOfMeasure,
            'category' => $this->category,
            'cost' => $this->cost,
            'price' => $this->price,
            'min_stock_level' => $this->minStockLevel,
            'max_stock_level' => $this->maxStockLevel,
            'lead_time_days' => $this->leadTimeDays,
            'weight' => $this->weight,
            'dimensions' => $this->dimensions,
            'is_serialized' => $this->isSerialized,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Convert to array for model attributes (excludes tag attributes).
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'tenant_id' => $this->tenantId,
            'description' => $this->description,
            'unit_of_measure' => $this->unitOfMeasure,
            'cost' => $this->cost,
            'price' => $this->price,
            'min_stock_level' => $this->minStockLevel,
            'max_stock_level' => $this->maxStockLevel,
            'lead_time_days' => $this->leadTimeDays,
            'weight' => $this->weight,
            'dimensions' => $this->dimensions,
            'is_serialized' => $this->isSerialized,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
        ];
    }
}
