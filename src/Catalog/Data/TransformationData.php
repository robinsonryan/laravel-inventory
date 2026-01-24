<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Data;

final readonly class TransformationData
{
    /**
     * @param  array<int, array{item_id: string, quantity: float}>  $inputs
     * @param  array<int, array{item_id: string, quantity: float}>  $outputs
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public array $inputs = [],
        public array $outputs = [],
        public bool $isActive = true,
        public ?array $metadata = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? null,
            inputs: $data['inputs'] ?? [],
            outputs: $data['outputs'] ?? [],
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
            'name' => $this->name,
            'description' => $this->description,
            'inputs' => $this->inputs,
            'outputs' => $this->outputs,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
        ];
    }
}
