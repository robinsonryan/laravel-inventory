<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Customize the table names used by the inventory package.
    |
    */
    'tables' => [
        // Catalog module
        'items' => 'items',
        'kits' => 'kits',
        'kit_items' => 'kit_items',
        'assemblies' => 'assemblies',
        'transformations' => 'transformations',
        'transformation_inputs' => 'transformation_inputs',
        'transformation_outputs' => 'transformation_outputs',
        'item_substitutions' => 'item_substitutions',

        // Stock module
        'locations' => 'locations',
        'stock' => 'stock',
        'stock_movements' => 'stock_movements',
        'reservations' => 'reservations',

        // Purchasing module
        'purchase_orders' => 'purchase_orders',
        'purchase_order_lines' => 'purchase_order_lines',
        'receipts' => 'receipts',
        'receipt_lines' => 'receipt_lines',

        // Reconciliation module
        'cycle_counts' => 'cycle_counts',
        'cycle_count_lines' => 'cycle_count_lines',
        'variance_adjustments' => 'variance_adjustments',

        // Forecasting module
        'reorder_recommendations' => 'reorder_recommendations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Primary Key Type
    |--------------------------------------------------------------------------
    |
    | The type of primary key to use for inventory records.
    | Options: 'uuid7', 'uuid', 'ulid', 'increments'
    |
    */
    'id_type' => 'uuid7',

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how inventory relates to tenants in your application.
    |
    */
    'tenant' => [
        'enabled' => true,
        'column' => 'tenant_id',
        'scoped' => [
            // Catalog is global by default
            'items' => false,
            'kits' => false,
            'assemblies' => false,
            'transformations' => false,
            'item_substitutions' => false,

            // Stock is per-tenant
            'locations' => true,
            'stock' => true,
            'stock_movements' => true,
            'reservations' => true,

            // Purchasing is per-tenant
            'purchase_orders' => true,
            'receipts' => true,

            // Reconciliation is per-tenant
            'cycle_counts' => true,

            // Forecasting is per-tenant
            'reorder_recommendations' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Jobs Integration
    |--------------------------------------------------------------------------
    |
    | Configure integration with work orders / jobs for automatic
    | reservation and consumption of inventory.
    |
    */
    'jobs' => [
        'model' => null,  // Set in consuming app, e.g., App\Models\WorkOrder::class
        'events' => [
            'created' => null,    // e.g., App\Events\WorkOrderCreated::class
            'completed' => null,  // e.g., App\Events\WorkOrderCompleted::class
            'cancelled' => null,  // e.g., App\Events\WorkOrderCancelled::class
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Actor Configuration
    |--------------------------------------------------------------------------
    |
    | Configure who performs actions for audit trail purposes.
    |
    */
    'actor' => [
        'model' => null,  // Set in consuming app, e.g., App\Models\Admin::class
        'guard' => 'web',
    ],

    /*
    |--------------------------------------------------------------------------
    | Business Rules
    |--------------------------------------------------------------------------
    |
    | Configure inventory business rules and constraints.
    |
    */
    'rules' => [
        'allow_negative_stock' => false,
        'reservation_expiry_hours' => 72,
        'movement_immutable' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Overrides
    |--------------------------------------------------------------------------
    |
    | Override default models with your own implementations.
    |
    */
    'models' => [
        // Catalog
        'item' => \RobinsonRyan\Inventory\Catalog\Models\Item::class,
        'kit' => \RobinsonRyan\Inventory\Catalog\Models\Kit::class,
        'kit_item' => \RobinsonRyan\Inventory\Catalog\Models\KitItem::class,
        'assembly' => \RobinsonRyan\Inventory\Catalog\Models\Assembly::class,
        'transformation' => \RobinsonRyan\Inventory\Catalog\Models\Transformation::class,
        'item_substitution' => \RobinsonRyan\Inventory\Catalog\Models\ItemSubstitution::class,

        // Stock
        'location' => \RobinsonRyan\Inventory\Stock\Models\Location::class,
        'stock' => \RobinsonRyan\Inventory\Stock\Models\Stock::class,
        'stock_movement' => \RobinsonRyan\Inventory\Stock\Models\StockMovement::class,
        'reservation' => \RobinsonRyan\Inventory\Stock\Models\Reservation::class,

        // Purchasing
        'purchase_order' => \RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder::class,
        'purchase_order_line' => \RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine::class,
        'receipt' => \RobinsonRyan\Inventory\Purchasing\Models\Receipt::class,

        // Reconciliation
        'cycle_count' => \RobinsonRyan\Inventory\Reconciliation\Models\CycleCount::class,
        'cycle_count_line' => \RobinsonRyan\Inventory\Reconciliation\Models\CycleCountLine::class,
        'variance_adjustment' => \RobinsonRyan\Inventory\Reconciliation\Models\VarianceAdjustment::class,

        // Forecasting
        'reorder_recommendation' => \RobinsonRyan\Inventory\Forecasting\Models\ReorderRecommendation::class,
    ],
];
