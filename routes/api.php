<?php

use Illuminate\Support\Facades\Route;
use LaravelTelescope\Telemetry\Http\Controllers\McpController;
use LaravelTelescope\Telemetry\Http\Controllers\OverviewController;
use LaravelTelescope\Telemetry\Http\Controllers\AnalysisController;

$config = config('telescope-telemetry.mcp');
$path = $config['path'] ?? 'telescope-telemetry';
$middleware = array_merge(
    $config['auth']['middleware'] ?? ['api'],
    ['telescope.mcp.optimize']
);

if ($config['auth']['enabled'] ?? true) {
    $middleware[] = 'telescope.mcp.auth';
}

Route::prefix($path)
    ->middleware($middleware)
    ->group(function () {
        // MCP Protocol endpoints
        Route::post('/', [McpController::class, 'handle']);
        Route::get('/tools', [McpController::class, 'listTools']);
        
        // Overview endpoints
        Route::get('/overview', [OverviewController::class, 'dashboard']);
        Route::get('/overview/health', [OverviewController::class, 'health']);
        Route::get('/overview/performance', [OverviewController::class, 'performance']);
        Route::get('/overview/problems', [OverviewController::class, 'problems']);
        
        // Analysis endpoints
        Route::get('/analysis/slow-queries', [AnalysisController::class, 'slowQueries']);
        Route::get('/analysis/n-plus-one', [AnalysisController::class, 'nPlusOne']);
        Route::get('/analysis/bottlenecks', [AnalysisController::class, 'bottlenecks']);
        Route::get('/analysis/trends', [AnalysisController::class, 'trends']);
        Route::get('/analysis/suggestions', [AnalysisController::class, 'suggestions']);
    });
