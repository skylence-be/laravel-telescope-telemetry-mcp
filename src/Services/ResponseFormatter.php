<?php

namespace Skylence\\TelescopeMcp\Services;

class ResponseFormatter
{
    protected array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Format response based on mode.
     */
    public function format(array $data, string $mode = 'auto'): array
    {
        if ($mode === 'auto') {
            $mode = $this->determineMode($data);
        }
        
        return match ($mode) {
            'summary' => $this->formatSummary($data),
            'standard' => $this->formatStandard($data),
            'detailed' => $this->formatDetailed($data),
            default => $this->formatStandard($data),
        };
    }
    
    /**
     * Format summary response (minimal data, counts only).
     */
    public function formatSummary(array $data): array
    {
        return [
            'mode' => 'summary',
            'summary' => [
                'total_count' => $data['total'] ?? count($data),
                'type' => $data['type'] ?? 'unknown',
                'period' => $data['period'] ?? null,
            ],
            'stats' => $data['stats'] ?? $this->extractStats($data),
            '_meta' => [
                'format' => 'summary',
                'optimized_for_ai' => true,
            ],
        ];
    }
    
    /**
     * Format standard response (key fields only).
     */
    public function formatStandard(array $data): array
    {
        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = array_map([$this, 'extractKeyFields'], $data['data']);
        }
        
        return array_merge($data, [
            '_meta' => [
                'format' => 'standard',
                'fields_included' => $this->getStandardFields(),
            ],
        ]);
    }
    
    /**
     * Format detailed response (full data).
     */
    public function formatDetailed(array $data): array
    {
        return array_merge($data, [
            '_meta' => [
                'format' => 'detailed',
                'complete_data' => true,
            ],
        ]);
    }
    
    /**
     * Format list response with specified fields.
     */
    public function formatList(array $entries, array $fields): array
    {
        return array_map(function ($entry) use ($fields) {
            // Convert EntryResult to array if needed
            if (is_object($entry) && method_exists($entry, 'toArray')) {
                $entry = $entry->toArray();
            }

            $formatted = [];

            foreach ($fields as $field) {
                $formatted[$field] = $this->extractField($entry, $field);
            }

            // Always include ID if present
            if (isset($entry['id'])) {
                $formatted['id'] = $entry['id'];
            }

            return $formatted;
        }, $entries);
    }
    
    /**
     * Format detail view of single item.
     */
    public function formatDetail(array $entry): array
    {
        // Convert EntryResult to array if needed
        if (is_object($entry) && method_exists($entry, 'toArray')) {
            $entry = $entry->toArray();
        }

        return [
            'entry' => $entry,
            'formatted' => $this->formatEntry($entry),
            '_meta' => [
                'format' => 'detail',
                'single_entry' => true,
            ],
        ];
    }
    
    /**
     * Format statistics response.
     */
    public function formatStats(array $stats): array
    {
        return [
            'statistics' => $stats,
            'summary' => $this->generateStatsSummary($stats),
            '_meta' => [
                'format' => 'statistics',
                'token_efficient' => true,
            ],
        ];
    }
    
    /**
     * Format error response.
     */
    public function formatError(string $message, int $code = 400, array $details = []): array
    {
        return [
            'error' => true,
            'message' => $message,
            'code' => $code,
            'details' => $details,
            '_meta' => [
                'format' => 'error',
            ],
        ];
    }
    
    /**
     * Format success response.
     */
    public function formatSuccess(string $message, array $data = []): array
    {
        return array_merge([
            'success' => true,
            'message' => $message,
        ], $data, [
            '_meta' => [
                'format' => 'success',
            ],
        ]);
    }
    
    /**
     * Determine the best format mode based on data size.
     */
    protected function determineMode(array $data): string
    {
        $count = isset($data['data']) ? count($data['data']) : count($data);
        
        if ($count > ($this->config['summary_threshold'] ?? 5)) {
            return 'summary';
        }
        
        return 'standard';
    }
    
    /**
     * Extract key fields from an entry.
     */
    protected function extractKeyFields(array $entry): array
    {
        // Convert EntryResult to array if needed
        if (is_object($entry) && method_exists($entry, 'toArray')) {
            $entry = $entry->toArray();
        }

        $standardFields = $this->getStandardFields();
        $result = [];

        foreach ($standardFields as $field) {
            $result[$field] = $this->extractField($entry, $field);
        }

        return $result;
    }
    
    /**
     * Extract a field from an entry using dot notation.
     */
    protected function extractField(array $entry, string $field)
    {
        $keys = explode('.', $field);
        $value = $entry;
        
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        
        return $value;
    }
    
    /**
     * Get standard fields to include.
     */
    protected function getStandardFields(): array
    {
        return [
            'id',
            'type',
            'family_hash',
            'content.uri',
            'content.method',
            'content.controller_action',
            'content.response_status',
            'content.duration',
            'content.memory',
            'created_at',
        ];
    }
    
    /**
     * Extract statistics from data.
     */
    protected function extractStats(array $data): array
    {
        if (isset($data['stats'])) {
            return $data['stats'];
        }
        
        $items = $data['data'] ?? $data;
        
        if (empty($items)) {
            return [
                'count' => 0,
                'avg' => 0,
                'min' => 0,
                'max' => 0,
            ];
        }
        
        $values = array_map(function ($item) {
            return $item['content']['duration'] ?? $item['duration'] ?? 0;
        }, $items);
        
        return [
            'count' => count($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
        ];
    }
    
    /**
     * Generate a summary from statistics.
     */
    protected function generateStatsSummary(array $stats): string
    {
        $parts = [];
        
        if (isset($stats['count'])) {
            $parts[] = "Count: {$stats['count']}";
        }
        
        if (isset($stats['avg'])) {
            $parts[] = sprintf("Avg: %.2fms", $stats['avg']);
        }
        
        if (isset($stats['p95'])) {
            $parts[] = sprintf("P95: %.2fms", $stats['p95']);
        }
        
        return implode(', ', $parts);
    }
    
    /**
     * Format a single entry for display.
     */
    protected function formatEntry(array $entry): array
    {
        // Convert EntryResult to array if needed
        if (is_object($entry) && method_exists($entry, 'toArray')) {
            $entry = $entry->toArray();
        }

        $formatted = [
            'id' => $entry['id'] ?? null,
            'type' => $entry['type'] ?? null,
            'occurred_at' => $entry['created_at'] ?? null,
        ];

        if (isset($entry['content'])) {
            $formatted['details'] = $this->formatContent($entry['content'], $entry['type'] ?? '');
        }

        return $formatted;
    }
    
    /**
     * Format entry content based on type.
     */
    protected function formatContent(array $content, string $type): array
    {
        // Type-specific formatting
        switch ($type) {
            case 'request':
                return $this->formatRequestContent($content);
            case 'query':
                return $this->formatQueryContent($content);
            case 'exception':
                return $this->formatExceptionContent($content);
            default:
                return $content;
        }
    }
    
    /**
     * Format request content.
     */
    protected function formatRequestContent(array $content): array
    {
        return [
            'method' => $content['method'] ?? null,
            'uri' => $content['uri'] ?? null,
            'status' => $content['response_status'] ?? null,
            'duration' => isset($content['duration']) ? "{$content['duration']}ms" : null,
            'memory' => isset($content['memory']) ? "{$content['memory']}MB" : null,
            'controller' => $content['controller_action'] ?? null,
        ];
    }
    
    /**
     * Format query content.
     */
    protected function formatQueryContent(array $content): array
    {
        return [
            'sql' => $content['sql'] ?? null,
            'time' => isset($content['time']) ? "{$content['time']}ms" : null,
            'connection' => $content['connection'] ?? null,
            'slow' => ($content['slow'] ?? false) ? 'Yes' : 'No',
        ];
    }
    
    /**
     * Format exception content.
     */
    protected function formatExceptionContent(array $content): array
    {
        return [
            'class' => $content['class'] ?? null,
            'message' => $content['message'] ?? null,
            'file' => $content['file'] ?? null,
            'line' => $content['line'] ?? null,
            'trace_summary' => isset($content['trace']) ? 
                $this->formatStackTraceSummary($content['trace']) : null,
        ];
    }
    
    /**
     * Format stack trace summary.
     */
    protected function formatStackTraceSummary(array $trace): string
    {
        $summary = array_slice($trace, 0, 3);
        
        return implode(' -> ', array_map(function ($frame) {
            return ($frame['class'] ?? '') . '@' . ($frame['function'] ?? '');
        }, $summary));
    }
}
