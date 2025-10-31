<?php

namespace Skylence\TelescopeMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OptimizeResponse
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        
        if ($response instanceof JsonResponse) {
            $this->optimizeJsonResponse($response, $request);
        }
        
        return $response;
    }
    
    /**
     * Optimize JSON response for token efficiency.
     */
    protected function optimizeJsonResponse(JsonResponse $response, Request $request): void
    {
        $config = config('telescope-telemetry.mcp.response');
        $data = $response->getData(true);
        
        // Apply field filtering if requested
        if ($request->has('fields')) {
            $data = $this->filterFields($data, $request->input('fields'));
        }
        
        // Apply compression if enabled
        if ($config['compression'] ?? true) {
            $this->compressResponse($response, $data);
        }
        
        // Check response size and warn if too large
        $sizeKb = strlen(json_encode($data)) / 1024;
        $maxSizeKb = $config['max_size_kb'] ?? 100;
        
        if ($sizeKb > $maxSizeKb) {
            $data['_warning'] = sprintf(
                'Response size (%.2f KB) exceeds recommended limit (%d KB). Consider using pagination or summary mode.',
                $sizeKb,
                $maxSizeKb
            );
        }
        
        // Add metadata about response optimization
        $data['_meta'] = array_merge($data['_meta'] ?? [], [
            'optimized' => true,
            'size_kb' => round($sizeKb, 2),
            'mode' => $this->determineResponseMode($request),
        ]);
        
        $response->setData($data);
    }
    
    /**
     * Filter fields from the response data.
     */
    protected function filterFields(array $data, string $fields): array
    {
        $requestedFields = array_map('trim', explode(',', $fields));
        
        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = array_map(function ($item) use ($requestedFields) {
                return array_intersect_key($item, array_flip($requestedFields));
            }, $data['data']);
        }
        
        return $data;
    }
    
    /**
     * Apply compression to the response.
     */
    protected function compressResponse(JsonResponse $response, array $data): void
    {
        // Remove null values recursively
        $data = $this->removeNullValues($data);
        
        // Remove empty arrays
        $data = $this->removeEmptyArrays($data);
        
        // Truncate long strings if needed
        $data = $this->truncateLongStrings($data);
        
        $response->setData($data);
    }
    
    /**
     * Remove null values recursively from array.
     */
    protected function removeNullValues(array $array): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->removeNullValues($value);
            }
            return $value;
        }, array_filter($array, function ($value) {
            return $value !== null;
        }));
    }
    
    /**
     * Remove empty arrays from response.
     */
    protected function removeEmptyArrays(array $array): array
    {
        return array_filter($array, function ($value) {
            if (is_array($value)) {
                return !empty($value);
            }
            return true;
        });
    }
    
    /**
     * Truncate long strings to save tokens.
     */
    protected function truncateLongStrings(array $array, int $maxLength = 500): array
    {
        return array_map(function ($value) use ($maxLength) {
            if (is_string($value) && strlen($value) > $maxLength) {
                return substr($value, 0, $maxLength) . '... [truncated]';
            }
            if (is_array($value)) {
                return $this->truncateLongStrings($value, $maxLength);
            }
            return $value;
        }, $array);
    }
    
    /**
     * Determine the response mode based on request.
     */
    protected function determineResponseMode(Request $request): string
    {
        if ($request->has('mode')) {
            return $request->input('mode');
        }
        
        $config = config('telescope-telemetry.mcp.response.mode', 'auto');
        
        if ($config === 'auto') {
            // Detect AI client from user agent
            $userAgent = $request->header('User-Agent', '');
            
            if (str_contains($userAgent, 'Claude') || 
                str_contains($userAgent, 'GPT') ||
                str_contains($userAgent, 'MCP')) {
                return 'summary';
            }
        }
        
        return $config;
    }
}
