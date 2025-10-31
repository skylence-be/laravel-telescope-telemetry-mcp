<?php

namespace Skylence\TelescopeMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthenticateMcp
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!config('telescope-telemetry.mcp.auth.enabled', true)) {
            return $next($request);
        }
        
        // Check for API token in header
        $token = $request->header('X-MCP-Token') ?? $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32001,
                    'message' => 'Authentication required',
                ],
                'id' => $request->input('id'),
            ], 401);
        }
        
        // Validate token (customize this based on your authentication needs)
        if (!$this->isValidToken($token)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32002,
                    'message' => 'Invalid authentication token',
                ],
                'id' => $request->input('id'),
            ], 403);
        }
        
        return $next($request);
    }
    
    /**
     * Validate the authentication token.
     */
    protected function isValidToken(string $token): bool
    {
        // In production, validate against database or external service
        // For now, check against environment variable
        $validToken = env('TELESCOPE_TELEMETRY_API_TOKEN');
        
        if (!$validToken) {
            // If no token is configured, allow access (development mode)
            return true;
        }
        
        return hash_equals($validToken, $token);
    }
}
