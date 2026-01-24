<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory;

use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inventory.php', 'inventory');
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->publishMigrations();
        $this->registerEventListeners();
    }

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/inventory.php' => config_path('inventory.php'),
        ], 'inventory-config');
    }

    private function publishMigrations(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'inventory-migrations');
    }

    private function registerEventListeners(): void
    {
        // Event listeners for Jobs integration will be registered here
        // once the Jobs module is implemented
    }
}
