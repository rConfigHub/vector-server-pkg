<?php

namespace Rconfig\VectorServer;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rconfig\VectorServer\Console\Commands\VectorMonitorAgentCheckIns;
use Rconfig\VectorServer\Http\Middleware\AgentAttachId;
use Rconfig\VectorServer\Http\Middleware\AgentCheckApiSyncAccess;
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

    protected function registerRoutes()
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'middleware' =>  ['agent.check.api.sync.access', 'cors'],
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
        $this->app['router']->aliasMiddleware('agent.attach.id', AgentAttachId::class);
        $this->app['router']->aliasMiddleware('agent.check.api.sync.access', AgentCheckApiSyncAccess::class);
    }

    protected function registerCommands()
    {
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
