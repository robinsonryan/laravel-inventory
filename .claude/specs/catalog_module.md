# Catalog Module Specification

## Overview

The Catalog module manages the inventory item catalog - items that can be stocked, sold, or used in work orders. It includes support for kits (bundles), assemblies, transformations (manufacturing), and substitutions.

## Models

### Item

The core catalog entry representing a stockable/sellable item.

```php
namespace RobinsonRyan\Inventory\Catalog\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid|null (for tenant-scoped catalogs, default null for global)
- sku: string (unique identifier)
- name: string
- description: text|null
- unit_of_measure: string (default: 'each')
- cost: decimal(10,4)|null (standard cost)
- price: decimal(10,4)|null (standard sell price)
- min_stock_level: integer|null (reorder point)
- max_stock_level: integer|null
- lead_time_days: integer|null (for reorder calculations)
- weight: decimal(10,4)|null
- dimensions: json|null {length, width, height, unit}
- is_serialized: boolean (default: false)
- is_active: boolean (default: true)
- metadata: json|null
- created_at, updated_at

// Tag Attributes (via HasTags):
- category: ItemCategoryDefinition

// Relationships:
- kits(): BelongsToMany (Kit) - kits this item belongs to
- asKit(): HasOne (Kit) - if this item IS a kit
- substitutes(): HasMany (ItemSubstitution) - items that can substitute
- substitutedBy(): HasMany (ItemSubstitution) - items this substitutes for
- transformationInputs(): HasMany (TransformationInput)
- transformationOutputs(): HasMany (TransformationOutput)
- stocks(): HasMany (Stock) - from Stock module
```

### Kit

A kit is a bundle of items sold/used together. A kit itself can be an Item.

```php
namespace RobinsonRyan\Inventory\Catalog\Models;

// Fields:
- id: uuid (primary key)
- item_id: uuid (the Item this kit represents)
- name: string
- description: text|null
- is_active: boolean (default: true)
- created_at, updated_at

// Relationships:
- item(): BelongsTo (Item)
- kitItems(): HasMany (KitItem)
- items(): BelongsToMany (Item) through kit_items
```

### KitItem

Pivot table for kit composition.

```php
namespace RobinsonRyan\Inventory\Catalog\Models;

// Fields:
- id: uuid (primary key)
- kit_id: uuid (FK to kits)
- item_id: uuid (FK to items)
- quantity: decimal(10,4) (default: 1)
- is_optional: boolean (default: false)
- sort_order: integer (default: 0)
- created_at, updated_at

// Relationships:
- kit(): BelongsTo (Kit)
- item(): BelongsTo (Item)
```

### Assembly

An assembly is an item created through transformation (manufacturing).

```php
namespace RobinsonRyan\Inventory\Catalog\Models;

// Fields:
- id: uuid (primary key)
- item_id: uuid (the Item this assembly produces)
- transformation_id: uuid (FK to transformations)
- lot_number: string|null
- serial_number: string|null
- quantity_produced: decimal(10,4)
- production_date: datetime
- notes: text|null
- metadata: json|null
- created_at, updated_at

// Relationships:
- item(): BelongsTo (Item)
- transformation(): BelongsTo (Transformation)
```

### Transformation

A transformation defines how input items become output items (manufacturing recipe).

```php
namespace RobinsonRyan\Inventory\Catalog\Models;

// Fields:
- id: uuid (primary key)
- name: string
- description: text|null
- is_active: boolean (default: true)
- metadata: json|null
- created_at, updated_at

// Relationships:
- inputs(): HasMany (TransformationInput)
- outputs(): HasMany (TransformationOutput)
```

### TransformationInput

Input items consumed in a transformation.

```php
namespace RobinsonRyan\Inventory\Catalog\Models;

// Fields:
- id: uuid (primary key)
- transformation_id: uuid (FK)
- item_id: uuid (FK)
- quantity: decimal(10,4) (amount consumed per transformation)
- created_at, updated_at

// Relationships:
- transformation(): BelongsTo
- item(): BelongsTo
```

### TransformationOutput

Output items produced by a transformation.

```php
namespace RobinsonRyan\Inventory\Catalog\Models;

// Fields:
- id: uuid (primary key)
- transformation_id: uuid (FK)
- item_id: uuid (FK)
- quantity: decimal(10,4) (amount produced per transformation)
- created_at, updated_at

// Relationships:
- transformation(): BelongsTo
- item(): BelongsTo
```

### ItemSubstitution

Defines items that can substitute for others.

```php
namespace RobinsonRyan\Inventory\Catalog\Models;

// Fields:
- id: uuid (primary key)
- item_id: uuid (the original item)
- substitute_item_id: uuid (the substitute)
- priority: integer (default: 0, lower = preferred)
- ratio: decimal(10,4) (default: 1.0, how many substitutes per original)
- is_bidirectional: boolean (default: false)
- notes: text|null
- created_at, updated_at

// Relationships:
- item(): BelongsTo
- substituteItem(): BelongsTo
```

## Tag Definitions

### ItemCategoryDefinition

```php
namespace RobinsonRyan\Inventory\Catalog\TagDefinitions;

final class ItemCategoryDefinition extends TagDefinition
{
    public static string $slug = 'item-category';
    public static string $name = 'Item Category';
    public static bool $singleSelect = true;
    public static bool $global = true;

    public const array DEFAULT_VALUES = [
        'part',
        'supply',
        'fluid',
        'labor',
        'service',
        'assembly',
    ];
}
```

## Actions

### CreateItem

```php
namespace RobinsonRyan\Inventory\Catalog\Actions;

final class CreateItem
{
    public function execute(ItemData $data): Item;
}
```

### UpdateItem

```php
namespace RobinsonRyan\Inventory\Catalog\Actions;

final class UpdateItem
{
    public function execute(Item $item, ItemData $data): Item;
}
```

### DefineTransformation

```php
namespace RobinsonRyan\Inventory\Catalog\Actions;

final class DefineTransformation
{
    public function execute(TransformationData $data): Transformation;
}
```

### CreateKit

```php
namespace RobinsonRyan\Inventory\Catalog\Actions;

final class CreateKit
{
    public function execute(KitData $data): Kit;
}
```

### DefineSubstitution

```php
namespace RobinsonRyan\Inventory\Catalog\Actions;

final class DefineSubstitution
{
    public function execute(
        Item $item,
        Item $substitute,
        int $priority = 0,
        float $ratio = 1.0,
        bool $bidirectional = false
    ): ItemSubstitution;
}
```

## Data Objects

### ItemData

```php
namespace RobinsonRyan\Inventory\Catalog\Data;

final readonly class ItemData
{
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

    public static function fromArray(array $data): self;
    public function toArray(): array;
}
```

### KitData

```php
namespace RobinsonRyan\Inventory\Catalog\Data;

final readonly class KitData
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $itemId = null, // Existing item or will create
        public array $items = [], // [{item_id, quantity, is_optional, sort_order}]
        public bool $isActive = true,
    ) {}
}
```

### TransformationData

```php
namespace RobinsonRyan\Inventory\Catalog\Data;

final readonly class TransformationData
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public array $inputs = [],  // [{item_id, quantity}]
        public array $outputs = [], // [{item_id, quantity}]
        public bool $isActive = true,
        public ?array $metadata = null,
    ) {}
}
```

## Migrations

1. `create_items_table.php`
2. `create_kits_table.php`
3. `create_kit_items_table.php`
4. `create_transformations_table.php`
5. `create_transformation_inputs_table.php`
6. `create_transformation_outputs_table.php`
7. `create_assemblies_table.php`
8. `create_item_substitutions_table.php`

## Tests

### Feature Tests

- `tests/Feature/Catalog/ItemTest.php`
  - it creates an item with required fields
  - it creates an item with all fields
  - it updates an item
  - it sets item category via tag
  - it scopes items by category
  - it validates unique sku

- `tests/Feature/Catalog/KitTest.php`
  - it creates a kit with items
  - it calculates kit total cost
  - it handles optional kit items

- `tests/Feature/Catalog/TransformationTest.php`
  - it creates a transformation with inputs and outputs
  - it validates transformation has at least one input
  - it validates transformation has at least one output

- `tests/Feature/Catalog/SubstitutionTest.php`
  - it creates a substitution
  - it creates bidirectional substitution
  - it finds substitutes for an item

### Unit Tests

- `tests/Unit/Catalog/ItemDataTest.php`
- `tests/Unit/Catalog/TagDefinitionsTest.php`

## Directory Structure

```
src/Catalog/
├── Models/
│   ├── Item.php
│   ├── Kit.php
│   ├── KitItem.php
│   ├── Assembly.php
│   ├── Transformation.php
│   ├── TransformationInput.php
│   ├── TransformationOutput.php
│   └── ItemSubstitution.php
├── TagDefinitions/
│   └── ItemCategoryDefinition.php
├── Actions/
│   ├── CreateItem.php
│   ├── UpdateItem.php
│   ├── CreateKit.php
│   ├── DefineTransformation.php
│   └── DefineSubstitution.php
└── Data/
    ├── ItemData.php
    ├── KitData.php
    └── TransformationData.php

database/migrations/
├── 2026_01_01_000010_create_items_table.php
├── 2026_01_01_000011_create_kits_table.php
├── 2026_01_01_000012_create_kit_items_table.php
├── 2026_01_01_000013_create_transformations_table.php
├── 2026_01_01_000014_create_transformation_inputs_table.php
├── 2026_01_01_000015_create_transformation_outputs_table.php
├── 2026_01_01_000016_create_assemblies_table.php
└── 2026_01_01_000017_create_item_substitutions_table.php

tests/Feature/Catalog/
├── ItemTest.php
├── KitTest.php
├── TransformationTest.php
└── SubstitutionTest.php

tests/Unit/Catalog/
├── ItemDataTest.php
└── TagDefinitionsTest.php
```
