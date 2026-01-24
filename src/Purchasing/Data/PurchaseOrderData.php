<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Data;

final readonly class PurchaseOrderData
{
    /**
     * @param  array<int, PurchaseOrderLineData|array<string, mixed>>  $lines
     * @param  array{line1?: string, line2?: string, city?: string, state?: string, postal?: string, country?: string}|null  $shippingAddress
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $vendorId,
        public ?string $poNumber = null,
        public ?string $orderDate = null,
        public ?string $expectedDate = null,
        public array $lines = [],
        public ?float $tax = null,
        public ?float $shipping = null,
        public ?array $shippingAddress = null,
        public ?string $notes = null,
        public ?array $metadata = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $lines = [];
        if (isset($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as $line) {
                $lines[] = $line instanceof PurchaseOrderLineData
                    ? $line
                    : PurchaseOrderLineData::fromArray($line);
            }
        }

        return new self(
            vendorId: $data['vendor_id'],
            poNumber: $data['po_number'] ?? null,
            orderDate: $data['order_date'] ?? null,
            expectedDate: $data['expected_date'] ?? null,
            lines: $lines,
            tax: isset($data['tax']) ? (float) $data['tax'] : null,
            shipping: isset($data['shipping']) ? (float) $data['shipping'] : null,
            shippingAddress: $data['shipping_address'] ?? null,
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
            'vendor_id' => $this->vendorId,
            'po_number' => $this->poNumber,
            'order_date' => $this->orderDate,
            'expected_date' => $this->expectedDate,
            'lines' => array_map(
                fn (PurchaseOrderLineData|array $line) => $line instanceof PurchaseOrderLineData
                    ? $line->toArray()
                    : $line,
                $this->lines
            ),
            'tax' => $this->tax,
            'shipping' => $this->shipping,
            'shipping_address' => $this->shippingAddress,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Convert to array for model attributes (excludes lines).
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return array_filter([
            'vendor_id' => $this->vendorId,
            'po_number' => $this->poNumber,
            'order_date' => $this->orderDate,
            'expected_date' => $this->expectedDate,
            'tax' => $this->tax,
            'shipping' => $this->shipping,
            'shipping_address' => $this->shippingAddress,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
        ], fn ($value) => $value !== null);
    }
}
