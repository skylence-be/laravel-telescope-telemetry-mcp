<?php

namespace Skylence\\TelescopeMcp\Services;

class PerformanceAnalyzer
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Normalize entry to array format.
     */
    protected function normalizeEntry($entry): array
    {
        if (is_array($entry)) {
            return $entry;
        }

        // Handle EntryResult objects from Telescope
        if (is_object($entry)) {
            $content = isset($entry->content) && is_array($entry->content) ? $entry->content : [];
            $id = isset($entry->id) ? $entry->id : null;

            return [
                'id' => $id,
                'content' => $content,
            ];
        }

        return ['id' => null, 'content' => []];
    }

    /**
     * Normalize entries array.
     */
    protected function normalizeEntries(array $entries): array
    {
        return array_map(fn($e) => $this->normalizeEntry($e), $entries);
    }

    /**
     * Analyze performance of requests.
     */
    public function analyzeRequests(array $requests): array
    {
        $requests = $this->normalizeEntries($requests);
        if (empty($requests)) {
            return $this->emptyAnalysis();
        }
        
        $analysis = [
            'summary' => $this->summarizePerformance($requests),
            'slow_requests' => $this->identifySlowRequests($requests),
            'endpoints' => $this->analyzeEndpoints($requests),
            'status_breakdown' => $this->analyzeStatusCodes($requests),
            'time_distribution' => $this->calculateTimeDistribution($requests),
            'memory_usage' => $this->analyzeMemoryUsage($requests),
            'recommendations' => $this->generateRecommendations($requests),
        ];
        
        return $analysis;
    }
    
    /**
     * Analyze queries for performance issues.
     */
    public function analyzeQueries(array $queries): array
    {
        $queries = $this->normalizeEntries($queries);
        if (empty($queries)) {
            return $this->emptyAnalysis();
        }
        
        return [
            'summary' => $this->summarizeQueryPerformance($queries),
            'slow_queries' => $this->identifySlowQueries($queries),
            'duplicates' => $this->findDuplicateQueries($queries),
            'n_plus_one' => $this->detectNPlusOneQueries($queries),
            'by_connection' => $this->groupByConnection($queries),
            'recommendations' => $this->generateQueryRecommendations($queries),
        ];
    }
    
    /**
     * Identify bottlenecks in the system.
     */
    public function identifyBottlenecks(array $requests, array $queries): array
    {
        $requests = $this->normalizeEntries($requests);
        $queries = $this->normalizeEntries($queries);
        $bottlenecks = [];
        
        // Database bottlenecks
        $dbTime = $this->calculateDatabaseTime($queries);
        $requestTime = $this->calculateTotalRequestTime($requests);

        if ($requestTime > 0 && $dbTime > $requestTime * 0.5) {
            $bottlenecks[] = [
                'type' => 'database',
                'severity' => 'high',
                'percentage' => round(($dbTime / $requestTime) * 100, 2),
                'message' => 'Database queries consuming over 50% of request time',
                'recommendation' => 'Optimize slow queries, add indexes, or implement caching',
            ];
        }
        
        // Memory bottlenecks
        $highMemoryRequests = array_filter($requests, function ($req) {
            return ($req['content']['memory'] ?? 0) > $this->config['high_memory_mb'];
        });
        
        if (count($highMemoryRequests) > count($requests) * 0.1) {
            $bottlenecks[] = [
                'type' => 'memory',
                'severity' => 'medium',
                'count' => count($highMemoryRequests),
                'message' => 'High memory usage detected in multiple requests',
                'recommendation' => 'Review memory-intensive operations and optimize data handling',
            ];
        }
        
        // Endpoint-specific bottlenecks
        $endpointStats = $this->analyzeEndpoints($requests);
        foreach ($endpointStats as $endpoint => $stats) {
            if ($stats['avg_duration'] > $this->config['slow_request_ms']) {
                $bottlenecks[] = [
                    'type' => 'endpoint',
                    'severity' => 'medium',
                    'endpoint' => $endpoint,
                    'avg_duration' => $stats['avg_duration'],
                    'message' => "Endpoint {$endpoint} is consistently slow",
                    'recommendation' => 'Profile and optimize this specific endpoint',
                ];
            }
        }
        
        return $bottlenecks;
    }
    
    /**
     * Calculate performance trends over time.
     */
    public function calculateTrends(array $currentData, array $historicalData): array
    {
        $trends = [
            'direction' => 'stable',
            'change_percentage' => 0,
            'details' => [],
        ];
        
        if (empty($historicalData)) {
            return $trends;
        }
        
        $currentAvg = $this->calculateAverageDuration($currentData);
        $historicalAvg = $this->calculateAverageDuration($historicalData);

        $change = $historicalAvg > 0 ? (($currentAvg - $historicalAvg) / $historicalAvg) * 100 : 0;

        $trends['change_percentage'] = round($change, 2);
        $trends['direction'] = $change > 10 ? 'degrading' : ($change < -10 ? 'improving' : 'stable');
        
        // Calculate percentile trends
        $trends['details']['p50'] = [
            'current' => $this->calculatePercentile($currentData, 50),
            'historical' => $this->calculatePercentile($historicalData, 50),
        ];
        
        $trends['details']['p95'] = [
            'current' => $this->calculatePercentile($currentData, 95),
            'historical' => $this->calculatePercentile($historicalData, 95),
        ];
        
        return $trends;
    }
    
    /**
     * Detect anomalies in performance data.
     */
    public function detectAnomalies(array $data): array
    {
        if (count($data) < 10) {
            return [];
        }
        
        $durations = array_map(fn($item) => $item['content']['duration'] ?? 0, $data);
        $mean = array_sum($durations) / count($durations);
        $stdDev = $this->calculateStdDev($durations, $mean);

        if ($stdDev == 0) {
            return [];
        }

        $anomalies = [];
        $threshold = $mean + (2 * $stdDev); // 2 standard deviations

        foreach ($data as $item) {
            $duration = $item['content']['duration'] ?? 0;
            if ($duration > $threshold) {
                $anomalies[] = [
                    'id' => $item['id'],
                    'duration' => $duration,
                    'deviation' => round(($duration - $mean) / $stdDev, 2),
                    'endpoint' => $item['content']['uri'] ?? 'unknown',
                    'timestamp' => $item['created_at'],
                ];
            }
        }
        
        return $anomalies;
    }
    
    /**
     * Generate performance score.
     */
    public function calculatePerformanceScore(array $metrics): int
    {
        $score = 100;
        
        // Deduct points for slow average response time
        if (isset($metrics['avg_duration'])) {
            if ($metrics['avg_duration'] > 1000) $score -= 20;
            elseif ($metrics['avg_duration'] > 500) $score -= 10;
            elseif ($metrics['avg_duration'] > 200) $score -= 5;
        }
        
        // Deduct points for high error rate
        if (isset($metrics['error_rate'])) {
            if ($metrics['error_rate'] > 0.1) $score -= 30;
            elseif ($metrics['error_rate'] > 0.05) $score -= 15;
            elseif ($metrics['error_rate'] > 0.01) $score -= 5;
        }
        
        // Deduct points for memory issues
        if (isset($metrics['high_memory_percentage'])) {
            if ($metrics['high_memory_percentage'] > 0.2) $score -= 10;
            elseif ($metrics['high_memory_percentage'] > 0.1) $score -= 5;
        }
        
        return max(0, $score);
    }
    
    /**
     * Summarize performance metrics.
     */
    protected function summarizePerformance(array $requests): array
    {
        $durations = array_map(fn($r) => $r['content']['duration'] ?? 0, $requests);
        
        return [
            'total_requests' => count($requests),
            'avg_duration' => array_sum($durations) / count($durations),
            'min_duration' => min($durations),
            'max_duration' => max($durations),
            'p50' => $this->calculatePercentile($requests, 50),
            'p95' => $this->calculatePercentile($requests, 95),
            'p99' => $this->calculatePercentile($requests, 99),
        ];
    }
    
    /**
     * Identify slow requests.
     */
    protected function identifySlowRequests(array $requests): array
    {
        $threshold = $this->config['slow_request_ms'] ?? 1000;
        
        $slowRequests = array_filter($requests, function ($request) use ($threshold) {
            return ($request['content']['duration'] ?? 0) > $threshold;
        });
        
        return array_map(function ($request) {
            return [
                'id' => $request['id'],
                'uri' => $request['content']['uri'] ?? '',
                'method' => $request['content']['method'] ?? '',
                'duration' => $request['content']['duration'] ?? 0,
                'controller' => $request['content']['controller_action'] ?? '',
            ];
        }, array_slice($slowRequests, 0, 10));
    }
    
    /**
     * Analyze endpoints performance.
     */
    protected function analyzeEndpoints(array $requests): array
    {
        $endpoints = [];
        
        foreach ($requests as $request) {
            $endpoint = $request['content']['controller_action'] ?? 'unknown';
            
            if (!isset($endpoints[$endpoint])) {
                $endpoints[$endpoint] = [
                    'count' => 0,
                    'total_duration' => 0,
                    'durations' => [],
                ];
            }
            
            $endpoints[$endpoint]['count']++;
            $endpoints[$endpoint]['total_duration'] += $request['content']['duration'] ?? 0;
            $endpoints[$endpoint]['durations'][] = $request['content']['duration'] ?? 0;
        }
        
        foreach ($endpoints as $endpoint => &$stats) {
            $stats['avg_duration'] = $stats['count'] > 0 ? $stats['total_duration'] / $stats['count'] : 0;
            $stats['p95'] = $this->percentile($stats['durations'], 95);
            unset($stats['durations']); // Remove raw data to save tokens
        }
        
        return $endpoints;
    }
    
    /**
     * Analyze status codes distribution.
     */
    protected function analyzeStatusCodes(array $requests): array
    {
        $statuses = [];
        
        foreach ($requests as $request) {
            $status = $request['content']['response_status'] ?? 'unknown';
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }
        
        return $statuses;
    }
    
    /**
     * Calculate time distribution.
     */
    protected function calculateTimeDistribution(array $requests): array
    {
        $buckets = [
            '0-100ms' => 0,
            '100-500ms' => 0,
            '500-1000ms' => 0,
            '1000-5000ms' => 0,
            '5000ms+' => 0,
        ];
        
        foreach ($requests as $request) {
            $duration = $request['content']['duration'] ?? 0;
            
            if ($duration < 100) $buckets['0-100ms']++;
            elseif ($duration < 500) $buckets['100-500ms']++;
            elseif ($duration < 1000) $buckets['500-1000ms']++;
            elseif ($duration < 5000) $buckets['1000-5000ms']++;
            else $buckets['5000ms+']++;
        }
        
        return $buckets;
    }
    
    /**
     * Analyze memory usage.
     */
    protected function analyzeMemoryUsage(array $requests): array
    {
        $memories = array_map(fn($r) => $r['content']['memory'] ?? 0, $requests);
        
        return [
            'avg_memory' => array_sum($memories) / count($memories),
            'min_memory' => min($memories),
            'max_memory' => max($memories),
            'high_memory_count' => count(array_filter($memories, fn($m) => $m > $this->config['high_memory_mb'])),
        ];
    }
    
    /**
     * Generate performance recommendations.
     */
    protected function generateRecommendations(array $requests): array
    {
        $recommendations = [];
        $summary = $this->summarizePerformance($requests);
        
        if ($summary['avg_duration'] > 1000) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'high',
                'message' => 'Average response time is high',
                'suggestion' => 'Consider implementing caching, optimizing database queries, or adding indexes',
            ];
        }
        
        if ($summary['p95'] > 3000) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'medium',
                'message' => '95th percentile response time exceeds 3 seconds',
                'suggestion' => 'Investigate and optimize slowest endpoints',
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Helper methods
     */
    
    protected function calculateAverageDuration(array $data): float
    {
        if (empty($data)) return 0;
        
        $durations = array_map(fn($item) => $item['content']['duration'] ?? 0, $data);
        return array_sum($durations) / count($durations);
    }
    
    protected function calculatePercentile(array $data, int $percentile): float
    {
        if (empty($data)) return 0;
        
        $values = array_map(fn($item) => $item['content']['duration'] ?? 0, $data);
        sort($values);
        
        $index = ceil(($percentile / 100) * count($values)) - 1;
        return $values[$index] ?? 0;
    }
    
    protected function percentile(array $values, int $percentile): float
    {
        if (empty($values)) return 0;
        
        sort($values);
        $index = ceil(($percentile / 100) * count($values)) - 1;
        return $values[$index] ?? 0;
    }
    
    protected function calculateStdDev(array $values, float $mean): float
    {
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / count($values);
        return sqrt($variance);
    }
    
    protected function calculateDatabaseTime(array $queries): float
    {
        return array_sum(array_map(fn($q) => $q['content']['time'] ?? 0, $queries));
    }
    
    protected function calculateTotalRequestTime(array $requests): float
    {
        return array_sum(array_map(fn($r) => $r['content']['duration'] ?? 0, $requests));
    }
    
    protected function summarizeQueryPerformance(array $queries): array
    {
        $times = array_map(fn($q) => $q['content']['time'] ?? 0, $queries);
        
        return [
            'total_queries' => count($queries),
            'total_time' => array_sum($times),
            'avg_time' => array_sum($times) / count($times),
            'slow_queries' => count(array_filter($times, fn($t) => $t > $this->config['slow_query_ms'])),
        ];
    }
    
    protected function identifySlowQueries(array $queries): array
    {
        $threshold = $this->config['slow_query_ms'] ?? 100;
        
        $slowQueries = array_filter($queries, function ($query) use ($threshold) {
            return ($query['content']['time'] ?? 0) > $threshold;
        });
        
        return array_slice($slowQueries, 0, 10);
    }
    
    protected function findDuplicateQueries(array $queries): array
    {
        $sqlCounts = [];
        
        foreach ($queries as $query) {
            $sql = $query['content']['sql'] ?? '';
            $sqlCounts[$sql] = ($sqlCounts[$sql] ?? 0) + 1;
        }
        
        $duplicates = array_filter($sqlCounts, fn($count) => $count > 1);
        arsort($duplicates);
        
        return array_slice($duplicates, 0, 10, true);
    }
    
    protected function detectNPlusOneQueries(array $queries): array
    {
        $patterns = [];
        $threshold = $this->config['n_plus_one_threshold'] ?? 3;
        
        foreach ($queries as $query) {
            $sql = preg_replace('/\d+/', 'N', $query['content']['sql'] ?? '');
            $patterns[$sql] = ($patterns[$sql] ?? 0) + 1;
        }
        
        $nPlusOne = array_filter($patterns, fn($count) => $count >= $threshold);
        
        return array_map(function ($sql, $count) {
            return [
                'pattern' => $sql,
                'count' => $count,
                'likely_n_plus_one' => true,
            ];
        }, array_keys($nPlusOne), $nPlusOne);
    }
    
    protected function groupByConnection(array $queries): array
    {
        $connections = [];
        
        foreach ($queries as $query) {
            $connection = $query['content']['connection'] ?? 'default';
            $connections[$connection] = ($connections[$connection] ?? 0) + 1;
        }
        
        return $connections;
    }
    
    protected function generateQueryRecommendations(array $queries): array
    {
        $recommendations = [];
        $duplicates = $this->findDuplicateQueries($queries);
        $nPlusOne = $this->detectNPlusOneQueries($queries);
        
        if (!empty($duplicates)) {
            $recommendations[] = [
                'type' => 'duplicate_queries',
                'priority' => 'medium',
                'message' => 'Duplicate queries detected',
                'suggestion' => 'Consider using eager loading or caching for repeated queries',
            ];
        }
        
        if (!empty($nPlusOne)) {
            $recommendations[] = [
                'type' => 'n_plus_one',
                'priority' => 'high',
                'message' => 'N+1 query patterns detected',
                'suggestion' => 'Use eager loading with with() or load() methods',
            ];
        }
        
        return $recommendations;
    }
    
    protected function emptyAnalysis(): array
    {
        return [
            'summary' => ['message' => 'No data available for analysis'],
            'recommendations' => [],
        ];
    }
}
