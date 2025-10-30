<?php

namespace LaravelTelescope\Telemetry\Tools;

use Laravel\Telescope\Contracts\EntriesRepository;
use LaravelTelescope\Telemetry\Services\PerformanceAnalyzer;
use LaravelTelescope\Telemetry\Services\QueryAnalyzer;

class OverviewTool extends AbstractTool
{
    protected string $entryType = '';
    protected PerformanceAnalyzer $performanceAnalyzer;
    protected QueryAnalyzer $queryAnalyzer;
    
    public function __construct(
        array $config,
        \LaravelTelescope\Telemetry\Services\PaginationManager $pagination,
        \LaravelTelescope\Telemetry\Services\ResponseFormatter $formatter,
        \LaravelTelescope\Telemetry\Services\CacheManager $cache
    ) {
        parent::__construct($config, $pagination, $formatter, $cache);
        $this->performanceAnalyzer = app(PerformanceAnalyzer::class);
        $this->queryAnalyzer = app(QueryAnalyzer::class);
    }
    
    public function getName(): string
    {
        return 'telescope.overview';
    }
    
    public function getDescription(): string
    {
        return 'Get a comprehensive system overview with health status, performance metrics, and critical issues in under 2K tokens';
    }
    
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'enum' => ['5m', '1h', '24h', '7d'],
                    'description' => 'Time period for analysis',
                    'default' => '1h',
                ],
                'include_recommendations' => [
                    'type' => 'boolean',
                    'description' => 'Include optimization recommendations',
                    'default' => true,
                ],
            ],
        ];
    }
    
    public function execute(array $arguments = []): array
    {
        $cacheKey = $this->getCacheKey('overview', $arguments);
        
        return $this->cache->remember($cacheKey, function () use ($arguments) {
            $period = $arguments['period'] ?? '1h';
            $includeRecommendations = $arguments['include_recommendations'] ?? true;
            
            // Gather data from different entry types
            $requests = $this->storage->get('request', ['limit' => 100])->toArray();
            $queries = $this->storage->get('query', ['limit' => 100])->toArray();
            $exceptions = $this->storage->get('exception', ['limit' => 50])->toArray();
            $jobs = $this->storage->get('job', ['limit' => 50])->toArray();
            $cache = $this->storage->get('cache', ['limit' => 50])->toArray();
            
            // Analyze performance
            $requestAnalysis = $this->performanceAnalyzer->analyzeRequests($requests);
            $queryAnalysis = $this->queryAnalyzer->calculateStats($queries);
            $bottlenecks = $this->performanceAnalyzer->identifyBottlenecks($requests, $queries);
            
            // Build overview
            $overview = [
                'health_status' => $this->calculateHealthStatus($requestAnalysis, $queryAnalysis, $exceptions),
                'performance_metrics' => $this->getPerformanceMetrics($requestAnalysis, $queryAnalysis),
                'critical_issues' => $this->identifyCriticalIssues($requestAnalysis, $queryAnalysis, $exceptions, $bottlenecks),
                'system_stats' => $this->getSystemStats($requests, $queries, $exceptions, $jobs, $cache),
                'recent_errors' => $this->getRecentErrors($exceptions),
            ];
            
            if ($includeRecommendations) {
                $overview['recommendations'] = $this->generateRecommendations(
                    $requestAnalysis,
                    $queryAnalysis,
                    $bottlenecks,
                    $exceptions
                );
            }
            
            return $this->formatter->format($overview, 'summary');
        }, $this->cache->getTtl('overview'));
    }
    
    /**
     * Calculate overall health status.
     */
    protected function calculateHealthStatus(array $requestAnalysis, array $queryAnalysis, array $exceptions): array
    {
        $score = 100;
        $issues = [];
        
        // Check request performance
        if (($requestAnalysis['summary']['avg_duration'] ?? 0) > 1000) {
            $score -= 20;
            $issues[] = 'High average response time';
        }
        
        // Check error rate
        $errorRate = $this->calculateErrorRate($requestAnalysis);
        if ($errorRate > 5) {
            $score -= 30;
            $issues[] = 'High error rate (' . round($errorRate, 1) . '%)';
        } elseif ($errorRate > 1) {
            $score -= 10;
            $issues[] = 'Elevated error rate';
        }
        
        // Check database performance
        if (($queryAnalysis['slow_query_count'] ?? 0) > 10) {
            $score -= 15;
            $issues[] = 'Multiple slow queries detected';
        }
        
        // Check exceptions
        if (count($exceptions) > 10) {
            $score -= 15;
            $issues[] = 'High exception rate';
        }
        
        return [
            'score' => max(0, $score),
            'status' => $this->getStatusLabel($score),
            'issues' => $issues,
        ];
    }
    
    /**
     * Get performance metrics summary.
     */
    protected function getPerformanceMetrics(array $requestAnalysis, array $queryAnalysis): array
    {
        return [
            'requests' => [
                'avg_response_time' => round($requestAnalysis['summary']['avg_duration'] ?? 0, 2),
                'p95_response_time' => round($requestAnalysis['summary']['p95'] ?? 0, 2),
                'throughput' => $this->calculateThroughput($requestAnalysis),
            ],
            'database' => [
                'avg_query_time' => round($queryAnalysis['avg_time'] ?? 0, 2),
                'total_queries' => $queryAnalysis['total_queries'] ?? 0,
                'slow_queries' => $queryAnalysis['slow_query_count'] ?? 0,
            ],
            'resources' => [
                'avg_memory_mb' => round($requestAnalysis['memory_usage']['avg_memory'] ?? 0, 2),
                'peak_memory_mb' => round($requestAnalysis['memory_usage']['max_memory'] ?? 0, 2),
            ],
        ];
    }
    
    /**
     * Identify critical issues.
     */
    protected function identifyCriticalIssues(
        array $requestAnalysis,
        array $queryAnalysis,
        array $exceptions,
        array $bottlenecks
    ): array {
        $issues = [];
        
        // Check for bottlenecks
        foreach ($bottlenecks as $bottleneck) {
            if ($bottleneck['severity'] === 'high') {
                $issues[] = [
                    'type' => $bottleneck['type'],
                    'severity' => 'critical',
                    'message' => $bottleneck['message'],
                    'action' => $bottleneck['recommendation'],
                ];
            }
        }
        
        // Check for N+1 queries
        $nPlusOne = $this->queryAnalyzer->detectNPlusOne($this->storage->get('query', ['limit' => 100])->toArray());
        if (!empty($nPlusOne)) {
            $issues[] = [
                'type' => 'n_plus_one',
                'severity' => 'high',
                'message' => count($nPlusOne) . ' N+1 query patterns detected',
                'action' => 'Use eager loading to reduce database queries',
            ];
        }
        
        // Check for repeated exceptions
        $exceptionGroups = $this->groupExceptions($exceptions);
        foreach ($exceptionGroups as $class => $count) {
            if ($count > 5) {
                $issues[] = [
                    'type' => 'repeated_exception',
                    'severity' => 'high',
                    'message' => "{$class} thrown {$count} times",
                    'action' => 'Investigate and fix the root cause',
                ];
            }
        }
        
        // Check for slow endpoints
        foreach ($requestAnalysis['endpoints'] ?? [] as $endpoint => $stats) {
            if ($stats['avg_duration'] > 2000) {
                $issues[] = [
                    'type' => 'slow_endpoint',
                    'severity' => 'medium',
                    'message' => "Endpoint {$endpoint} averaging {$stats['avg_duration']}ms",
                    'action' => 'Optimize or add caching to this endpoint',
                ];
            }
        }
        
        return array_slice($issues, 0, 5); // Limit to top 5 issues
    }
    
    /**
     * Get system statistics.
     */
    protected function getSystemStats(
        array $requests,
        array $queries,
        array $exceptions,
        array $jobs,
        array $cache
    ): array {
        return [
            'requests_count' => count($requests),
            'queries_count' => count($queries),
            'exceptions_count' => count($exceptions),
            'jobs_processed' => count($jobs),
            'cache_operations' => count($cache),
            'cache_hit_rate' => $this->calculateCacheHitRate($cache),
            'failed_jobs' => count(array_filter($jobs, fn($j) => ($j['content']['status'] ?? '') === 'failed')),
        ];
    }
    
    /**
     * Get recent errors summary.
     */
    protected function getRecentErrors(array $exceptions): array
    {
        $recentErrors = array_slice($exceptions, 0, 3);
        
        return array_map(function ($exception) {
            return [
                'class' => $exception['content']['class'] ?? 'Unknown',
                'message' => substr($exception['content']['message'] ?? '', 0, 100),
                'location' => $exception['content']['file'] ?? '' . ':' . $exception['content']['line'] ?? '',
                'occurred_at' => $exception['created_at'] ?? '',
            ];
        }, $recentErrors);
    }
    
    /**
     * Generate optimization recommendations.
     */
    protected function generateRecommendations(
        array $requestAnalysis,
        array $queryAnalysis,
        array $bottlenecks,
        array $exceptions
    ): array {
        $recommendations = [];
        
        // Performance recommendations
        if (($requestAnalysis['summary']['avg_duration'] ?? 0) > 500) {
            $recommendations[] = [
                'category' => 'performance',
                'priority' => 'high',
                'action' => 'Implement response caching for frequently accessed endpoints',
            ];
        }
        
        // Database recommendations
        if (($queryAnalysis['slow_query_count'] ?? 0) > 5) {
            $recommendations[] = [
                'category' => 'database',
                'priority' => 'high',
                'action' => 'Add indexes to optimize slow queries',
            ];
        }
        
        // Error handling recommendations
        if (count($exceptions) > 10) {
            $recommendations[] = [
                'category' => 'stability',
                'priority' => 'critical',
                'action' => 'Review and fix recurring exceptions',
            ];
        }
        
        // Bottleneck recommendations
        foreach ($bottlenecks as $bottleneck) {
            if ($bottleneck['severity'] === 'high') {
                $recommendations[] = [
                    'category' => 'bottleneck',
                    'priority' => $bottleneck['severity'],
                    'action' => $bottleneck['recommendation'],
                ];
            }
        }
        
        return array_slice($recommendations, 0, 5);
    }
    
    /**
     * Helper methods
     */
    
    protected function calculateErrorRate(array $requestAnalysis): float
    {
        $total = $requestAnalysis['summary']['total_requests'] ?? 0;
        if ($total === 0) return 0;
        
        $errors = 0;
        foreach ($requestAnalysis['status_breakdown'] ?? [] as $status => $count) {
            if ($status >= 400) {
                $errors += $count;
            }
        }
        
        return ($errors / $total) * 100;
    }
    
    protected function getStatusLabel(int $score): string
    {
        if ($score >= 90) return 'healthy';
        if ($score >= 70) return 'good';
        if ($score >= 50) return 'warning';
        return 'critical';
    }
    
    protected function calculateThroughput(array $requestAnalysis): string
    {
        $total = $requestAnalysis['summary']['total_requests'] ?? 0;
        // Assuming data is from last hour
        return round($total / 60, 2) . ' req/min';
    }
    
    protected function groupExceptions(array $exceptions): array
    {
        $groups = [];
        
        foreach ($exceptions as $exception) {
            $class = $exception['content']['class'] ?? 'Unknown';
            $groups[$class] = ($groups[$class] ?? 0) + 1;
        }
        
        return $groups;
    }
    
    protected function calculateCacheHitRate(array $cacheEntries): string
    {
        if (empty($cacheEntries)) return '0%';
        
        $hits = count(array_filter($cacheEntries, fn($c) => ($c['content']['type'] ?? '') === 'hit'));
        $total = count($cacheEntries);
        
        if ($total === 0) return '0%';
        
        return round(($hits / $total) * 100, 1) . '%';
    }
    
    protected function getListFields(): array
    {
        return [];
    }
    
    protected function getSearchableFields(): array
    {
        return [];
    }
}
