<?php

namespace LaravelTelescope\Telemetry\Tools;

class RequestsTool extends AbstractTool
{
    protected string $entryType = 'request';
    
    public function getName(): string
    {
        return 'telescope.requests';
    }
    
    public function getDescription(): string
    {
        return 'Analyze HTTP requests handled by your application with token-optimized responses';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['summary', 'list', 'detail', 'stats', 'search', 'slow'],
                    'description' => 'Action to perform',
                    'default' => 'list',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Number of entries to return (max 25)',
                    'default' => 10,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Offset for pagination',
                    'default' => 0,
                ],
                'id' => [
                    'type' => 'string',
                    'description' => 'Entry ID for detail view',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query',
                ],
                'status' => [
                    'type' => 'integer',
                    'description' => 'Filter by HTTP status code',
                ],
                'method' => [
                    'type' => 'string',
                    'description' => 'Filter by HTTP method',
                ],
                'min_duration' => [
                    'type' => 'integer',
                    'description' => 'Minimum duration in ms',
                ],
            ],
        ];
    }
    
    public function execute(array $arguments = []): array
    {
        $action = $arguments['action'] ?? 'list';
        
        return match ($action) {
            'slow' => $this->getSlowRequests($arguments),
            default => parent::execute($arguments),
        };
    }
    
    /**
     * Get slow requests.
     */
    protected function getSlowRequests(array $arguments): array
    {
        $threshold = $arguments['min_duration'] ?? $this->config['slow_request_ms'] ?? 1000;
        $limit = $this->pagination->getLimit($arguments['limit'] ?? 10);
        
        $entries = $this->getEntries($arguments);
        
        $slowRequests = array_filter($entries, function ($entry) use ($threshold) {
            return ($entry['content']['duration'] ?? 0) >= $threshold;
        });
        
        usort($slowRequests, function ($a, $b) {
            return $b['content']['duration'] <=> $a['content']['duration'];
        });
        
        $slowRequests = array_slice($slowRequests, 0, $limit);
        
        return $this->formatter->format([
            'slow_requests' => array_map(function ($request) {
                return [
                    'id' => $request['id'],
                    'method' => $request['content']['method'] ?? '',
                    'uri' => $request['content']['uri'] ?? '',
                    'status' => $request['content']['response_status'] ?? 0,
                    'duration' => $request['content']['duration'] ?? 0,
                    'controller' => $request['content']['controller_action'] ?? '',
                    'memory' => $request['content']['memory'] ?? 0,
                    'created_at' => $request['created_at'] ?? '',
                ];
            }, $slowRequests),
            'threshold_ms' => $threshold,
            'total_slow' => count($slowRequests),
        ], 'standard');
    }
    
    /**
     * Get fields to include in list view.
     */
    protected function getListFields(): array
    {
        return [
            'id',
            'content.method',
            'content.uri',
            'content.controller_action',
            'content.response_status',
            'content.duration',
            'content.memory',
            'content.user.id',
            'created_at',
        ];
    }
    
    /**
     * Get searchable fields.
     */
    protected function getSearchableFields(): array
    {
        return [
            'uri',
            'controller_action',
            'method',
            'ip_address',
        ];
    }
    
    /**
     * Override stats to include request-specific metrics.
     */
    public function stats(array $arguments = []): array
    {
        $entries = $this->getEntries($arguments);
        
        if (empty($entries)) {
            return $this->formatter->formatStats([]);
        }
        
        $durations = array_map(fn($e) => $e['content']['duration'] ?? 0, $entries);
        $memories = array_map(fn($e) => $e['content']['memory'] ?? 0, $entries);
        $statuses = array_map(fn($e) => $e['content']['response_status'] ?? 0, $entries);
        
        $statusCounts = array_count_values($statuses);
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($statusCounts as $status => $count) {
            if ($status >= 200 && $status < 400) {
                $successCount += $count;
            } elseif ($status >= 400) {
                $errorCount += $count;
            }
        }
        
        return $this->formatter->formatStats([
            'total_requests' => count($entries),
            'duration' => [
                'avg' => array_sum($durations) / count($durations),
                'min' => min($durations),
                'max' => max($durations),
                'p50' => $this->percentile($durations, 50),
                'p95' => $this->percentile($durations, 95),
                'p99' => $this->percentile($durations, 99),
            ],
            'memory' => [
                'avg' => array_sum($memories) / count($memories),
                'min' => min($memories),
                'max' => max($memories),
            ],
            'status' => [
                'success' => $successCount,
                'error' => $errorCount,
                'error_rate' => round(($errorCount / count($entries)) * 100, 2) . '%',
                'breakdown' => $statusCounts,
            ],
            'methods' => $this->getMethodBreakdown($entries),
            'endpoints' => $this->getTopEndpoints($entries, 5),
        ]);
    }
    
    /**
     * Get method breakdown.
     */
    protected function getMethodBreakdown(array $entries): array
    {
        $methods = [];
        
        foreach ($entries as $entry) {
            $method = $entry['content']['method'] ?? 'UNKNOWN';
            $methods[$method] = ($methods[$method] ?? 0) + 1;
        }
        
        return $methods;
    }
    
    /**
     * Get top endpoints by request count.
     */
    protected function getTopEndpoints(array $entries, int $limit = 5): array
    {
        $endpoints = [];
        
        foreach ($entries as $entry) {
            $endpoint = $entry['content']['controller_action'] ?? $entry['content']['uri'] ?? 'unknown';
            
            if (!isset($endpoints[$endpoint])) {
                $endpoints[$endpoint] = [
                    'count' => 0,
                    'avg_duration' => 0,
                    'durations' => [],
                ];
            }
            
            $endpoints[$endpoint]['count']++;
            $endpoints[$endpoint]['durations'][] = $entry['content']['duration'] ?? 0;
        }
        
        foreach ($endpoints as $endpoint => &$data) {
            $data['avg_duration'] = array_sum($data['durations']) / count($data['durations']);
            unset($data['durations']); // Remove raw data
        }
        
        uasort($endpoints, fn($a, $b) => $b['count'] <=> $a['count']);
        
        return array_slice($endpoints, 0, $limit, true);
    }
}
