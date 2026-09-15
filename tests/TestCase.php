<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Tests;

use Illuminate\Database\Migrations\Migrator;
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

    /**
     * Locate an installed first-party package's migration directory.
     *
     * Fails loudly: a missing dependency here silently produces "no such table"
     * failures scattered across the suite instead of one legible error.
     */
    private function dependencyMigrationPath(string $package): string
    {
        $path = __DIR__.'/../vendor/robinsonryan/'.$package.'/database/migrations';

        if (! is_dir($path)) {
            throw new RuntimeException(
                "Missing migrations for robinsonryan/{$package} at {$path}. Run `composer install`.",
            );
        }

        return $path;
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The suite runs against a real PostgreSQL database — the DDEV `db`
        // service, on a database of its own called `testing` (created by the
        // post-start hook in .ddev/config.yaml). That is deliberate: SQLite
        // silently accepts things Postgres rejects, and it has already hidden a
        // live defect in the reservation availability lookup. Every value is
        // overridable so the suite can point at another server in CI.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'pgsql',
            'host' => env('INVENTORY_TEST_DB_HOST', 'db'),
            'port' => (int) env('INVENTORY_TEST_DB_PORT', 5432),
            'database' => env('INVENTORY_TEST_DB_DATABASE', 'testing'),
            'username' => env('INVENTORY_TEST_DB_USERNAME', 'db'),
            'password' => env('INVENTORY_TEST_DB_PASSWORD', 'db'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        // Configure taxon for UUID7
        $app['config']->set('taxon.id_type', 'uuid7');
        $app['config']->set('taxon.tenant.enabled', false);
        $app['config']->set('taxon.auto_create', true);

        $app['config']->set('inventory.id_type', 'uuid7');

        // A tenant resolver is required, not optional. Ten tables declare
        // tenant_id NOT NULL, so every insert needs one — there is no schema in
        // which "turning tenancy off" works. (The config flag that claimed to do
        // that was removed; nothing ever read it, and setting it false killed 215
        // tests by way of NOT NULL violations.)
        //
        // Binding a constant tenant here also means the suite CANNOT catch a
        // cross-tenant leak: every row belongs to the same tenant, so a query
        // that forgets to filter looks identical to one that does not. The
        // package does not filter reads by design — see BelongsToTenant — but if
        // that ever changes, these tests need a second tenant before they can
        // prove anything.
        $app->singleton(TenantResolver::class, fn (): TenantResolver => new FixedTenantResolver(self::TENANT_ID));

        // Register every migration directory with the migrator so RefreshDatabase's
        // one-time migration run picks them all up: taxon's, robinsonryan/vendor's
        // and this package's. Dependency paths are resolved through the composer
        // install rather than by walking up the directory tree — the on-disk sibling
        // layout is not stable (robinsonryan/vendor lives in a directory named
        // `vendor-pkg`).
        //
        // Registering paths rather than calling loadMigrationsFrom() is deliberate:
        // Testbench's helper migrates and rolls back around *every* test, which on a
        // real database means rebuilding thirty tables 527 times.
        $paths = [
            $this->dependencyMigrationPath('taxon'),
            $this->dependencyMigrationPath('vendor'),
            __DIR__.'/../database/migrations',
        ];

        $app->afterResolving('migrator', static function (Migrator $migrator) use ($paths): void {
            foreach ($paths as $path) {
                $migrator->path($path);
            }
        });
    }
}
