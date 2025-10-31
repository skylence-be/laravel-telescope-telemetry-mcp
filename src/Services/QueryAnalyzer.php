<?php

namespace Skylence\TelescopeMcp\Services;

class QueryAnalyzer
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Normalize query to array format.
     */
    protected function normalizeQuery($query): array
    {
        if (is_array($query)) {
            return $query;
        }

        // Handle EntryResult objects from Telescope
        if (is_object($query)) {
            $content = isset($query->content) && is_array($query->content) ? $query->content : [];
            $id = isset($query->id) ? $query->id : null;

            return [
                'id' => $id,
                'content' => $content,
            ];
        }

        return ['id' => null, 'content' => []];
    }

    /**
     * Normalize queries array.
     */
    protected function normalizeQueries(array $queries): array
    {
        return array_map(fn($q) => $this->normalizeQuery($q), $queries);
    }
    
    /**
     * Analyze queries for N+1 problems.
     */
    public function detectNPlusOne(array $queries): array
    {
        $queries = $this->normalizeQueries($queries);
        $patterns = $this->groupByPattern($queries);
        $threshold = $this->config['n_plus_one_threshold'] ?? 3;
        
        $nPlusOneQueries = [];
        
        foreach ($patterns as $pattern => $group) {
            if (count($group) >= $threshold) {
                $nPlusOneQueries[] = [
                    'pattern' => $pattern,
                    'count' => count($group),
                    'table' => $this->extractTable($pattern),
                    'type' => $this->determineQueryType($pattern),
                    'examples' => array_slice($group, 0, 3),
                    'recommendation' => $this->generateNPlusOneRecommendation($pattern, count($group)),
                ];
            }
        }
        
        return $nPlusOneQueries;
    }
    
    /**
     * Find duplicate queries.
     */
    public function findDuplicates(array $queries): array
    {
        $queries = $this->normalizeQueries($queries);
        $duplicates = [];
        $seen = [];
        
        foreach ($queries as $query) {
            $sql = $this->normalizeSql($query['content']['sql'] ?? '');
            $hash = md5($sql);
            
            if (!isset($seen[$hash])) {
                $seen[$hash] = [
                    'sql' => $sql,
                    'count' => 0,
                    'total_time' => 0,
                    'occurrences' => [],
                ];
            }
            
            $seen[$hash]['count']++;
            $seen[$hash]['total_time'] += $query['content']['time'] ?? 0;
            $seen[$hash]['occurrences'][] = [
                'id' => $query['id'],
                'time' => $query['content']['time'] ?? 0,
                'connection' => $query['content']['connection'] ?? 'default',
            ];
        }
        
        foreach ($seen as $hash => $data) {
            if ($data['count'] > 1) {
                $duplicates[] = [
                    'sql' => $data['sql'],
                    'count' => $data['count'],
                    'total_time' => $data['total_time'],
                    'avg_time' => $data['total_time'] / $data['count'],
                    'wasted_time' => $data['total_time'] - ($data['total_time'] / $data['count']),
                    'recommendation' => $this->generateDuplicateRecommendation($data),
                ];
            }
        }
        
        usort($duplicates, fn($a, $b) => $b['wasted_time'] <=> $a['wasted_time']);
        
        return array_slice($duplicates, 0, 10);
    }
    
    /**
     * Identify slow queries.
     */
    public function identifySlowQueries(array $queries): array
    {
        $queries = $this->normalizeQueries($queries);
        $threshold = $this->config['slow_query_ms'] ?? 100;
        
        $slowQueries = array_filter($queries, function ($query) use ($threshold) {
            return ($query['content']['time'] ?? 0) > $threshold;
        });
        
        usort($slowQueries, fn($a, $b) => $b['content']['time'] <=> $a['content']['time']);
        
        return array_map(function ($query) {
            return [
                'id' => $query['id'],
                'sql' => $this->truncateSql($query['content']['sql'] ?? ''),
                'time' => $query['content']['time'] ?? 0,
                'connection' => $query['content']['connection'] ?? 'default',
                'table' => $this->extractTable($query['content']['sql'] ?? ''),
                'type' => $this->determineQueryType($query['content']['sql'] ?? ''),
                'recommendation' => $this->generateSlowQueryRecommendation($query),
            ];
        }, array_slice($slowQueries, 0, 10));
    }
    
    /**
     * Analyze query patterns.
     */
    public function analyzePatterns(array $queries): array
    {
        $patterns = [
            'select' => 0,
            'insert' => 0,
            'update' => 0,
            'delete' => 0,
            'joins' => 0,
            'subqueries' => 0,
            'aggregates' => 0,
        ];
        
        foreach ($queries as $query) {
            $sql = strtolower($query['content']['sql'] ?? '');
            
            if (str_starts_with($sql, 'select')) $patterns['select']++;
            elseif (str_starts_with($sql, 'insert')) $patterns['insert']++;
            elseif (str_starts_with($sql, 'update')) $patterns['update']++;
            elseif (str_starts_with($sql, 'delete')) $patterns['delete']++;
            
            if (str_contains($sql, 'join')) $patterns['joins']++;
            if (preg_match('/\(select/i', $sql)) $patterns['subqueries']++;
            if (preg_match('/(count|sum|avg|max|min)\(/i', $sql)) $patterns['aggregates']++;
        }
        
        return $patterns;
    }
    
    /**
     * Suggest query optimizations.
     */
    public function suggestOptimizations(array $queries): array
    {
        $suggestions = [];
        
        // Check for missing indexes
        $missingIndexes = $this->detectMissingIndexes($queries);
        if (!empty($missingIndexes)) {
            $suggestions[] = [
                'type' => 'missing_indexes',
                'priority' => 'high',
                'tables' => $missingIndexes,
                'recommendation' => 'Consider adding indexes to frequently queried columns',
            ];
        }
        
        // Check for full table scans
        $fullScans = $this->detectFullTableScans($queries);
        if (!empty($fullScans)) {
            $suggestions[] = [
                'type' => 'full_table_scans',
                'priority' => 'high',
                'queries' => $fullScans,
                'recommendation' => 'Add WHERE clauses or indexes to avoid full table scans',
            ];
        }
        
        // Check for SELECT *
        $selectAll = $this->detectSelectAll($queries);
        if (!empty($selectAll)) {
            $suggestions[] = [
                'type' => 'select_all',
                'priority' => 'medium',
                'count' => count($selectAll),
                'recommendation' => 'Specify only needed columns instead of SELECT *',
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Calculate query statistics.
     */
    public function calculateStats(array $queries): array
    {
        $queries = $this->normalizeQueries($queries);
        if (empty($queries)) {
            return $this->emptyStats();
        }
        
        $times = array_map(fn($q) => $q['content']['time'] ?? 0, $queries);
        
        return [
            'total_queries' => count($queries),
            'total_time' => array_sum($times),
            'avg_time' => array_sum($times) / count($times),
            'min_time' => min($times),
            'max_time' => max($times),
            'p50' => $this->percentile($times, 50),
            'p95' => $this->percentile($times, 95),
            'p99' => $this->percentile($times, 99),
            'slow_query_count' => count(array_filter($times, fn($t) => $t > $this->config['slow_query_ms'])),
            'by_type' => $this->analyzePatterns($queries),
        ];
    }
    
    /**
     * Group queries by pattern.
     */
    protected function groupByPattern(array $queries): array
    {
        $patterns = [];
        
        foreach ($queries as $query) {
            $pattern = $this->extractPattern($query['content']['sql'] ?? '');
            
            if (!isset($patterns[$pattern])) {
                $patterns[$pattern] = [];
            }
            
            $patterns[$pattern][] = $query;
        }
        
        return $patterns;
    }
    
    /**
     * Extract pattern from SQL by replacing values with placeholders.
     */
    protected function extractPattern(string $sql): string
    {
        // Remove quotes and their content
        $sql = preg_replace("/'[^']*'/", '?', $sql);
        $sql = preg_replace('/"[^"]*"/', '?', $sql);
        
        // Replace numbers with placeholders
        $sql = preg_replace('/\b\d+\b/', '?', $sql);
        
        // Replace IN clauses
        $sql = preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', $sql);
        
        return trim($sql);
    }
    
    /**
     * Normalize SQL for comparison.
     */
    protected function normalizeSql(string $sql): string
    {
        // Remove extra whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);
        
        // Remove comments
        $sql = preg_replace('/--.*$/', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//', '', $sql);
        
        return trim($sql);
    }
    
    /**
     * Extract table name from SQL.
     */
    protected function extractTable(string $sql): string
    {
        $sql = strtolower($sql);
        
        if (preg_match('/from\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/update\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/insert\s+into\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        
        return 'unknown';
    }
    
    /**
     * Determine query type.
     */
    protected function determineQueryType(string $sql): string
    {
        $sql = strtolower(trim($sql));
        
        if (str_starts_with($sql, 'select')) return 'SELECT';
        if (str_starts_with($sql, 'insert')) return 'INSERT';
        if (str_starts_with($sql, 'update')) return 'UPDATE';
        if (str_starts_with($sql, 'delete')) return 'DELETE';
        
        return 'OTHER';
    }
    
    /**
     * Truncate SQL for display.
     */
    protected function truncateSql(string $sql, int $maxLength = 200): string
    {
        if (strlen($sql) <= $maxLength) {
            return $sql;
        }
        
        return substr($sql, 0, $maxLength) . '...';
    }
    
    /**
     * Generate N+1 recommendation.
     */
    protected function generateNPlusOneRecommendation(string $pattern, int $count): string
    {
        $table = $this->extractTable($pattern);
        
        return "Use eager loading with ->with('{$table}') or ->load('{$table}') to reduce {$count} queries to 1";
    }
    
    /**
     * Generate duplicate query recommendation.
     */
    protected function generateDuplicateRecommendation(array $data): string
    {
        if ($data['count'] > 10) {
            return 'Consider caching this frequently executed query';
        }
        
        if ($data['count'] > 5) {
            return 'Store result in a variable to avoid repeated execution';
        }
        
        return 'Review code to eliminate duplicate query execution';
    }
    
    /**
     * Generate slow query recommendation.
     */
    protected function generateSlowQueryRecommendation(array $query): string
    {
        $time = $query['content']['time'] ?? 0;
        
        if ($time > 1000) {
            return 'Critical: Add indexes or refactor this query immediately';
        }
        
        if ($time > 500) {
            return 'High priority: Optimize with indexes or query restructuring';
        }
        
        return 'Consider adding an index or optimizing the query structure';
    }
    
    /**
     * Detect missing indexes.
     */
    protected function detectMissingIndexes(array $queries): array
    {
        $tables = [];
        
        foreach ($queries as $query) {
            $sql = $query['content']['sql'] ?? '';
            $time = $query['content']['time'] ?? 0;
            
            if ($time > $this->config['slow_query_ms'] && str_contains(strtolower($sql), 'where')) {
                $table = $this->extractTable($sql);
                if ($table !== 'unknown') {
                    $tables[$table] = ($tables[$table] ?? 0) + 1;
                }
            }
        }
        
        return array_keys(array_filter($tables, fn($count) => $count > 3));
    }
    
    /**
     * Detect full table scans.
     */
    protected function detectFullTableScans(array $queries): array
    {
        $fullScans = [];
        
        foreach ($queries as $query) {
            $sql = strtolower($query['content']['sql'] ?? '');
            
            if (str_starts_with($sql, 'select') && 
                !str_contains($sql, 'where') && 
                !str_contains($sql, 'limit')) {
                $fullScans[] = $this->truncateSql($query['content']['sql'] ?? '');
            }
        }
        
        return array_slice($fullScans, 0, 5);
    }
    
    /**
     * Detect SELECT * queries.
     */
    protected function detectSelectAll(array $queries): array
    {
        return array_filter($queries, function ($query) {
            $sql = $query['content']['sql'] ?? '';
            return preg_match('/select\s+\*/i', $sql);
        });
    }
    
    /**
     * Calculate percentile.
     */
    protected function percentile(array $values, int $percentile): float
    {
        if (empty($values)) return 0;
        
        sort($values);
        $index = ceil(($percentile / 100) * count($values)) - 1;
        
        return $values[$index] ?? 0;
    }
    
    /**
     * Return empty stats structure.
     */
    protected function emptyStats(): array
    {
        return [
            'total_queries' => 0,
            'total_time' => 0,
            'avg_time' => 0,
            'min_time' => 0,
            'max_time' => 0,
            'p50' => 0,
            'p95' => 0,
            'p99' => 0,
            'slow_query_count' => 0,
            'by_type' => [],
        ];
    }
}
