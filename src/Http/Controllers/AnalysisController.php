<?php

namespace Skylence\TelescopeMcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Skylence\TelescopeMcp\Services\PerformanceAnalyzer;
use Skylence\TelescopeMcp\Services\QueryAnalyzer;
use Skylence\TelescopeMcp\Services\ResponseFormatter;
use Skylence\TelescopeMcp\Services\AggregationService;
use Laravel\Telescope\Contracts\EntriesRepository;

class AnalysisController extends Controller
{
    protected PerformanceAnalyzer $performanceAnalyzer;
    protected QueryAnalyzer $queryAnalyzer;
    protected ResponseFormatter $formatter;
    protected AggregationService $aggregation;
    protected EntriesRepository $storage;

    public function __construct(
        PerformanceAnalyzer $performanceAnalyzer,
        QueryAnalyzer $queryAnalyzer,
        ResponseFormatter $formatter,
        AggregationService $aggregation,
        EntriesRepository $storage
    ) {
        $this->performanceAnalyzer = $performanceAnalyzer;
        $this->queryAnalyzer = $queryAnalyzer;
        $this->formatter = $formatter;
        $this->aggregation = $aggregation;
        $this->storage = $storage;
    }
    
    /**
     * Analyze slow queries.
     */
    public function slowQueries(Request $request): JsonResponse
    {
        $threshold = $request->input('threshold', 100);
        $limit = $request->input('limit', 10);
        
        $queries = $this->storage->get('query', ['limit' => 200])->toArray();
        $slowQueries = $this->queryAnalyzer->identifySlowQueries($queries);
        
        $result = [
            'slow_queries' => array_slice($slowQueries, 0, $limit),
            'total_found' => count($slowQueries),
            'threshold_ms' => $threshold,
            'optimizations' => $this->queryAnalyzer->suggestOptimizations($queries),
            'impact' => $this->calculateSlowQueryImpact($slowQueries),
        ];
        
        return response()->json($this->formatter->format($result, 'standard'));
    }
    
    /**
     * Detect N+1 queries.
     */
    public function nPlusOne(Request $request): JsonResponse
    {
        $queries = $this->storage->get('query', ['limit' => 500])->toArray();
        $nPlusOne = $this->queryAnalyzer->detectNPlusOne($queries);
        
        $result = [
            'n_plus_one_patterns' => $nPlusOne,
            'total_patterns' => count($nPlusOne),
            'potential_reduction' => $this->calculateQueryReduction($nPlusOne),
            'fixes' => array_map(function ($pattern) {
                return [
                    'pattern' => $pattern['pattern'],
                    'solution' => "Use eager loading with ->with('{$pattern['table']}')",
                    'impact' => "{$pattern['count']} queries → 1 query",
                ];
            }, array_slice($nPlusOne, 0, 5)),
        ];
        
        return response()->json($this->formatter->format($result, 'standard'));
    }
    
    /**
     * Identify system bottlenecks.
     */
    public function bottlenecks(Request $request): JsonResponse
    {
        $requests = $this->storage->get('request', ['limit' => 200])->toArray();
        $queries = $this->storage->get('query', ['limit' => 200])->toArray();
        
        $bottlenecks = $this->performanceAnalyzer->identifyBottlenecks($requests, $queries);
        
        $result = [
            'bottlenecks' => $bottlenecks,
            'summary' => $this->summarizeBottlenecks($bottlenecks),
            'recommendations' => $this->prioritizeBottleneckFixes($bottlenecks),
        ];
        
        return response()->json($this->formatter->format($result, 'standard'));
    }
    
    /**
     * Analyze performance trends.
     */
    public function trends(Request $request): JsonResponse
    {
        $period = $request->input('period', '24h');
        $metric = $request->input('metric', 'duration');
        
        $requests = $this->storage->get('request', ['limit' => 500])->toArray();
        
        // Sort by time
        usort($requests, fn($a, $b) => strtotime($a['created_at']) <=> strtotime($b['created_at']));
        
        // Calculate trend
        $trend = $this->aggregation->calculateTrend($requests, 'created_at', "content.{$metric}");
        
        // Time window aggregations
        $timeWindows = $this->aggregation->aggregateByTimeWindow($requests, 'created_at');
        
        // Anomalies
        $anomalies = $this->aggregation->detectAnomalies($requests, "content.{$metric}");
        
        $result = [
            'trend' => $trend,
            'time_windows' => $timeWindows,
            'anomalies' => array_slice($anomalies, 0, 5),
            'histogram' => $this->aggregation->createHistogram($requests, "content.{$metric}", 10),
        ];
        
        return response()->json($this->formatter->format($result, 'standard'));
    }
    
    /**
     * Generate optimization suggestions.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $requests = $this->storage->get('request', ['limit' => 200])->toArray();
        $queries = $this->storage->get('query', ['limit' => 200])->toArray();
        $exceptions = $this->storage->get('exception', ['limit' => 100])->toArray();
        $cache = $this->storage->get('cache', ['limit' => 100])->toArray();
        
        $suggestions = $this->generateSuggestions($requests, $queries, $exceptions, $cache);
        
        $result = [
            'suggestions' => $suggestions,
            'total' => count($suggestions),
            'by_category' => $this->groupSuggestionsByCategory($suggestions),
            'estimated_impact' => $this->estimateImpact($suggestions),
        ];
        
        return response()->json($this->formatter->format($result, 'standard'));
    }
    
    /**
     * Helper methods.
     */
    
    protected function calculateSlowQueryImpact(array $slowQueries): array
    {
        $totalTime = array_sum(array_column($slowQueries, 'time'));
        $count = count($slowQueries);
        
        return [
            'total_time_ms' => round($totalTime, 2),
            'query_count' => $count,
            'potential_savings' => round($totalTime * 0.7, 2), // Assume 70% improvement possible
        ];
    }
    
    protected function calculateQueryReduction(array $nPlusOne): array
    {
        $currentQueries = array_sum(array_column($nPlusOne, 'count'));
        $optimizedQueries = count($nPlusOne);
        
        return [
            'current' => $currentQueries,
            'optimized' => $optimizedQueries,
            'reduction' => $currentQueries - $optimizedQueries,
            'percentage' => round((($currentQueries - $optimizedQueries) / $currentQueries) * 100, 2),
        ];
    }
    
    protected function summarizeBottlenecks(array $bottlenecks): array
    {
        $summary = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];
        
        foreach ($bottlenecks as $bottleneck) {
            $severity = $bottleneck['severity'] ?? 'low';
            $summary[$severity] = ($summary[$severity] ?? 0) + 1;
        }
        
        return $summary;
    }
    
    protected function prioritizeBottleneckFixes(array $bottlenecks): array
    {
        usort($bottlenecks, function ($a, $b) {
            $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            return $severityOrder[$a['severity']] <=> $severityOrder[$b['severity']];
        });
        
        return array_map(function ($bottleneck) {
            return [
                'type' => $bottleneck['type'],
                'severity' => $bottleneck['severity'],
                'action' => $bottleneck['recommendation'],
            ];
        }, array_slice($bottlenecks, 0, 5));
    }
    
    protected function generateSuggestions(array $requests, array $queries, array $exceptions, array $cache): array
    {
        $suggestions = [];
        
        // Performance suggestions
        $requestAnalysis = $this->performanceAnalyzer->analyzeRequests($requests);
        foreach ($requestAnalysis['recommendations'] ?? [] as $rec) {
            $suggestions[] = [
                'category' => 'performance',
                'priority' => $rec['priority'],
                'message' => $rec['message'],
                'action' => $rec['suggestion'],
            ];
        }
        
        // Query suggestions
        $queryOptimizations = $this->queryAnalyzer->suggestOptimizations($queries);
        foreach ($queryOptimizations as $opt) {
            $suggestions[] = [
                'category' => 'database',
                'priority' => $opt['priority'],
                'message' => $opt['type'],
                'action' => $opt['recommendation'],
            ];
        }
        
        // Cache suggestions
        $cacheHitRate = $this->calculateCacheHitRate($cache);
        if ($cacheHitRate < 50) {
            $suggestions[] = [
                'category' => 'caching',
                'priority' => 'medium',
                'message' => "Low cache hit rate ({$cacheHitRate}%)",
                'action' => 'Review caching strategy and add more cache layers',
            ];
        }
        
        // Exception suggestions
        if (count($exceptions) > 20) {
            $suggestions[] = [
                'category' => 'stability',
                'priority' => 'high',
                'message' => 'High exception rate detected',
                'action' => 'Implement better error handling and validation',
            ];
        }
        
        return $suggestions;
    }
    
    protected function groupSuggestionsByCategory(array $suggestions): array
    {
        $grouped = [];
        
        foreach ($suggestions as $suggestion) {
            $category = $suggestion['category'];
            $grouped[$category] = ($grouped[$category] ?? 0) + 1;
        }
        
        return $grouped;
    }
    
    protected function estimateImpact(array $suggestions): array
    {
        $impact = [
            'performance_improvement' => '20-40%',
            'error_reduction' => '50-70%',
            'cost_savings' => '15-25%',
        ];
        
        $highPriority = count(array_filter($suggestions, fn($s) => $s['priority'] === 'high'));
        
        if ($highPriority > 3) {
            $impact['overall'] = 'Significant improvements possible';
        } elseif ($highPriority > 0) {
            $impact['overall'] = 'Moderate improvements available';
        } else {
            $impact['overall'] = 'System is well optimized';
        }
        
        return $impact;
    }
    
    protected function calculateCacheHitRate(array $cacheEntries): float
    {
        if (empty($cacheEntries)) return 0;
        
        $hits = count(array_filter($cacheEntries, fn($c) => ($c['content']['type'] ?? '') === 'hit'));
        
        return round(($hits / count($cacheEntries)) * 100, 2);
    }
}
