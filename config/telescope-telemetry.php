<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telescope Telemetry MCP Configuration
    |--------------------------------------------------------------------------
    |
    | This file configures the token-optimized Laravel Telescope MCP
    | integration for AI assistants.
    |
    */

    'mcp' => [
        'enabled' => env('TELESCOPE_TELEMETRY_ENABLED', true),
        'path' => env('TELESCOPE_TELEMETRY_PATH', 'telescope-telemetry'),
        
        /*
        |--------------------------------------------------------------------------
        | Token Optimization Settings
        |--------------------------------------------------------------------------
        |
        | Configure limits and thresholds to optimize token usage for AI clients
        |
        */
        'limits' => [
            'default' => env('TELESCOPE_TELEMETRY_DEFAULT_LIMIT', 10),
            'maximum' => env('TELESCOPE_TELEMETRY_MAX_LIMIT', 25),
            'summary_threshold' => env('TELESCOPE_TELEMETRY_SUMMARY_THRESHOLD', 5),
        ],
        
        /*
        |--------------------------------------------------------------------------
        | Response Optimization
        |--------------------------------------------------------------------------
        |
        | Configure how responses are formatted and optimized
        |
        */
        'response' => [
            'mode' => env('TELESCOPE_TELEMETRY_MODE', 'auto'), // auto, summary, standard, detailed
            'compression' => env('TELESCOPE_TELEMETRY_COMPRESSION', true),
            'streaming' => env('TELESCOPE_TELEMETRY_STREAMING', true),
            'max_size_kb' => env('TELESCOPE_TELEMETRY_MAX_SIZE_KB', 100),
            'field_filtering' => env('TELESCOPE_TELEMETRY_FIELD_FILTERING', true),
        ],
        
        /*
        |--------------------------------------------------------------------------
        | Performance Analysis
        |--------------------------------------------------------------------------
        |
        | Thresholds for performance analysis and detection
        |
        */
        'analysis' => [
            'slow_query_ms' => env('TELESCOPE_TELEMETRY_SLOW_QUERY_MS', 100),
            'n_plus_one_threshold' => env('TELESCOPE_TELEMETRY_N_PLUS_ONE_THRESHOLD', 3),
            'cache_ttl' => env('TELESCOPE_TELEMETRY_CACHE_TTL', 300),
            'slow_request_ms' => env('TELESCOPE_TELEMETRY_SLOW_REQUEST_MS', 1000),
            'high_memory_mb' => env('TELESCOPE_TELEMETRY_HIGH_MEMORY_MB', 50),
        ],
        
        /*
        |--------------------------------------------------------------------------
        | Caching Configuration
        |--------------------------------------------------------------------------
        |
        | Configure caching for frequently accessed data
        |
        */
        'cache' => [
            'enabled' => env('TELESCOPE_TELEMETRY_CACHE_ENABLED', true),
            'driver' => env('TELESCOPE_TELEMETRY_CACHE_DRIVER', 'redis'),
            'prefix' => env('TELESCOPE_TELEMETRY_CACHE_PREFIX', 'telescope_telemetry'),
            'ttl' => [
                'overview' => 60,
                'statistics' => 300,
                'analysis' => 120,
                'list' => 30,
            ],
        ],
        
        /*
        |--------------------------------------------------------------------------
        | Authentication & Authorization
        |--------------------------------------------------------------------------
        |
        | Configure access control for the telemetry endpoints
        |
        */
        'auth' => [
            'enabled' => env('TELESCOPE_TELEMETRY_AUTH_ENABLED', true),
            'middleware' => ['api'],
            'rate_limit' => env('TELESCOPE_TELEMETRY_RATE_LIMIT', '60,1'),
        ],
        
        /*
        |--------------------------------------------------------------------------
        | Progressive Disclosure
        |--------------------------------------------------------------------------
        |
        | Configure how data is progressively disclosed to AI clients
        |
        */
        'progressive' => [
            'enabled' => true,
            'stages' => [
                'summary' => ['count', 'avg', 'min', 'max'],
                'list' => ['id', 'type', 'status', 'duration', 'created_at'],
                'detail' => '*',
            ],
        ],
        
        /*
        |--------------------------------------------------------------------------
        | Aggregation Settings
        |--------------------------------------------------------------------------
        |
        | Configure aggregation and statistics calculation
        |
        */
        'aggregation' => [
            'percentiles' => [50, 95, 99],
            'time_windows' => [
                'recent' => 300,    // 5 minutes
                'short' => 3600,    // 1 hour
                'medium' => 86400,  // 1 day
                'long' => 604800,   // 1 week
            ],
            'trend_detection' => true,
            'anomaly_detection' => true,
        ],
        
        /*
        |--------------------------------------------------------------------------
        | Tools Configuration
        |--------------------------------------------------------------------------
        |
        | Enable/disable specific tools and their features
        |
        */
        'tools' => [
            'requests' => [
                'enabled' => true,
                'include_headers' => false,
                'include_session' => false,
                'include_input' => true,
            ],
            'queries' => [
                'enabled' => true,
                'include_bindings' => false,
                'explain_enabled' => true,
            ],
            'exceptions' => [
                'enabled' => true,
                'stack_trace_limit' => 5,
            ],
            'logs' => [
                'enabled' => true,
                'context_depth' => 2,
            ],
            'jobs' => [
                'enabled' => true,
                'include_payload' => false,
            ],
            'cache' => [
                'enabled' => true,
            ],
            'events' => [
                'enabled' => true,
                'include_listeners' => false,
            ],
            'mail' => [
                'enabled' => true,
                'include_content' => false,
            ],
            'models' => [
                'enabled' => true,
                'include_changes' => true,
            ],
            'redis' => [
                'enabled' => true,
            ],
            'schedule' => [
                'enabled' => true,
            ],
            'notifications' => [
                'enabled' => true,
                'include_data' => false,
            ],
            'gates' => [
                'enabled' => true,
            ],
            'views' => [
                'enabled' => true,
                'include_data' => false,
            ],
            'http_client' => [
                'enabled' => true,
                'include_headers' => false,
                'include_body' => false,
            ],
            'commands' => [
                'enabled' => true,
                'include_arguments' => true,
            ],
            'dumps' => [
                'enabled' => true,
            ],
            'batches' => [
                'enabled' => true,
            ],
        ],
    ],
];
