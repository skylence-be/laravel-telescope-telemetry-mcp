<?php

namespace Skylence\TelescopeMcp\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Skylence\TelescopeMcp\TelescopeTelemetryServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Additional setup if needed
    }
    
    protected function getPackageProviders($app)
    {
        return [
            TelescopeServiceProvider::class,
            TelescopeTelemetryServiceProvider::class,
        ];
    }
    
    protected function getEnvironmentSetUp($app)
    {
        // Setup default database for testing
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        
        // Configure Telescope
        $app['config']->set('telescope.enabled', true);
        $app['config']->set('telescope.storage.database.connection', 'testbench');
        
        // Configure Telescope Telemetry
        $app['config']->set('telescope-telemetry.mcp.enabled', true);
        $app['config']->set('telescope-telemetry.mcp.auth.enabled', false);
        $app['config']->set('telescope-telemetry.mcp.cache.enabled', false);
        $app['config']->set('telescope-telemetry.mcp.limits.default', 10);
        $app['config']->set('telescope-telemetry.mcp.limits.maximum', 25);
    }
    
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../vendor/laravel/telescope/database/migrations');
    }
}
