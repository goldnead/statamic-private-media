<?php

namespace Goldnead\PrivateMedia\Tests;

use Goldnead\PrivateMedia\ServiceProvider;
use Goldnead\PrivateMedia\Tags\PrivateMedia as PrivateMediaTag;
use Goldnead\PrivateMedia\Tests\Fixtures\Viewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_media');

        AssetContainer::make('private')->disk('private_media')->title('Private')->save();

        // Statamic mounts addon routes and tags from its booted callback,
        // which Testbench never fires; boot the addon the way that would.
        $this->app->getProvider(ServiceProvider::class)?->bootAddon();
        PrivateMediaTag::register();
    }

    /**
     * Registered here, not by a `migrate` call in setUp(): Testbench runs this
     * before RefreshDatabase opens its transaction. DDL inside that transaction
     * commits it implicitly under MySQL, and the next savepoint then fails.
     *
     * The entitlements sibling's migrations are listed too: the addon manifest
     * names only this package, so Statamic never boots that one here.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-entitlements/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            ...parent::getPackageProviders($app),
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\IdentityContracts\ServiceProvider::class,
            \Goldnead\Entitlements\ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('brand-context.multi_brand', false);
        $app['config']->set('entitlements.cp.enabled', false);
        $app['config']->set('queue.default', 'sync');

        // The host's Eloquent user, as adriangoldner.com has one.
        $app['config']->set('auth.providers.users.driver', 'eloquent');
        $app['config']->set('auth.providers.users.model', Viewer::class);

        $app['config']->set('private-media.routes.enabled', true);
        $app['config']->set('private-media.source.container', 'private');
    }

    /**
     * In-memory SQLite by default; DB_DRIVER=mysql runs the identical suite
     * against a real server (phpunit.mysql.xml).
     *
     * @return array<string, mixed>
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'private_media_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }
}
