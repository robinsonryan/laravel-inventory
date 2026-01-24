# Stock Module Specification

## Overview

The Stock module manages physical inventory: locations, stock quantities, movements (audit trail), and reservations. All stock changes create immutable movement records.

## Models

### Location

A physical or logical location where stock is stored.

```php
namespace RobinsonRyan\Inventory\Stock\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid (FK to tenants)
- parent_id: uuid|null (FK to self, for nested locations)
- code: string (unique within tenant)
- name: string
- description: text|null
- is_active: boolean (default: true)
- is_pickable: boolean (default: true, can items be picked from here)
- is_receivable: boolean (default: true, can items be received here)
- metadata: json|null
- created_at, updated_at

// Tag Attributes (via HasTags):
- type: LocationTypeDefinition

// Relationships:
- parent(): BelongsTo (Location)
- children(): HasMany (Location)
- stocks(): HasMany (Stock)
- incomingMovements(): HasMany (StockMovement, to_location_id)
- outgoingMovements(): HasMany (StockMovement, from_location_id)

// Traits:
- BelongsToTenant
- HasUuid7
```

### Stock

Current stock level of an item at a location.

```php
namespace RobinsonRyan\Inventory\Stock\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid (FK)
- item_id: uuid (FK to items)
- location_id: uuid (FK to locations)
- quantity: decimal(10,4) (current quantity)
- reserved_quantity: decimal(10,4) (default: 0, quantity reserved)
- available_quantity: computed (quantity - reserved_quantity)
- cost_basis: decimal(10,4)|null (weighted average cost)
- lot_number: string|null
- serial_number: string|null (for serialized items)
- expiry_date: date|null
- metadata: json|null
- created_at, updated_at

// Unique constraint: (tenant_id, item_id, location_id, lot_number, serial_number)

// Relationships:
- item(): BelongsTo (Item from Catalog)
- location(): BelongsTo (Location)
- movements(): HasMany (StockMovement)
- reservations(): HasMany (Reservation)

// Traits:
- BelongsToTenant
- HasUuid7
```

### StockMovement

Immutable audit trail of all stock changes.

```php
namespace RobinsonRyan\Inventory\Stock\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid (FK)
- item_id: uuid (FK)
- from_location_id: uuid|null (FK, null for purchases)
- to_location_id: uuid|null (FK, null for sales/consumption)
- quantity: decimal(10,4) (positive value)
- cost: decimal(10,4)|null (unit cost at time of movement)
- reference_type: string|null (e.g., 'purchase_order', 'work_order')
- reference_id: string|null (FK to reference)
- actor_type: string|null (who performed: admin, system)
- actor_id: string|null
- lot_number: string|null
- serial_number: string|null
- notes: text|null
- metadata: json|null
- created_at (immutable, no updated_at)

// Tag Attributes (via HasTags):
- type: MovementTypeDefinition

// Relationships:
- item(): BelongsTo
- fromLocation(): BelongsTo (Location)
- toLocation(): BelongsTo (Location)

// Traits:
- BelongsToTenant
- HasUuid7
- HasImmutableAuditTrail
```

### Reservation

A hold on inventory for a job/work order.

```php
namespace RobinsonRyan\Inventory\Stock\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid (FK)
- item_id: uuid (FK)
- stock_id: uuid|null (FK, specific stock record)
- location_id: uuid|null (FK, preferred location)
- job_type: string (e.g., 'work_order')
- job_id: string
- line_id: string|null (specific line item reference)
- quantity: decimal(10,4)
- reserved_at: datetime
- expires_at: datetime|null
- fulfilled_at: datetime|null
- cancelled_at: datetime|null
- notes: text|null
- metadata: json|null
- created_at, updated_at

// Tag Attributes (via HasTags):
- status: ReservationStatusDefinition

// Relationships:
- item(): BelongsTo
- stock(): BelongsTo (Stock)
- location(): BelongsTo

// Traits:
- BelongsToTenant
- HasUuid7
```

## Tag Definitions

### LocationTypeDefinition

```php
namespace RobinsonRyan\Inventory\Stock\TagDefinitions;

final class LocationTypeDefinition extends TagDefinition
{
    public static string $slug = 'location-type';
    public static string $name = 'Location Type';
    public static bool $singleSelect = true;
    public static bool $global = true;

    public const array DEFAULT_VALUES = [
        'warehouse',
        'bin',
        'shelf',
        'wip',        // Work in progress
        'truck',      // Mobile location
        'virtual',    // For adjustments
        'quarantine', // Damaged/inspection
    ];
}
```

### MovementTypeDefinition

```php
namespace RobinsonRyan\Inventory\Stock\TagDefinitions;

final class MovementTypeDefinition extends TagDefinition
{
    public static string $slug = 'movement-type';
    public static string $name = 'Movement Type';
    public static bool $singleSelect = true;
    public static bool $global = true;

    public const array DEFAULT_VALUES = [
        'purchase',     // Receiving from vendor
        'sale',         // Sold to customer
        'transfer',     // Between locations
        'adjustment',   // Manual correction
        'consumption',  // Used in job
        'production',   // Output from transformation
        'return',       // Customer return
        'scrap',        // Written off
    ];
}
```

### ReservationStatusDefinition

```php
namespace RobinsonRyan\Inventory\Stock\TagDefinitions;

final class ReservationStatusDefinition extends TagDefinition
{
    public static string $slug = 'reservation-status';
    public static string $name = 'Reservation Status';
    public static bool $singleSelect = true;
    public static bool $global = true;

    public const array DEFAULT_VALUES = [
        'pending',    // Waiting for stock
        'reserved',   // Stock allocated
        'partial',    // Partially fulfilled
        'fulfilled',  // Consumed/used
        'cancelled',  // Released
        'expired',    // Timed out
    ];
}
```

## Services

### StockService

```php
namespace RobinsonRyan\Inventory\Stock\Services;

final class StockService
{
    // Get current stock level
    public function getQuantity(string $itemId, ?string $locationId = null): float;

    // Get available (unreserved) stock
    public function getAvailable(string $itemId, ?string $locationId = null): float;

    // Add stock (creates movement)
    public function addStock(AddStockData $data): StockMovement;

    // Remove stock (creates movement)
    public function removeStock(RemoveStockData $data): StockMovement;

    // Transfer between locations
    public function transfer(TransferData $data): StockMovement;

    // Adjust stock (for corrections)
    public function adjust(AdjustStockData $data): StockMovement;

    // Get stock across all locations for an item
    public function getStockByItem(string $itemId): Collection;

    // Get stock at a location
    public function getStockByLocation(string $locationId): Collection;
}
```

### ReservationService

```php
namespace RobinsonRyan\Inventory\Stock\Services;

final class ReservationService
{
    // Create a reservation
    public function reserve(ReservationData $data): Reservation;

    // Release a reservation
    public function release(Reservation $reservation): void;

    // Fulfill a reservation (consume stock)
    public function fulfill(Reservation $reservation): StockMovement;

    // Partially fulfill
    public function partialFulfill(Reservation $reservation, float $quantity): StockMovement;

    // Expire old reservations
    public function expireOld(): int;

    // Get reservations for a job
    public function getForJob(string $jobType, string $jobId): Collection;

    // Check if item has sufficient available stock
    public function canReserve(string $itemId, float $quantity, ?string $locationId = null): bool;
}
```

## Actions

### AddStock

```php
namespace RobinsonRyan\Inventory\Stock\Actions;

final class AddStock
{
    public function execute(AddStockData $data): StockMovement;
}
```

### RemoveStock

```php
namespace RobinsonRyan\Inventory\Stock\Actions;

final class RemoveStock
{
    public function execute(RemoveStockData $data): StockMovement;
}
```

### TransferStock

```php
namespace RobinsonRyan\Inventory\Stock\Actions;

final class TransferStock
{
    public function execute(TransferData $data): StockMovement;
}
```

### AdjustStock

```php
namespace RobinsonRyan\Inventory\Stock\Actions;

final class AdjustStock
{
    public function execute(AdjustStockData $data): StockMovement;
}
```

### ExecuteTransformation

Consumes inputs, creates outputs (production).

```php
namespace RobinsonRyan\Inventory\Stock\Actions;

final class ExecuteTransformation
{
    public function execute(
        Transformation $transformation,
        string $locationId,
        float $quantity = 1.0
    ): Assembly;
}
```

## Data Objects

### AddStockData

```php
namespace RobinsonRyan\Inventory\Stock\Data;

final readonly class AddStockData
{
    public function __construct(
        public string $itemId,
        public string $locationId,
        public float $quantity,
        public string $type = 'purchase',
        public ?float $cost = null,
        public ?string $referenceType = null,
        public ?string $referenceId = null,
        public ?string $lotNumber = null,
        public ?string $serialNumber = null,
        public ?string $notes = null,
        public ?array $metadata = null,
    ) {}
}
```

### RemoveStockData

```php
namespace RobinsonRyan\Inventory\Stock\Data;

final readonly class RemoveStockData
{
    public function __construct(
        public string $itemId,
        public string $locationId,
        public float $quantity,
        public string $type = 'sale',
        public ?string $referenceType = null,
        public ?string $referenceId = null,
        public ?string $lotNumber = null,
        public ?string $serialNumber = null,
        public ?string $notes = null,
        public ?array $metadata = null,
    ) {}
}
```

### TransferData

```php
namespace RobinsonRyan\Inventory\Stock\Data;

final readonly class TransferData
{
    public function __construct(
        public string $itemId,
        public string $fromLocationId,
        public string $toLocationId,
        public float $quantity,
        public ?string $lotNumber = null,
        public ?string $serialNumber = null,
        public ?string $notes = null,
    ) {}
}
```

### AdjustStockData

```php
namespace RobinsonRyan\Inventory\Stock\Data;

final readonly class AdjustStockData
{
    public function __construct(
        public string $itemId,
        public string $locationId,
        public float $newQuantity, // Target quantity
        public string $reason,
        public ?string $lotNumber = null,
        public ?string $serialNumber = null,
        public ?string $notes = null,
    ) {}
}
```

### ReservationData

```php
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
}
```

## Migrations

1. `create_locations_table.php`
2. `create_stock_table.php`
3. `create_stock_movements_table.php`
4. `create_reservations_table.php`

## Tests

### Feature Tests

- `tests/Feature/Stock/LocationTest.php`
  - it creates a location
  - it creates nested locations
  - it sets location type via tag
  - it scopes locations by tenant

- `tests/Feature/Stock/StockTest.php`
  - it adds stock to a location
  - it removes stock from a location
  - it prevents negative stock (unless configured)
  - it transfers stock between locations
  - it adjusts stock quantity
  - it tracks cost basis

- `tests/Feature/Stock/MovementTest.php`
  - it creates immutable movement records
  - it prevents movement updates
  - it prevents movement deletes
  - it tracks movement types

- `tests/Feature/Stock/ReservationTest.php`
  - it creates a reservation
  - it reserves available stock
  - it prevents over-reservation
  - it fulfills a reservation
  - it releases a reservation
  - it expires old reservations
  - it partially fulfills reservations

### Unit Tests

- `tests/Unit/Stock/StockServiceTest.php`
- `tests/Unit/Stock/ReservationServiceTest.php`
- `tests/Unit/Stock/TagDefinitionsTest.php`

## Directory Structure

```
src/Stock/
├── Models/
│   ├── Location.php
│   ├── Stock.php
│   ├── StockMovement.php
│   └── Reservation.php
├── TagDefinitions/
│   ├── LocationTypeDefinition.php
│   ├── MovementTypeDefinition.php
│   └── ReservationStatusDefinition.php
├── Services/
│   ├── StockService.php
│   └── ReservationService.php
├── Actions/
│   ├── AddStock.php
│   ├── RemoveStock.php
│   ├── TransferStock.php
│   ├── AdjustStock.php
│   └── ExecuteTransformation.php
└── Data/
    ├── AddStockData.php
    ├── RemoveStockData.php
    ├── TransferData.php
    ├── AdjustStockData.php
    └── ReservationData.php

database/migrations/
├── 2026_01_01_000020_create_locations_table.php
├── 2026_01_01_000021_create_stock_table.php
├── 2026_01_01_000022_create_stock_movements_table.php
└── 2026_01_01_000023_create_reservations_table.php

tests/Feature/Stock/
├── LocationTest.php
├── StockTest.php
├── MovementTest.php
└── ReservationTest.php

tests/Unit/Stock/
├── StockServiceTest.php
├── ReservationServiceTest.php
└── TagDefinitionsTest.php
```

## Key Constraints

1. **Immutability**: StockMovement records cannot be updated or deleted
2. **No Negative Stock**: By default, cannot remove more than available (configurable)
3. **Reserved vs Available**: `available = quantity - reserved`
4. **Tenant Isolation**: All queries are automatically scoped to tenant
5. **Audit Trail**: Every stock change creates a movement record
