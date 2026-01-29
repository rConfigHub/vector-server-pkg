<?php

namespace Rconfig\VectorServer;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rconfig\VectorServer\Console\Commands\VectorAgentDownloadBinary;
use Rconfig\VectorServer\Console\Commands\VectorMonitorAgentCheckIns;
use Rconfig\VectorServer\Http\Middleware\AgentAttachId;
use Rconfig\VectorServer\Http\Middleware\AgentCheckApiSyncAccess;
use Rconfig\VectorServer\Http\Middleware\AgentEnforceHttps;
use Rconfig\VectorServer\Services\AgentQueue\QueueHandler;

class VectorServerServiceProvider extends ServiceProvider
{

    public function register()
    {
        $this->app->singleton('agentqueueservice', function () {
            return new QueueHandler();
        });

        $this->registerConfig();
    }

    public function boot()
    {
        $this->publishConfig();
        $this->publishViews();
        $this->ensureViewsPublished();
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'vector-server');
        $this->registerRoutes();
        $this->loadMiddleware();
        $this->registerCommands();
        $this->loadMigrations();
        $this->publishTests();
        $this->loadScheduler();
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/vector-server.php',
            'vector-server'
        );
    }

    protected function publishConfig()
    {
        $this->publishes([
            __DIR__ . '/../config/vector-server.php' => config_path('vector-server.php'),
        ], 'config');
    }

    protected function publishViews()
    {
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/vector-server'),
        ], 'vector-server-views');
    }

    protected function ensureViewsPublished(): void
    {
        $targetDir = resource_path('views/vendor/vector-server');
        $targetFile = $targetDir . '/vector/install.sh.blade.php';

        $sourceDir = __DIR__ . '/../resources/views';
        $sourceFile = $sourceDir . '/vector/install.sh.blade.php';

        if (! is_file($sourceFile)) {
            return;
        }

        $targetFileDir = dirname($targetFile);
        if (! is_dir($targetFileDir)) {
            mkdir($targetFileDir, 0755, true);
        }

        if (is_dir($targetFileDir)) {
            $shouldCopy = true;
            if (is_file($targetFile)) {
                $shouldCopy = hash_file('sha256', $sourceFile) !== hash_file('sha256', $targetFile);
            }
            if ($shouldCopy) {
                copy($sourceFile, $targetFile);
            }
        }
    }

    protected function registerRoutes()
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::group([], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/public_install.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/public_downloads.php');
        });

        Route::prefix('api')->group(function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api_agent_bootstrap.php');
        });

        Route::group([
            'middleware' =>  ['agent.enforce.https', 'agent.check.api.sync.access', 'cors'],
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api_agentsync.php');
        });

        // Web Api Routes with nameSpace (Api) removed
        Route::prefix('api')->middleware('auth:api')->group(function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/agents.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/agentlog.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/agentqueue.php');
        });
    }

    protected function loadMiddleware()
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('agent.enforce.https', AgentEnforceHttps::class);
        $router->aliasMiddleware('agent.attach.id', AgentAttachId::class);
        $router->aliasMiddleware('agent.check.api.sync.access', AgentCheckApiSyncAccess::class);
    }

    protected function registerCommands()
    {
        $this->commands([
            VectorAgentDownloadBinary::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                VectorMonitorAgentCheckIns::class,
            ]);
        }
    }

    protected function loadMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function publishTests()
    {
        // $this->publishes([
        //     __DIR__ . '/../tests/VectorserverTests' => base_path('tests/VectorserverTests'),
        // ], 'tests');
    }

    protected function loadScheduler()
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('vector:agent-checkins')->everyMinute();
    }
}
