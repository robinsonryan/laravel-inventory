# Forecasting Module Specification

## Overview

The Forecasting module provides demand planning and reorder recommendations. It analyzes historical consumption patterns to suggest when and how much to reorder.

## Models

### ReorderRecommendation

A recommended reorder for an item.

```php
namespace RobinsonRyan\Inventory\Forecasting\Models;

// Fields:
- id: uuid (primary key)
- tenant_id: uuid (FK)
- item_id: uuid (FK)
- vendor_id: uuid|null (FK, preferred vendor)
- current_quantity: decimal(10,4)
- reserved_quantity: decimal(10,4)
- available_quantity: decimal(10,4)
- min_stock_level: integer|null
- recommended_quantity: integer
- estimated_stockout_date: date|null
- average_daily_usage: decimal(10,4)|null
- lead_time_days: integer|null
- notes: text|null
- generated_at: datetime
- actioned_at: datetime|null (when PO was created)
- dismissed_at: datetime|null
- purchase_order_id: uuid|null (FK, if acted upon)
- metadata: json|null
- created_at, updated_at

// Relationships:
- item(): BelongsTo (Item from Catalog)
- vendor(): BelongsTo (Vendor from vendor package)
- purchaseOrder(): BelongsTo (PurchaseOrder from Purchasing)

// Traits:
- BelongsToTenant
- HasUuid7
```

## Services

### DemandAnalyzer

Analyzes historical consumption to calculate usage patterns.

```php
namespace RobinsonRyan\Inventory\Forecasting\Services;

final class DemandAnalyzer
{
    // Calculate average daily usage over a period
    public function calculateAverageDailyUsage(
        string $itemId,
        ?int $days = 90
    ): float;

    // Calculate usage trend (increasing, decreasing, stable)
    public function calculateTrend(
        string $itemId,
        ?int $days = 90
    ): string; // 'increasing', 'decreasing', 'stable'

    // Get total consumption in period
    public function getTotalConsumption(
        string $itemId,
        Carbon $from,
        Carbon $to
    ): float;

    // Get consumption by period (daily, weekly, monthly)
    public function getConsumptionByPeriod(
        string $itemId,
        string $period = 'daily',
        ?int $periods = 30
    ): Collection;

    // Forecast future demand
    public function forecast(
        string $itemId,
        int $days = 30
    ): float;
}
```

### ReorderCalculator

Calculates when and how much to reorder.

```php
namespace RobinsonRyan\Inventory\Forecasting\Services;

final class ReorderCalculator
{
    // Calculate reorder point (when to order)
    public function calculateReorderPoint(string $itemId): int;

    // Calculate reorder quantity (how much to order)
    public function calculateReorderQuantity(string $itemId): int;

    // Calculate estimated stockout date
    public function estimateStockoutDate(string $itemId): ?Carbon;

    // Get items below reorder point
    public function getItemsBelowReorderPoint(): Collection;

    // Generate reorder recommendation for an item
    public function generateRecommendation(string $itemId): ?ReorderRecommendation;

    // Generate recommendations for all items needing reorder
    public function generateAllRecommendations(): Collection;
}
```

## Actions

### GenerateRecommendations

Generate reorder recommendations for items below reorder point.

```php
namespace RobinsonRyan\Inventory\Forecasting\Actions;

final class GenerateRecommendations
{
    public function execute(?array $itemIds = null): Collection;
}
```

### DismissRecommendation

Dismiss a recommendation (not needed).

```php
namespace RobinsonRyan\Inventory\Forecasting\Actions;

final class DismissRecommendation
{
    public function execute(ReorderRecommendation $recommendation): ReorderRecommendation;
}
```

### CreatePurchaseOrderFromRecommendation

Create a PO from a recommendation.

```php
namespace RobinsonRyan\Inventory\Forecasting\Actions;

final class CreatePurchaseOrderFromRecommendation
{
    public function execute(
        ReorderRecommendation $recommendation,
        ?string $vendorId = null
    ): PurchaseOrder;
}
```

### CreatePurchaseOrderFromMultipleRecommendations

Create a PO from multiple recommendations (same vendor).

```php
namespace RobinsonRyan\Inventory\Forecasting\Actions;

final class CreatePurchaseOrderFromMultipleRecommendations
{
    public function execute(array $recommendationIds, string $vendorId): PurchaseOrder;
}
```

## Data Objects

### ForecastData

```php
namespace RobinsonRyan\Inventory\Forecasting\Data;

final readonly class ForecastData
{
    public function __construct(
        public string $itemId,
        public float $averageDailyUsage,
        public string $trend,
        public float $forecastedDemand,
        public int $daysToStockout,
        public int $recommendedOrderQuantity,
    ) {}
}
```

## Migrations

1. `create_reorder_recommendations_table.php`

## Commands

### GenerateReorderRecommendationsCommand

Artisan command to generate recommendations (for scheduling).

```php
namespace RobinsonRyan\Inventory\Forecasting\Commands;

final class GenerateReorderRecommendationsCommand extends Command
{
    protected $signature = 'inventory:generate-recommendations {--tenant=}';
    protected $description = 'Generate reorder recommendations for items below reorder point';
}
```

## Tests

### Feature Tests

- `tests/Feature/Forecasting/DemandAnalyzerTest.php`
  - it calculates average daily usage
  - it detects increasing trend
  - it detects decreasing trend
  - it forecasts future demand
  - it handles items with no history

- `tests/Feature/Forecasting/ReorderCalculatorTest.php`
  - it calculates reorder point
  - it calculates reorder quantity
  - it estimates stockout date
  - it identifies items below reorder point
  - it generates recommendation

- `tests/Feature/Forecasting/RecommendationTest.php`
  - it creates recommendation
  - it dismisses recommendation
  - it creates PO from recommendation
  - it marks recommendation as actioned

### Unit Tests

- `tests/Unit/Forecasting/DemandAnalyzerTest.php`
- `tests/Unit/Forecasting/ReorderCalculatorTest.php`

## Directory Structure

```
src/Forecasting/
├── Models/
│   └── ReorderRecommendation.php
├── Services/
│   ├── DemandAnalyzer.php
│   └── ReorderCalculator.php
├── Actions/
│   ├── GenerateRecommendations.php
│   ├── DismissRecommendation.php
│   ├── CreatePurchaseOrderFromRecommendation.php
│   └── CreatePurchaseOrderFromMultipleRecommendations.php
├── Data/
│   └── ForecastData.php
└── Commands/
    └── GenerateReorderRecommendationsCommand.php

database/migrations/
└── 2026_01_01_000040_create_reorder_recommendations_table.php

tests/Feature/Forecasting/
├── DemandAnalyzerTest.php
├── ReorderCalculatorTest.php
└── RecommendationTest.php

tests/Unit/Forecasting/
├── DemandAnalyzerTest.php
└── ReorderCalculatorTest.php
```

## Algorithm Notes

### Average Daily Usage Calculation

```
ADU = Total Consumption in Period / Number of Days

Example: 90 units consumed over 30 days = 3 units/day ADU
```

### Reorder Point Calculation

```
ROP = (Average Daily Usage × Lead Time Days) + Safety Stock

Safety Stock = Average Daily Usage × Safety Days (default 7 days)

Example: ADU=3, Lead Time=5, Safety Days=7
ROP = (3 × 5) + (3 × 7) = 15 + 21 = 36 units
```

### Economic Order Quantity (simplified)

```
EOQ = sqrt((2 × Annual Demand × Order Cost) / Holding Cost)

Simplified (if costs not available):
ROQ = Average Daily Usage × (Lead Time + Review Period + Safety Days)
```

### Trend Detection

- Compare current period usage to previous period
- If current > previous by 10%+: 'increasing'
- If current < previous by 10%+: 'decreasing'
- Otherwise: 'stable'
