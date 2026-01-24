# Reconciliation Module Specification

## Overview

The Reconciliation module handles inventory cycle counts and variance adjustments. It provides a workflow for counting physical inventory and reconciling differences with system records.

## Models

### CycleCount

A cycle count session/document.

```php
namespace RobinsonRyan\Inventory\Reconciliation\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid (FK)
- count_number: string (unique within tenant)
- location_id: uuid|null (FK, scope to specific location)
- scheduled_date: date|null
- started_at: datetime|null
- completed_at: datetime|null
- approved_at: datetime|null
- approved_by: uuid|null (FK to actor)
- created_by: uuid|null (FK to actor)
- notes: text|null
- metadata: json|null
- created_at, updated_at

// Tag Attributes (via HasTags):
- status: CycleCountStatusDefinition

// Relationships:
- location(): BelongsTo (Location from Stock)
- lines(): HasMany (CycleCountLine)
- adjustments(): HasMany (VarianceAdjustment)

// Traits:
- BelongsToTenant
- HasUuid7
```

### CycleCountLine

Individual item counts within a cycle count.

```php
namespace RobinsonRyan\Inventory\Reconciliation\Models;

// Fields:
- id: uuid (primary key)
- cycle_count_id: uuid (FK)
- item_id: uuid (FK)
- location_id: uuid (FK)
- stock_id: uuid|null (FK, specific stock record if applicable)
- expected_quantity: decimal(10,4) (system quantity at start)
- counted_quantity: decimal(10,4)|null
- variance: decimal(10,4)|null (computed: counted - expected)
- lot_number: string|null
- serial_number: string|null
- counted_by: uuid|null (FK to actor)
- counted_at: datetime|null
- notes: text|null
- created_at, updated_at

// Relationships:
- cycleCount(): BelongsTo
- item(): BelongsTo
- location(): BelongsTo
- stock(): BelongsTo

// Traits:
- HasUuid7
```

### VarianceAdjustment

Record of variance adjustments after count approval.

```php
namespace RobinsonRyan\Inventory\Reconciliation\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid (FK)
- cycle_count_id: uuid (FK)
- cycle_count_line_id: uuid (FK)
- item_id: uuid (FK)
- location_id: uuid (FK)
- quantity_before: decimal(10,4)
- quantity_after: decimal(10,4)
- variance: decimal(10,4)
- cost_impact: decimal(10,2)|null
- stock_movement_id: uuid (FK, the adjustment movement)
- reason: text|null
- approved_by: uuid|null
- approved_at: datetime
- created_at, updated_at

// Relationships:
- cycleCount(): BelongsTo
- cycleCountLine(): BelongsTo
- item(): BelongsTo
- location(): BelongsTo
- stockMovement(): BelongsTo

// Traits:
- BelongsToTenant
- HasUuid7
```

## Tag Definitions

### CycleCountStatusDefinition

```php
namespace RobinsonRyan\Inventory\Reconciliation\TagDefinitions;

final class CycleCountStatusDefinition extends TagDefinition
{
    public static string $slug = 'cycle-count-status';
    public static string $name = 'Cycle Count Status';
    public static bool $singleSelect = true;
    public static bool $global = true;

    public const array DEFAULT_VALUES = [
        'draft',       // Not started
        'in_progress', // Counting underway
        'pending',     // Counting done, awaiting approval
        'approved',    // Variances approved
        'cancelled',   // Cancelled
    ];
}
```

## Services

### ReconciliationService

```php
namespace RobinsonRyan\Inventory\Reconciliation\Services;

final class ReconciliationService
{
    // Generate next count number
    public function generateCountNumber(): string;

    // Get items to count for a location
    public function getItemsToCount(string $locationId): Collection;

    // Calculate total variance value
    public function calculateVarianceValue(CycleCount $count): float;

    // Get counts with unapproved variances
    public function getCountsRequiringApproval(): Collection;

    // Check if count can be approved
    public function canApprove(CycleCount $count): bool;
}
```

## Actions

### InitiateCycleCount

Create a new cycle count session.

```php
namespace RobinsonRyan\Inventory\Reconciliation\Actions;

final class InitiateCycleCount
{
    public function execute(CycleCountData $data): CycleCount;
}
```

### RecordCount

Record a counted quantity for an item.

```php
namespace RobinsonRyan\Inventory\Reconciliation\Actions;

final class RecordCount
{
    public function execute(
        CycleCountLine $line,
        float $countedQuantity,
        ?string $notes = null
    ): CycleCountLine;
}
```

### CompleteCounting

Mark counting as complete, ready for approval.

```php
namespace RobinsonRyan\Inventory\Reconciliation\Actions;

final class CompleteCounting
{
    public function execute(CycleCount $count): CycleCount;
}
```

### ApproveVariance

Approve variances and create adjustment movements.

```php
namespace RobinsonRyan\Inventory\Reconciliation\Actions;

final class ApproveVariance
{
    public function execute(
        CycleCount $count,
        ?string $reason = null
    ): CycleCount;
}
```

### CancelCycleCount

Cancel a cycle count.

```php
namespace RobinsonRyan\Inventory\Reconciliation\Actions;

final class CancelCycleCount
{
    public function execute(CycleCount $count): CycleCount;
}
```

## Data Objects

### CycleCountData

```php
namespace RobinsonRyan\Inventory\Reconciliation\Data;

final readonly class CycleCountData
{
    public function __construct(
        public ?string $locationId = null,
        public ?array $itemIds = null, // Specific items to count
        public ?string $scheduledDate = null,
        public ?string $notes = null,
    ) {}
}
```

## Migrations

1. `create_cycle_counts_table.php`
2. `create_cycle_count_lines_table.php`
3. `create_variance_adjustments_table.php`

## Tests

### Feature Tests

- `tests/Feature/Reconciliation/CycleCountTest.php`
  - it creates a cycle count
  - it creates lines for all items at location
  - it creates lines for specific items
  - it calculates expected quantities from stock

- `tests/Feature/Reconciliation/CountingTest.php`
  - it records a count
  - it calculates variance
  - it marks count as complete
  - it validates all lines counted before completion

- `tests/Feature/Reconciliation/ApprovalTest.php`
  - it approves variances
  - it creates adjustment movements for variances
  - it tracks cost impact
  - it cancels cycle count

### Unit Tests

- `tests/Unit/Reconciliation/ReconciliationServiceTest.php`
- `tests/Unit/Reconciliation/TagDefinitionsTest.php`

## Directory Structure

```
src/Reconciliation/
├── Models/
│   ├── CycleCount.php
│   ├── CycleCountLine.php
│   └── VarianceAdjustment.php
├── TagDefinitions/
│   └── CycleCountStatusDefinition.php
├── Services/
│   └── ReconciliationService.php
├── Actions/
│   ├── InitiateCycleCount.php
│   ├── RecordCount.php
│   ├── CompleteCounting.php
│   ├── ApproveVariance.php
│   └── CancelCycleCount.php
└── Data/
    └── CycleCountData.php

database/migrations/
├── 2026_01_01_000050_create_cycle_counts_table.php
├── 2026_01_01_000051_create_cycle_count_lines_table.php
└── 2026_01_01_000052_create_variance_adjustments_table.php

tests/Feature/Reconciliation/
├── CycleCountTest.php
├── CountingTest.php
└── ApprovalTest.php

tests/Unit/Reconciliation/
├── ReconciliationServiceTest.php
└── TagDefinitionsTest.php
```

## Workflow

1. **Initiate**: Create cycle count, generating lines with expected quantities
2. **Count**: Record physical counts for each line
3. **Complete**: Mark counting complete, calculate variances
4. **Review**: Review variances, add notes/reasons
5. **Approve**: Approve to create adjustment movements
   - Or **Cancel** if count is invalid

```
draft -> in_progress -> pending -> approved
                    \-> cancelled
```
