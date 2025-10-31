<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Skylence\TelescopeMcp\Http\Controllers\McpController;

// Specific route for MCP tools/call (must come before generic route)
Route::post('tools/call', [McpController::class, 'executeToolCall']);

// Base route for MCP protocol
Route::post('/', [McpController::class, 'handle']);

// Alternative routes for direct access
Route::get('/manifest.json', [McpController::class, 'manifest']);
Route::post('/manifest.json', [McpController::class, 'manifest']);

// Route for executing specific tools
Route::post('/tools/{tool}', [McpController::class, 'executeTool'])
    ->where('tool', '[a-zA-Z0-9_]+'); // Prevents conflict with tools/call

// Catch-all route for debugging
Route::any('{any}', function () {
    if (config('telescope-telemetry.mcp.logging.enabled', false)) {
        \Illuminate\Support\Facades\Log::channel(config('telescope-telemetry.mcp.logging.channel', 'stack'))
            ->info('MCP Route not found', [
                'method' => request()->method(),
                'path' => request()->path(),
                'input' => request()->all(),
            ]);
    }

    return response()->json(['error' => 'Route not found'], 404);
})->where('any', '.*');
