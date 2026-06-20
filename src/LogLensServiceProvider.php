<?php

namespace LogLens;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogLens\Console\IndexCommand;
use LogLens\Console\PruneCommand;
use LogLens\Console\SearchCommand;
use LogLens\Console\StatsCommand;
use LogLens\Console\TailCommand;
use LogLens\Console\TickCommand;
use LogLens\Contracts\LogSource;
use LogLens\Diagnostics\Diagnostics;
use LogLens\Fingerprint\FingerprintEngine;
use LogLens\Indexing\IndexManager;
use LogLens\Indexing\Indexer;
use LogLens\Indexing\TailReader;
use LogLens\Parsing\ParserManager;
use LogLens\Search\SearchEngine;
use LogLens\Security\Authorizer;
use LogLens\Security\Redactor;
use LogLens\Security\SafeRenderer;
use LogLens\Sources\LocalFileSource;
use LogLens\Tail\TailEngine;

/**
 * Hand-rolled provider (no spatie/laravel-package-tools — it cannot span
 * L8→13). Every cross-version API is guarded; no Kernel references, no
 * migrations, no mutable static state (Octane-safe).
 */
class LogLensServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/loglens.php', 'loglens');

        // Per-request services are container-scoped where available (Octane-safe
        // on L9+), falling back to singleton on Laravel 8 which lacks scoped().
        $this->scopedOrSingleton(LogSource::class, fn ($app) => new LocalFileSource(
            (array) $app['config']['loglens.roots'],
            (array) $app['config']['loglens.include'],
            (array) $app['config']['loglens.exclude']
        ));

        $this->app->bind(LocalFileSource::class, fn ($app) => $app->make(LogSource::class));

        $this->scopedOrSingleton(ParserManager::class, fn ($app) => new ParserManager(
            (array) $app['config']['loglens.parsing']
        ));

        $this->scopedOrSingleton(FingerprintEngine::class, fn ($app) => new FingerprintEngine(
            (array) $app['config']['loglens.fingerprint']
        ));

        $this->scopedOrSingleton(IndexManager::class, fn ($app) => new IndexManager(
            (array) $app['config']['loglens.index']
        ));

        $this->scopedOrSingleton(Indexer::class, fn ($app) => new Indexer(
            $app->make(LogSource::class),
            $app->make(ParserManager::class),
            $app->make(FingerprintEngine::class),
            $app->make(IndexManager::class),
            (array) $app['config']['loglens.index']
        ));

        $this->scopedOrSingleton(TailReader::class, fn ($app) => new TailReader(
            $app->make(LogSource::class),
            $app->make(ParserManager::class)
        ));

        $this->scopedOrSingleton(\LogLens\Indexing\WorkerHeartbeat::class, fn ($app) => new \LogLens\Indexing\WorkerHeartbeat(
            $app->make(\Illuminate\Contracts\Cache\Repository::class)
        ));

        $this->scopedOrSingleton(\LogLens\Indexing\IndexCoordinator::class, function ($app) {
            $config = (array) $app['config']['loglens.index'];
            $config['parsing'] = (array) $app['config']['loglens.parsing'];
            $config['fingerprint'] = (array) $app['config']['loglens.fingerprint'];

            return new \LogLens\Indexing\IndexCoordinator(
                $app->make(Indexer::class),
                $app->make(IndexManager::class),
                $app->make(LocalFileSource::class),
                $app->make(\LogLens\Indexing\WorkerHeartbeat::class),
                $app->make(\Illuminate\Contracts\Cache\Repository::class),
                $app->make(ParserManager::class),
                $app->make(FingerprintEngine::class),
                $config
            );
        });

        $this->scopedOrSingleton(SearchEngine::class, fn ($app) => new SearchEngine(
            $app->make(LogSource::class),
            $app->make(IndexManager::class),
            $app->make(ParserManager::class),
            (array) $app['config']['loglens.search']
        ));

        $this->scopedOrSingleton(TailEngine::class, fn ($app) => new TailEngine(
            $app->make(LogSource::class),
            $app->make(ParserManager::class),
            (array) $app['config']['loglens.tail']
        ));

        $this->scopedOrSingleton(Redactor::class, fn ($app) => new Redactor(
            (array) $app['config']['loglens.security.redaction']
        ));

        $this->app->singleton(SafeRenderer::class, fn () => new SafeRenderer());

        $this->scopedOrSingleton(Authorizer::class, fn ($app) => new Authorizer(
            (array) $app['config']['loglens.security'] + ['read_only' => (bool) $app['config']['loglens.read_only']]
        ));

        $this->scopedOrSingleton(Diagnostics::class, fn ($app) => new Diagnostics(
            $app->make(IndexManager::class)
        ));

        $this->scopedOrSingleton(\LogLens\Http\EntryPresenter::class, fn ($app) => new \LogLens\Http\EntryPresenter(
            $app->make(Redactor::class),
            $app->make(SafeRenderer::class),
            (int) $app['config']['loglens.parsing.max_display_bytes']
        ));

        $this->scopedOrSingleton(\LogLens\Browsing\Browser::class, fn ($app) => new \LogLens\Browsing\Browser(
            $app->make(LocalFileSource::class),
            $app->make(IndexManager::class),
            $app->make(\LogLens\Indexing\IndexCoordinator::class),
            $app->make(TailReader::class),
            $app->make(ParserManager::class),
            $app->make(\LogLens\Http\EntryPresenter::class)
        ));

        $this->scopedOrSingleton(\LogLens\FileManagement\FileManager::class, fn ($app) => new \LogLens\FileManagement\FileManager(
            $app->make(LocalFileSource::class),
            $app->make(IndexManager::class)
        ));

        $this->scopedOrSingleton(\LogLens\Search\SavedSearchStore::class, fn ($app) => new \LogLens\Search\SavedSearchStore(
            (string) $app['config']['loglens.index.directory']
        ));

        $this->scopedOrSingleton(\LogLens\FileManagement\Pruner::class, fn ($app) => new \LogLens\FileManagement\Pruner(
            $app->make(LocalFileSource::class),
            $app->make(IndexManager::class),
            $app->make(\LogLens\FileManagement\FileManager::class)
        ));
    }

    public function boot(): void
    {
        // Interpret offset-less log timestamps in the app's timezone (overridable)
        // instead of forcing UTC, so time filters / histograms / tail align.
        \LogLens\Support\Timestamp::useTimezone(
            $this->app['config']['loglens.parsing.timezone'] ?? $this->app['config']['app.timezone'] ?? null
        );

        $this->registerGates();
        $this->registerRateLimiters();
        $this->registerRoutes();
        $this->registerResources();
        $this->registerPublishing();
        $this->registerCommands();
        $this->registerAbout();
    }

    private function registerRateLimiters(): void
    {
        $limits = (array) $this->app['config']['loglens.security.rate_limits'];
        foreach (['search', 'tail', 'index', 'analytics'] as $name) {
            \Illuminate\Support\Facades\RateLimiter::for('loglens-' . $name, function ($request) use ($limits, $name) {
                $perMinute = (int) ($limits[$name] ?? 60);
                $key = optional($request->user())->getAuthIdentifier() ?: $request->ip();

                return \Illuminate\Cache\RateLimiting\Limit::perMinute($perMinute)->by($name . ':' . $key);
            });
        }
    }

    /**
     * Use scoped() on Laravel 9+ (Octane request isolation), singleton on 8.
     */
    private function scopedOrSingleton(string $abstract, \Closure $concrete): void
    {
        if (method_exists($this->app, 'scoped')) {
            $this->app->scoped($abstract, $concrete);
        } else {
            $this->app->singleton($abstract, $concrete);
        }
    }

    private function registerGates(): void
    {
        foreach (Authorizer::GATES as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, function ($user = null) use ($ability) {
                    // Default policy: allow in local env, otherwise deny until
                    // the host app defines the gate (production default-deny).
                    return Authorizer::defaultPolicy($ability, $user);
                });
            }
        }
    }

    private function registerRoutes(): void
    {
        $config = $this->app['config']['loglens.route'];

        $attributes = [
            'prefix' => $config['prefix'] ?? 'loglens',
            'middleware' => array_merge(
                (array) ($config['middleware'] ?? ['web']),
                [\LogLens\Http\Middleware\IpAllowlist::class, \LogLens\Http\Middleware\Authorize::class]
            ),
        ];
        if (! empty($config['domain'])) {
            $attributes['domain'] = $config['domain'];
        }

        Route::group($attributes, function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
            if (! $this->app['config']['loglens.api_only']) {
                $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
            }
        });
    }

    private function registerResources(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'loglens');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'loglens');
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/loglens.php' => $this->app->configPath('loglens.php'),
        ], 'loglens-config');
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            IndexCommand::class,
            SearchCommand::class,
            TailCommand::class,
            StatsCommand::class,
            PruneCommand::class,
            TickCommand::class,
        ]);
    }

    /**
     * Register `php artisan about` info only when AboutCommand exists (L9.21+).
     */
    private function registerAbout(): void
    {
        if (! class_exists(\Illuminate\Foundation\Console\AboutCommand::class)) {
            return;
        }

        \Illuminate\Foundation\Console\AboutCommand::add('LogLens', fn () => [
            'Version' => Diagnostics::VERSION,
            'Index store' => $this->app->make(IndexManager::class)->driverName(),
            'API only' => $this->app['config']['loglens.api_only'] ? 'true' : 'false',
            'Read only' => $this->app['config']['loglens.read_only'] ? 'true' : 'false',
        ]);
    }
}
