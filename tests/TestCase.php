<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use RobinsonRyan\Inventory\Contracts\TenantResolver;
use RobinsonRyan\Inventory\InventoryServiceProvider;
use RobinsonRyan\Taxon\TaxonServiceProvider;
use RobinsonRyan\Vendor\VendorServiceProvider;
use RuntimeException;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * The tenant every test runs under. Fixed so assertions can reference it.
     */
    public const TENANT_ID = '019fdd00-0000-7000-8000-000000000001';

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
        // Dependency migrations, resolved through the composer install rather than
        // by walking up the directory tree — the on-disk sibling layout is not
        // stable (robinsonryan/vendor lives in a directory named `vendor-pkg`).
        $this->loadMigrationsFrom($this->dependencyMigrationPath('taxon'));
        $this->loadMigrationsFrom($this->dependencyMigrationPath('vendor'));

        // Load inventory migrations
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }

    /**
     * Locate an installed first-party package's migration directory.
     *
     * Fails loudly: a missing dependency here silently produces "no such table"
     * failures scattered across the suite instead of one legible error.
     */
    private function dependencyMigrationPath(string $package): string
    {
        $path = dirname(__DIR__).'/vendor/robinsonryan/'.$package.'/database/migrations';

        if (! is_dir($path)) {
            throw new RuntimeException(
                "Missing migrations for robinsonryan/{$package} at {$path}. Run `composer install`.",
            );
        }

        return $path;
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

        // Configure inventory. Tenancy stays ON — it is the package default, and
        // the tenant-scoped tables (locations, stock, stock_movements,
        // reservations, reorder_recommendations) declare tenant_id NOT NULL, so
        // there is no schema in which disabling it actually works. See QUEUE.md.
        $app['config']->set('inventory.id_type', 'uuid7');
        $app['config']->set('inventory.tenant.enabled', true);

        $app->singleton(TenantResolver::class, fn (): TenantResolver => new FixedTenantResolver(self::TENANT_ID));
    }
}
