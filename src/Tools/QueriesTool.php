<?php

declare(strict_types=1);

namespace Skylence\TelescopeMcp\Tools;

use Skylence\TelescopeMcp\Services\PaginationManager;
use Skylence\TelescopeMcp\Services\QueryAnalyzer;
use Skylence\TelescopeMcp\Services\ResponseFormatter;

final class QueriesTool extends AbstractTool
{
    protected string $entryType = 'query';
    protected QueryAnalyzer $queryAnalyzer;

    public function __construct(
        array $config,
        PaginationManager $pagination,
        ResponseFormatter $formatter
    ) {
        parent::__construct($config, $pagination, $formatter);
        $this->queryAnalyzer = app(QueryAnalyzer::class);
    }

    public function getShortName(): string
    {
        return 'queries';
    }

    public function getSchema(): array
    {
        return [
            'name' => $this->getShortName(),
            'description' => 'Analyze database queries with N+1 detection, duplicate finding, and performance insights',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['summary', 'list', 'detail', 'stats', 'search', 'slow', 'duplicate', 'n_plus_one'],
                        'description' => 'Action to perform',
                        'default' => 'list',
                    ],
                    'period' => [
                        'type' => 'string',
                        'enum' => ['5m', '15m', '1h', '6h', '24h', '7d', '14d', '21d', '30d', '3M', '6M', '12M'],
                        'description' => 'Time period for analysis (overrides config default)',
                        'default' => '1h',
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
                    'slow_threshold' => [
                        'type' => 'integer',
                        'description' => 'Threshold for slow queries in ms',
                    ],
                    'connection' => [
                        'type' => 'string',
                        'description' => 'Filter by database connection',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $arguments = []): array
    {
        $action = $arguments['action'] ?? 'list';

        return match ($action) {
            'slow' => $this->getSlowQueries($arguments),
            'duplicate' => $this->getDuplicateQueries($arguments),
            'n_plus_one' => $this->getNPlusOneQueries($arguments),
            default => parent::execute($arguments),
        };
    }

    /**
     * Get slow queries.
     */
    protected function getSlowQueries(array $arguments): array
    {
        $entries = $this->normalizeEntries($this->getEntries($arguments));
        $slowQueries = $this->queryAnalyzer->identifySlowQueries($entries);

        return $this->formatter->format([
            'slow_queries' => $slowQueries,
            'threshold_ms' => $arguments['slow_threshold'] ?? $this->config['slow_query_ms'] ?? 100,
            'total_found' => count($slowQueries),
            'optimization_tips' => $this->getOptimizationTips($slowQueries),
        ], 'standard');
    }

    /**
     * Get duplicate queries.
     */
    protected function getDuplicateQueries(array $arguments): array
    {
        $entries = $this->normalizeEntries($this->getEntries($arguments));
        $duplicates = $this->queryAnalyzer->findDuplicates($entries);

        return $this->formatter->format([
            'duplicate_queries' => $duplicates,
            'total_duplicates' => count($duplicates),
            'wasted_time_ms' => array_sum(array_column($duplicates, 'wasted_time')),
            'recommendations' => $this->getDuplicateRecommendations($duplicates),
        ], 'standard');
    }

    /**
     * Detect N+1 queries.
     */
    protected function getNPlusOneQueries(array $arguments): array
    {
        $entries = $this->normalizeEntries($this->getEntries($arguments));
        $nPlusOne = $this->queryAnalyzer->detectNPlusOne($entries);

        return $this->formatter->format([
            'n_plus_one_queries' => $nPlusOne,
            'total_patterns' => count($nPlusOne),
            'potential_reduction' => $this->calculatePotentialReduction($nPlusOne),
            'fixes' => $this->generateNPlusOneFixes($nPlusOne),
        ], 'standard');
    }

    /**
     * Override summary to include query-specific insights.
     */
    public function summary(array $arguments = []): array
    {
        $entries = $this->normalizeEntries($this->getEntries($arguments));
        $stats = $this->queryAnalyzer->calculateStats($entries);

        $summary = [
            'total_queries' => $stats['total_queries'],
            'total_time_ms' => round($stats['total_time'], 2),
            'avg_time_ms' => round($stats['avg_time'], 2),
            'slow_queries' => $stats['slow_query_count'],
            'query_types' => $stats['by_type'],
            'issues' => [],
        ];

        // Check for issues
        if ($stats['slow_query_count'] > 0) {
            $summary['issues'][] = [
                'type' => 'slow_queries',
                'severity' => 'warning',
                'count' => $stats['slow_query_count'],
                'message' => "{$stats['slow_query_count']} slow queries detected",
            ];
        }

        $duplicates = $this->queryAnalyzer->findDuplicates($entries);
        if (! empty($duplicates)) {
            $summary['issues'][] = [
                'type' => 'duplicates',
                'severity' => 'info',
                'count' => count($duplicates),
                'message' => count($duplicates).' duplicate query patterns found',
            ];
        }

        $nPlusOne = $this->queryAnalyzer->detectNPlusOne($entries);
        if (! empty($nPlusOne)) {
            $summary['issues'][] = [
                'type' => 'n_plus_one',
                'severity' => 'error',
                'count' => count($nPlusOne),
                'message' => count($nPlusOne).' N+1 query patterns detected',
            ];
        }

        return $this->formatter->formatSummary($summary);
    }

    /**
     * Override stats to include query-specific metrics.
     */
    public function stats(array $arguments = []): array
    {
        $entries = $this->normalizeEntries($this->getEntries($arguments));
        $stats = $this->queryAnalyzer->calculateStats($entries);
        $patterns = $this->queryAnalyzer->analyzePatterns($entries);
        $suggestions = $this->queryAnalyzer->suggestOptimizations($entries);

        return $this->formatter->formatStats([
            'metrics' => $stats,
            'patterns' => $patterns,
            'optimizations' => $suggestions,
            'performance_score' => $this->calculateQueryPerformanceScore($stats),
        ]);
    }

    /**
     * Get fields to include in list view.
     */
    protected function getListFields(): array
    {
        return [
            'id',
            'content.sql',
            'content.time',
            'content.slow',
            'content.connection',
            'content.bindings',
            'created_at',
        ];
    }

    /**
     * Get searchable fields.
     */
    protected function getSearchableFields(): array
    {
        return [
            'sql',
            'connection',
        ];
    }

    /**
     * Get optimization tips for slow queries.
     */
    protected function getOptimizationTips(array $slowQueries): array
    {
        $tips = [];

        foreach ($slowQueries as $query) {
            if (stripos($query['sql'], 'select *') !== false) {
                $tips[] = 'Avoid SELECT *, specify only needed columns';
            }
            if (! stripos($query['sql'], 'limit') && stripos($query['sql'], 'select') === 0) {
                $tips[] = 'Consider adding LIMIT clauses to SELECT queries';
            }
            if (stripos($query['sql'], 'join') !== false) {
                $tips[] = 'Review JOIN conditions and ensure proper indexes exist';
            }
        }

        return array_unique($tips);
    }

    /**
     * Get recommendations for duplicate queries.
     */
    protected function getDuplicateRecommendations(array $duplicates): array
    {
        $recommendations = [];

        foreach ($duplicates as $duplicate) {
            if ($duplicate['count'] > 10) {
                $recommendations[] = [
                    'type' => 'caching',
                    'message' => 'Cache frequently repeated queries',
                    'sql_pattern' => $duplicate['sql'],
                ];
            } elseif ($duplicate['count'] > 5) {
                $recommendations[] = [
                    'type' => 'refactor',
                    'message' => 'Store query result in variable',
                    'sql_pattern' => $duplicate['sql'],
                ];
            }
        }

        return array_slice($recommendations, 0, 5);
    }

    /**
     * Calculate potential query reduction from N+1 fixes.
     */
    protected function calculatePotentialReduction(array $nPlusOne): array
    {
        $totalQueries = array_sum(array_column($nPlusOne, 'count'));
        $afterFix = count($nPlusOne); // Each pattern would become 1 query

        return [
            'current_queries' => $totalQueries,
            'after_fix' => $afterFix,
            'reduction' => $totalQueries - $afterFix,
            'reduction_percentage' => round((($totalQueries - $afterFix) / $totalQueries) * 100, 2),
        ];
    }

    /**
     * Generate N+1 fixes.
     */
    protected function generateNPlusOneFixes(array $nPlusOne): array
    {
        $fixes = [];

        foreach ($nPlusOne as $pattern) {
            $table = $pattern['table'] ?? 'related';
            $fixes[] = [
                'pattern' => $pattern['pattern'],
                'solution' => "Use eager loading: ->with('{$table}')",
                'example' => "Model::with('{$table}')->get();",
            ];
        }

        return $fixes;
    }

    /**
     * Calculate query performance score.
     */
    protected function calculateQueryPerformanceScore(array $stats): int
    {
        $score = 100;

        // Deduct for slow queries
        if ($stats['slow_query_count'] > 10) {
            $score -= 30;
        } elseif ($stats['slow_query_count'] > 5) {
            $score -= 15;
        } elseif ($stats['slow_query_count'] > 0) {
            $score -= 5;
        }

        // Deduct for high average time
        if ($stats['avg_time'] > 100) {
            $score -= 20;
        } elseif ($stats['avg_time'] > 50) {
            $score -= 10;
        } elseif ($stats['avg_time'] > 20) {
            $score -= 5;
        }

        // Deduct for high total time
        if ($stats['total_time'] > 5000) {
            $score -= 20;
        } elseif ($stats['total_time'] > 2000) {
            $score -= 10;
        }

        return max(0, $score);
    }
}
