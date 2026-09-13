<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory;

use Illuminate\Support\ServiceProvider;
use RobinsonRyan\Inventory\Console\SyncTagValuesCommand;
use RobinsonRyan\Inventory\Contracts\ReservationContract;
use RobinsonRyan\Inventory\Stock\Services\ReservationService;
use RobinsonRyan\Pact\Facades\Pact as PactFacade;
use RobinsonRyan\Pact\Pact;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inventory.php', 'inventory');

        $this->app->bind(ReservationContract::class, ReservationService::class);
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->publishMigrations();
        $this->registerCommands();
        $this->registerEventListeners();
        $this->registerShapes();
    }

    /**
     * Hand the item shape and its category vocabulary to Pact when the
     * application has Pact installed.
     *
     * Guarded rather than required: this package works without Pact, and an
     * application that has both should not have to restate the item contract
     * in a form request, a resource and a TypeScript interface.
     *
     * The path is the package's pact root, not its `shapes/` directory: Pact
     * reads `shapes/`, `vocabularies/` and `groups/` beneath the path it is
     * given (spec §5.1).
     */
    private function registerShapes(): void
    {
        if (! class_exists(Pact::class)) {
            return;
        }

        PactFacade::shapesFrom(__DIR__.'/../resources/pact', 'laravel-inventory');
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

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            SyncTagValuesCommand::class,
        ]);
    }

    private function registerEventListeners(): void
    {
        // Event listeners for Jobs integration will be registered here
        // once the Jobs module is implemented
    }
}
