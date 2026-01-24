# Purchasing Module Specification

## Overview

The Purchasing module manages purchase orders and receiving. It integrates with the Vendor package for supplier management and the Stock module for receiving inventory.

## Models

### PurchaseOrder

A purchase order to a vendor.

```php
namespace RobinsonRyan\Inventory\Purchasing\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid (FK)
- vendor_id: uuid (FK to vendors table)
- po_number: string (unique within tenant)
- order_date: date
- expected_date: date|null
- subtotal: decimal(10,2) (default: 0)
- tax: decimal(10,2) (default: 0)
- shipping: decimal(10,2) (default: 0)
- total: decimal(10,2) (default: 0)
- notes: text|null
- shipping_address: json|null {line1, line2, city, state, postal, country}
- metadata: json|null
- submitted_at: datetime|null
- cancelled_at: datetime|null
- created_by: uuid|null (FK to actor)
- created_at, updated_at

// Tag Attributes (via HasTags):
- status: PurchaseOrderStatusDefinition

// Relationships:
- vendor(): BelongsTo (Vendor from vendor package)
- lines(): HasMany (PurchaseOrderLine)
- receipts(): HasMany (Receipt)

// Traits:
- BelongsToTenant
- HasUuid7
- BelongsToVendor (from vendor package)
```

### PurchaseOrderLine

Line items on a purchase order.

```php
namespace RobinsonRyan\Inventory\Purchasing\Models;

// Fields:
- id: uuid (primary key)
- purchase_order_id: uuid (FK)
- item_id: uuid (FK to items)
- description: string|null (override item description)
- quantity: decimal(10,4)
- unit_cost: decimal(10,4)
- total: decimal(10,2) (computed: quantity * unit_cost)
- quantity_received: decimal(10,4) (default: 0)
- quantity_cancelled: decimal(10,4) (default: 0)
- destination_location_id: uuid|null (FK, where to receive)
- notes: text|null
- sort_order: integer (default: 0)
- created_at, updated_at

// Relationships:
- purchaseOrder(): BelongsTo
- item(): BelongsTo (Item from Catalog)
- destinationLocation(): BelongsTo (Location from Stock)
- receiptLines(): HasMany (ReceiptLine)

// Traits:
- HasUuid7
```

### Receipt

A receiving document (can receive multiple lines from a PO).

```php
namespace RobinsonRyan\Inventory\Purchasing\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid (FK)
- purchase_order_id: uuid (FK)
- receipt_number: string (unique within tenant)
- received_at: datetime
- received_by: uuid|null (FK to actor)
- notes: text|null
- metadata: json|null
- created_at, updated_at

// Relationships:
- purchaseOrder(): BelongsTo
- lines(): HasMany (ReceiptLine)

// Traits:
- BelongsToTenant
- HasUuid7
```

### ReceiptLine

Items received on a receipt.

```php
namespace RobinsonRyan\Inventory\Purchasing\Models;

// Fields:
- id: uuid (primary key)
- receipt_id: uuid (FK)
- purchase_order_line_id: uuid (FK)
- item_id: uuid (FK)
- location_id: uuid (FK, where received)
- quantity: decimal(10,4)
- lot_number: string|null
- serial_number: string|null
- stock_movement_id: uuid|null (FK, created movement)
- notes: text|null
- created_at, updated_at

// Relationships:
- receipt(): BelongsTo
- purchaseOrderLine(): BelongsTo
- item(): BelongsTo
- location(): BelongsTo
- stockMovement(): BelongsTo

// Traits:
- HasUuid7
```

## Tag Definitions

### PurchaseOrderStatusDefinition

```php
namespace RobinsonRyan\Inventory\Purchasing\TagDefinitions;

final class PurchaseOrderStatusDefinition extends TagDefinition
{
    public static string $slug = 'purchase-order-status';
    public static string $name = 'Purchase Order Status';
    public static bool $singleSelect = true;
    public static bool $global = true;

    public const array DEFAULT_VALUES = [
        'draft',
        'submitted',
        'partial',
        'received',
        'cancelled',
    ];

    public const array OPEN = ['draft', 'submitted', 'partial'];
    public const array TERMINAL = ['received', 'cancelled'];
}
```

## Services

### PurchaseOrderService

```php
namespace RobinsonRyan\Inventory\Purchasing\Services;

final class PurchaseOrderService
{
    // Generate next PO number
    public function generatePoNumber(): string;

    // Calculate totals
    public function calculateTotals(PurchaseOrder $po): void;

    // Check if PO is fully received
    public function isFullyReceived(PurchaseOrder $po): bool;

    // Get open POs for a vendor
    public function getOpenForVendor(string $vendorId): Collection;

    // Get lines pending receipt
    public function getPendingLines(PurchaseOrder $po): Collection;
}
```

## Actions

### CreatePurchaseOrder

```php
namespace RobinsonRyan\Inventory\Purchasing\Actions;

final class CreatePurchaseOrder
{
    public function execute(PurchaseOrderData $data): PurchaseOrder;
}
```

### UpdatePurchaseOrder

```php
namespace RobinsonRyan\Inventory\Purchasing\Actions;

final class UpdatePurchaseOrder
{
    public function execute(PurchaseOrder $po, PurchaseOrderData $data): PurchaseOrder;
}
```

### SubmitPurchaseOrder

```php
namespace RobinsonRyan\Inventory\Purchasing\Actions;

final class SubmitPurchaseOrder
{
    public function execute(PurchaseOrder $po): PurchaseOrder;
}
```

### ReceiveLine

```php
namespace RobinsonRyan\Inventory\Purchasing\Actions;

final class ReceiveLine
{
    public function execute(ReceiveLineData $data): ReceiptLine;
}
```

### ReceiveMultipleLines

```php
namespace RobinsonRyan\Inventory\Purchasing\Actions;

final class ReceiveMultipleLines
{
    public function execute(PurchaseOrder $po, array $lines): Receipt;
}
```

### CancelPurchaseOrder

```php
namespace RobinsonRyan\Inventory\Purchasing\Actions;

final class CancelPurchaseOrder
{
    public function execute(PurchaseOrder $po, ?string $reason = null): PurchaseOrder;
}
```

## Data Objects

### PurchaseOrderData

```php
namespace RobinsonRyan\Inventory\Purchasing\Data;

final readonly class PurchaseOrderData
{
    public function __construct(
        public string $vendorId,
        public ?string $poNumber = null, // Auto-generated if null
        public ?string $orderDate = null,
        public ?string $expectedDate = null,
        public array $lines = [], // [{item_id, quantity, unit_cost, ...}]
        public ?float $tax = null,
        public ?float $shipping = null,
        public ?array $shippingAddress = null,
        public ?string $notes = null,
        public ?array $metadata = null,
    ) {}
}
```

### PurchaseOrderLineData

```php
namespace RobinsonRyan\Inventory\Purchasing\Data;

final readonly class PurchaseOrderLineData
{
    public function __construct(
        public string $itemId,
        public float $quantity,
        public float $unitCost,
        public ?string $description = null,
        public ?string $destinationLocationId = null,
        public ?string $notes = null,
    ) {}
}
```

### ReceiveLineData

```php
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
}
```

## Migrations

1. `create_purchase_orders_table.php`
2. `create_purchase_order_lines_table.php`
3. `create_receipts_table.php`
4. `create_receipt_lines_table.php`

## Tests

### Feature Tests

- `tests/Feature/Purchasing/PurchaseOrderTest.php`
  - it creates a purchase order
  - it adds lines to purchase order
  - it calculates totals
  - it submits purchase order
  - it cancels purchase order
  - it validates vendor exists

- `tests/Feature/Purchasing/ReceivingTest.php`
  - it receives a line item
  - it creates stock movement on receive
  - it updates PO status to partial
  - it updates PO status to received when complete
  - it receives multiple lines at once
  - it handles over-receiving (configurable)

### Unit Tests

- `tests/Unit/Purchasing/PurchaseOrderServiceTest.php`
- `tests/Unit/Purchasing/TagDefinitionsTest.php`

## Directory Structure

```
src/Purchasing/
├── Models/
│   ├── PurchaseOrder.php
│   ├── PurchaseOrderLine.php
│   ├── Receipt.php
│   └── ReceiptLine.php
├── TagDefinitions/
│   └── PurchaseOrderStatusDefinition.php
├── Services/
│   └── PurchaseOrderService.php
├── Actions/
│   ├── CreatePurchaseOrder.php
│   ├── UpdatePurchaseOrder.php
│   ├── SubmitPurchaseOrder.php
│   ├── ReceiveLine.php
│   ├── ReceiveMultipleLines.php
│   └── CancelPurchaseOrder.php
└── Data/
    ├── PurchaseOrderData.php
    ├── PurchaseOrderLineData.php
    └── ReceiveLineData.php

database/migrations/
├── 2026_01_01_000030_create_purchase_orders_table.php
├── 2026_01_01_000031_create_purchase_order_lines_table.php
├── 2026_01_01_000032_create_receipts_table.php
└── 2026_01_01_000033_create_receipt_lines_table.php

tests/Feature/Purchasing/
├── PurchaseOrderTest.php
└── ReceivingTest.php

tests/Unit/Purchasing/
├── PurchaseOrderServiceTest.php
└── TagDefinitionsTest.php
```
