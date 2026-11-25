<?php

namespace Goldnead\PrivateMedia;

use Goldnead\Entitlements\EntitlementManager;
use Goldnead\PrivateMedia\Access\ClosedMediaAccess;
use Goldnead\PrivateMedia\Access\EntitlementsMediaAccess;
use Goldnead\PrivateMedia\Contracts\MediaAccess;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    private static bool $closedWarned = false;

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/private-media.php', 'private-media');

        // The access seam. entitlements is a `suggest`, so its presence is
        // checked, not assumed; without it every request is refused. A site
        // binding its own MediaAccess after this provider wins.
        $this->app->bindIf(MediaAccess::class, fn ($app) => $this->mediaAccess($app, class_exists(EntitlementManager::class)));

        // Bound by class name, never under a short slug.
        $this->app->singleton(PrivateMedia::class);

        $langPath = __DIR__.'/../resources/lang';
        $this->app->resolving('translator', fn ($translator) => $translator->addNamespace('private-media', $langPath));

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('private-media', $langPath);
        }
    }

    public function bootAddon(): void
    {
        // Own names, not Statamic's bootRoutes()/bootCommands()/bootPublishables():
        // AddonServiceProvider::boot() calls those itself right before
        // bootAddon(), so overriding them ran every body twice.
        $this->registerMigrations()
            ->registerCommands()
            ->registerDeliveryRoute()
            ->registerPublishables();
    }

    /**
     * The default access seam. Without entitlements every file stays closed:
     * logged once per process, so the cause is visible instead of silent.
     */
    protected function mediaAccess(Application $app, bool $entitlementsPresent): MediaAccess
    {
        if ($entitlementsPresent) {
            return $app->make(EntitlementsMediaAccess::class);
        }

        if (! self::$closedWarned) {
            self::$closedWarned = true;
            Log::warning('private-media: no MediaAccess bound, every private file is closed. Install goldnead/statamic-entitlements (1.3 or later) or bind MediaAccess.');
        }

        return new ClosedMediaAccess;
    }

    /**
     * Mounted here rather than from routes/web.php, which core would load on
     * its own terms: the prefix and middleware come from config, and the
     * whole route is off until a site switches it on.
     */
    protected function registerDeliveryRoute(): self
    {
        if (! config('private-media.routes.enabled', false) || $this->app->routesAreCached()) {
            return $this;
        }

        $middleware = (array) config('private-media.routes.middleware', ['web']);
        $throttle = config('private-media.routes.throttle');

        if (is_string($throttle) && $throttle !== '') {
            $middleware[] = 'throttle:'.$throttle;
        }

        Route::middleware($middleware)
            ->prefix(trim((string) config('private-media.routes.prefix', 'private-media'), '/'))
            ->name('private-media.')
            ->group(__DIR__.'/../routes/media.php');

        Route::getRoutes()->refreshNameLookups();

        return $this;
    }

    protected function registerMigrations(): self
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        return $this;
    }

    /**
     * Registered by hand because core's own command discovery runs after
     * Statamic's boot sequence, which a plain console context never reaches.
     */
    protected function registerCommands(): self
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\Commands\Prune::class]);
        }

        return $this;
    }

    protected function registerPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'private-media-migrations');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/private-media'),
        ], 'private-media-translations');

        return $this;
    }
}
