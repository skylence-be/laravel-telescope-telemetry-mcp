<?php

namespace LaravelTelescope\Telemetry;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use LaravelTelescope\Telemetry\Services\PaginationManager;
use LaravelTelescope\Telemetry\Services\ResponseFormatter;
use LaravelTelescope\Telemetry\Services\PerformanceAnalyzer;
use LaravelTelescope\Telemetry\Services\QueryAnalyzer;
use LaravelTelescope\Telemetry\Services\CacheManager;
use LaravelTelescope\Telemetry\Services\AggregationService;
use LaravelTelescope\Telemetry\Http\Middleware\AuthenticateMcp;
use LaravelTelescope\Telemetry\Http\Middleware\OptimizeResponse;

class TelescopeTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telescope-telemetry.php' => config_path('telescope-telemetry.php'),
            ], 'telescope-telemetry-config');
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        
        $this->registerMiddleware();
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/telescope-telemetry.php', 'telescope-telemetry'
        );

        $this->registerServices();
        $this->registerTools();
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

        $this->app->singleton(CacheManager::class, function ($app) {
            $cacheDriver = $app['config']->get('telescope-telemetry.mcp.cache.driver', 'redis');
            return new CacheManager(
                $app['config']->get('telescope-telemetry.mcp.cache'),
                $app['cache']->driver($cacheDriver)
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
        // Register OverviewTool separately since it has special config needs
        $this->app->singleton(\LaravelTelescope\Telemetry\Tools\OverviewTool::class, function ($app) {
            return new \LaravelTelescope\Telemetry\Tools\OverviewTool(
                $app['config']->get('telescope-telemetry.mcp', []),
                $app[\LaravelTelescope\Telemetry\Services\PaginationManager::class],
                $app[\LaravelTelescope\Telemetry\Services\ResponseFormatter::class],
                $app[\LaravelTelescope\Telemetry\Services\CacheManager::class]
            );
        });

        // Register QueriesTool separately since it has special config needs
        $this->app->singleton(\LaravelTelescope\Telemetry\Tools\QueriesTool::class, function ($app) {
            return new \LaravelTelescope\Telemetry\Tools\QueriesTool(
                $app['config']->get('telescope-telemetry.mcp.tools.queries', []),
                $app[\LaravelTelescope\Telemetry\Services\PaginationManager::class],
                $app[\LaravelTelescope\Telemetry\Services\ResponseFormatter::class],
                $app[\LaravelTelescope\Telemetry\Services\CacheManager::class]
            );
        });

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
                    $app[ResponseFormatter::class],
                    $app[CacheManager::class]
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
        return "LaravelTelescope\\Telemetry\\Tools\\{$toolName}Tool";
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
}
