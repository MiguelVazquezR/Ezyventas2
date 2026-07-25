<?php

namespace Ezyventas\AiAgent;

use App\AiTools\WriteModeGate;
use Ezyventas\AiAgent\Contracts\AiToolProvider;
use Ezyventas\AiAgent\Support\ToolRegistry;
use Illuminate\Support\ServiceProvider;

class AiAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ai-agent.php', 'ai-agent'
        );

        $this->app->singleton(ToolRegistry::class, function ($app) {
            return new ToolRegistry($app->make(AiToolProvider::class));
        });

        $this->app->scoped(WriteModeGate::class, function () {
            return new WriteModeGate();
        });
    }

    public function boot(): void
    {
        // Routes are loaded from routes/web.php (to inherit the web middleware group)
        // so they go through EnsureFrontendRequestsAreStateful for Sanctum SPA auth.
        // $this->loadRoutesFrom(__DIR__ . '/../routes/ai-agent.php');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/ai-agent.php' => config_path('ai-agent.php'),
        ], 'ai-agent-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'ai-agent-migrations');
    }
}
