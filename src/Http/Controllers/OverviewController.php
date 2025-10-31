<?php

namespace Skylence\TelescopeMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Skylence\TelescopeMcp\Tools\OverviewTool;
use Skylence\TelescopeMcp\Services\PerformanceAnalyzer;
use Skylence\TelescopeMcp\Services\ResponseFormatter;
use Laravel\Telescope\Contracts\EntriesRepository;

class OverviewController extends Controller
{
    protected OverviewTool $overviewTool;
    protected PerformanceAnalyzer $performanceAnalyzer;
    protected ResponseFormatter $formatter;
    protected EntriesRepository $storage;

    public function __construct(
        OverviewTool $overviewTool,
        PerformanceAnalyzer $performanceAnalyzer,
        ResponseFormatter $formatter,
        EntriesRepository $storage
    ) {
        $this->overviewTool = $overviewTool;
        $this->performanceAnalyzer = $performanceAnalyzer;
        $this->formatter = $formatter;
        $this->storage = $storage;
    }
    
    /**
     * Get system dashboard overview.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $period = $request->input('period', '1h');
        
        $result = $this->overviewTool->execute([
            'period' => $period,
            'include_recommendations' => true,
        ]);
        
        return response()->json($result);
    }
    
    /**
     * Get system health status.
     */
    public function health(Request $request): JsonResponse
    {
        $requests = $this->storage->get('request', ['limit' => 100])->toArray();
        $exceptions = $this->storage->get('exception', ['limit' => 50])->toArray();
        $jobs = $this->storage->get('job', ['limit' => 50])->toArray();
        
        $healthStatus = $this->calculateHealthMetrics($requests, $exceptions, $jobs);
        
        return response()->json($this->formatter->format($healthStatus, 'summary'));
    }
    
    /**
     * Get performance overview.
     */
    public function performance(Request $request): JsonResponse
    {
        $period = $request->input('period', '1h');
        $requests = $this->storage->get('request', ['limit' => 200])->toArray();
        $queries = $this->storage->get('query', ['limit' => 200])->toArray();
        
        $performance = $this->performanceAnalyzer->analyzeRequests($requests);
        $bottlenecks = $this->performanceAnalyzer->identifyBottlenecks($requests, $queries);
        
        $result = [
            'performance_summary' => $performance['summary'],
            'slow_endpoints' => array_slice($performance['slow_requests'], 0, 5),
            'bottlenecks' => $bottlenecks,
            'time_distribution' => $performance['time_distribution'],
            'recommendations' => $performance['recommendations'],
        ];
        
        return response()->json($this->formatter->format($result, 'standard'));
    }
    
    /**
     * Get detected problems.
     */
    public function problems(Request $request): JsonResponse
    {
        $severity = $request->input('severity', 'all');
        
        $problems = $this->detectProblems();
        
        if ($severity !== 'all') {
            $problems = array_filter($problems, fn($p) => $p['severity'] === $severity);
        }
        
        return response()->json($this->formatter->format([
            'problems' => array_values($problems),
            'total' => count($problems),
            'by_severity' => $this->groupBySeverity($problems),
        ], 'standard'));
    }
    
    /**
     * Calculate health metrics.
     */
    protected function calculateHealthMetrics(array $requests, array $exceptions, array $jobs): array
    {
        $totalRequests = count($requests);
        $errorRequests = count(array_filter($requests, fn($r) => ($r['content']['response_status'] ?? 0) >= 400));
        $errorRate = $totalRequests > 0 ? ($errorRequests / $totalRequests) * 100 : 0;
        
        $failedJobs = count(array_filter($jobs, fn($j) => ($j['content']['status'] ?? '') === 'failed'));
        $jobFailureRate = count($jobs) > 0 ? ($failedJobs / count($jobs)) * 100 : 0;
        
        $avgResponseTime = $this->calculateAverageResponseTime($requests);
        
        $score = 100;
        if ($errorRate > 5) $score -= 30;
        elseif ($errorRate > 1) $score -= 10;
        
        if (count($exceptions) > 10) $score -= 20;
        elseif (count($exceptions) > 5) $score -= 10;
        
        if ($avgResponseTime > 1000) $score -= 20;
        elseif ($avgResponseTime > 500) $score -= 10;
        
        if ($jobFailureRate > 10) $score -= 10;
        
        return [
            'health_score' => max(0, $score),
            'status' => $this->getHealthStatus($score),
            'metrics' => [
                'error_rate' => round($errorRate, 2),
                'exception_count' => count($exceptions),
                'avg_response_time' => round($avgResponseTime, 2),
                'failed_jobs' => $failedJobs,
                'job_failure_rate' => round($jobFailureRate, 2),
            ],
            'checks' => [
                'api_health' => $errorRate < 5 ? 'passing' : 'failing',
                'exception_rate' => count($exceptions) < 10 ? 'passing' : 'failing',
                'performance' => $avgResponseTime < 1000 ? 'passing' : 'failing',
                'job_processing' => $jobFailureRate < 10 ? 'passing' : 'failing',
            ],
        ];
    }
    
    /**
     * Detect system problems.
     */
    protected function detectProblems(): array
    {
        $problems = [];
        
        // Get recent data
        $requests = $this->storage->get('request', ['limit' => 100])->toArray();
        $queries = $this->storage->get('query', ['limit' => 100])->toArray();
        $exceptions = $this->storage->get('exception', ['limit' => 50])->toArray();
        $jobs = $this->storage->get('job', ['limit' => 50])->toArray();
        
        // Check for high error rate
        $errorRate = $this->calculateErrorRate($requests);
        if ($errorRate > 5) {
            $problems[] = [
                'type' => 'high_error_rate',
                'severity' => 'critical',
                'message' => "Error rate is {$errorRate}%",
                'affected_count' => count(array_filter($requests, fn($r) => ($r['content']['response_status'] ?? 0) >= 400)),
                'recommendation' => 'Investigate error logs and fix root causes',
            ];
        }
        
        // Check for slow queries
        $slowQueries = array_filter($queries, fn($q) => ($q['content']['time'] ?? 0) > 100);
        if (count($slowQueries) > 10) {
            $problems[] = [
                'type' => 'slow_queries',
                'severity' => 'high',
                'message' => count($slowQueries) . ' slow queries detected',
                'affected_count' => count($slowQueries),
                'recommendation' => 'Optimize queries and add indexes',
            ];
        }
        
        // Check for memory issues
        $highMemory = array_filter($requests, fn($r) => ($r['content']['memory'] ?? 0) > 50);
        if (count($highMemory) > 5) {
            $problems[] = [
                'type' => 'high_memory_usage',
                'severity' => 'medium',
                'message' => 'High memory usage in ' . count($highMemory) . ' requests',
                'affected_count' => count($highMemory),
                'recommendation' => 'Review memory-intensive operations',
            ];
        }
        
        // Check for repeated exceptions
        $exceptionGroups = [];
        foreach ($exceptions as $exception) {
            $class = $exception['content']['class'] ?? 'Unknown';
            $exceptionGroups[$class] = ($exceptionGroups[$class] ?? 0) + 1;
        }
        
        foreach ($exceptionGroups as $class => $count) {
            if ($count > 5) {
                $problems[] = [
                    'type' => 'repeated_exception',
                    'severity' => 'high',
                    'message' => "{$class} thrown {$count} times",
                    'affected_count' => $count,
                    'recommendation' => 'Fix the root cause of this exception',
                ];
            }
        }
        
        // Check for failed jobs
        $failedJobs = array_filter($jobs, fn($j) => ($j['content']['status'] ?? '') === 'failed');
        if (count($failedJobs) > 5) {
            $problems[] = [
                'type' => 'failed_jobs',
                'severity' => 'medium',
                'message' => count($failedJobs) . ' jobs have failed',
                'affected_count' => count($failedJobs),
                'recommendation' => 'Review job failures and retry logic',
            ];
        }
        
        return $problems;
    }
    
    /**
     * Helper methods.
     */
    
    protected function calculateAverageResponseTime(array $requests): float
    {
        if (empty($requests)) return 0;
        
        $times = array_map(fn($r) => $r['content']['duration'] ?? 0, $requests);
        return array_sum($times) / count($times);
    }
    
    protected function calculateErrorRate(array $requests): float
    {
        if (empty($requests)) return 0;
        
        $errors = count(array_filter($requests, fn($r) => ($r['content']['response_status'] ?? 0) >= 400));
        return round(($errors / count($requests)) * 100, 2);
    }
    
    protected function getHealthStatus(int $score): string
    {
        if ($score >= 90) return 'healthy';
        if ($score >= 70) return 'good';
        if ($score >= 50) return 'warning';
        return 'critical';
    }
    
    protected function groupBySeverity(array $problems): array
    {
        $grouped = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];
        
        foreach ($problems as $problem) {
            $severity = $problem['severity'] ?? 'low';
            $grouped[$severity] = ($grouped[$severity] ?? 0) + 1;
        }
        
        return $grouped;
    }
}
