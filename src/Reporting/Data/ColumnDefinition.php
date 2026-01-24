<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Data;

final readonly class ColumnDefinition
{
    public const TYPE_STRING = 'string';

    public const TYPE_NUMBER = 'number';

    public const TYPE_CURRENCY = 'currency';

    public const TYPE_DATE = 'date';

    public const TYPE_DATETIME = 'datetime';

    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TYPE_STRING,
        public bool $sortable = true,
        public ?string $format = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            label: $data['label'],
            type: $data['type'] ?? self::TYPE_STRING,
            sortable: $data['sortable'] ?? true,
            format: $data['format'] ?? null,
        );
    }

    /**
     * @return array{key: string, label: string, type: string, sortable: bool, format: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'sortable' => $this->sortable,
            'format' => $this->format,
        ];
    }
}
