<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use RobinsonRyan\Inventory\InventoryServiceProvider;
use RobinsonRyan\Taxon\TaxonServiceProvider;
use RobinsonRyan\Vendor\VendorServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            TaxonServiceProvider::class,
            VendorServiceProvider::class,
            InventoryServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // Load taxon migrations first (dependency)
        $taxonPath = dirname(__DIR__, 4).'/taxon/database/migrations';
        if (is_dir($taxonPath)) {
            $this->loadMigrationsFrom($taxonPath);
        }

        // Load inventory migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Configure taxon for UUID7
        $app['config']->set('taxon.id_type', 'uuid7');
        $app['config']->set('taxon.tenant.enabled', false);
        $app['config']->set('taxon.auto_create', true);

        // Configure inventory
        $app['config']->set('inventory.id_type', 'uuid7');
        $app['config']->set('inventory.tenant.enabled', false);
    }
}
