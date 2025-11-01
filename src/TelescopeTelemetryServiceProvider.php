<?php

declare(strict_types=1);

namespace Skylence\TelescopeMcp;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Skylence\TelescopeMcp\Http\Middleware\AuthenticateMcp;
use Skylence\TelescopeMcp\Http\Middleware\OptimizeResponse;
use Skylence\TelescopeMcp\MCP\TelescopeMcpServer;
use Skylence\TelescopeMcp\Services\AggregationService;
use Skylence\TelescopeMcp\Services\PaginationManager;
use Skylence\TelescopeMcp\Services\PerformanceAnalyzer;
use Skylence\TelescopeMcp\Services\QueryAnalyzer;
use Skylence\TelescopeMcp\Services\ResponseFormatter;

final class TelescopeTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/telescope-telemetry.php',
            'telescope-telemetry'
        );

        // Register core services
        $this->registerServices();

        // Register MCP server
        $this->app->singleton(TelescopeMcpServer::class, function ($app) {
            return new TelescopeMcpServer();
        });

        // Register tools
        $this->registerTools();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! config('telescope-telemetry.mcp.enabled', true)) {
            return;
        }

        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/telescope-telemetry.php' => config_path('telescope-telemetry.php'),
        ], 'telescope-telemetry-config');

        // Register middleware
        $this->registerMiddleware();

        // Register routes
        $this->registerRoutes();
    }

    /**
     * Register core services.
     */
    protected function registerServices(): void
    {
        $this->app->singleton(PaginationManager::class, function ($app) {
            return new PaginationManager(
                $app['config']->get('telescope-telemetry.mcp.limits')
            );
        });

        $this->app->singleton(ResponseFormatter::class, function ($app) {
            return new ResponseFormatter(
                $app['config']->get('telescope-telemetry.mcp.response')
            );
        });

        $this->app->singleton(PerformanceAnalyzer::class, function ($app) {
            return new PerformanceAnalyzer(
                $app['config']->get('telescope-telemetry.mcp.analysis')
            );
        });

        $this->app->singleton(QueryAnalyzer::class, function ($app) {
            return new QueryAnalyzer(
                $app['config']->get('telescope-telemetry.mcp.analysis')
            );
        });

        $this->app->singleton(AggregationService::class, function ($app) {
            return new AggregationService(
                $app['config']->get('telescope-telemetry.mcp.aggregation')
            );
        });
    }

    /**
     * Register tool implementations.
     */
    protected function registerTools(): void
    {
        $toolsConfig = $this->app['config']->get('telescope-telemetry.mcp.tools', []);

        foreach ($toolsConfig as $toolName => $config) {
            if ($config['enabled'] ?? true) {
                $this->registerTool($toolName, $config);
            }
        }
    }

    /**
     * Register a specific tool.
     */
    protected function registerTool(string $name, array $config): void
    {
        $className = $this->getToolClassName($name);

        if (class_exists($className)) {
            $this->app->singleton($className, function ($app) use ($config, $className) {
                return new $className(
                    $config,
                    $app[PaginationManager::class],
                    $app[ResponseFormatter::class]
                );
            });

            // Register tool alias for easier resolution
            $this->app->alias($className, "telescope.tool.{$name}");
        }
    }

    /**
     * Get the tool class name from tool name.
     */
    protected function getToolClassName(string $name): string
    {
        $toolName = str_replace('_', '', ucwords($name, '_'));

        return "Skylence\\TelescopeMcp\\Tools\\{$toolName}Tool";
    }

    /**
     * Register middleware.
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('telescope.mcp.auth', AuthenticateMcp::class);
        $router->aliasMiddleware('telescope.mcp.optimize', OptimizeResponse::class);
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        $middleware = config('telescope-telemetry.mcp.middleware', ['api']);

        // Add authentication middleware if enabled
        if (config('telescope-telemetry.mcp.auth.enabled', true)) {
            $middleware[] = 'telescope.mcp.auth';
        }

        Route::group([
            'prefix' => config('telescope-telemetry.mcp.path', 'telescope-mcp'),
            'middleware' => $middleware,
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }
}
