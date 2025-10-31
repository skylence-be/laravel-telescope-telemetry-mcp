<?php

namespace Skylence\TelescopeMcp\Services;

class PaginationManager
{
    protected array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Get the limit for pagination.
     */
    public function getLimit(?int $requestedLimit = null): int
    {
        $default = $this->config['default'] ?? 10;
        $maximum = $this->config['maximum'] ?? 25;
        
        if ($requestedLimit === null) {
            return $default;
        }
        
        return min($requestedLimit, $maximum);
    }
    
    /**
     * Paginate data with metadata.
     */
    public function paginate(array $data, int $total, int $limit, int $offset = 0): array
    {
        $hasMore = ($offset + $limit) < $total;
        $nextCursor = $hasMore ? $this->encodeCursor($offset + $limit) : null;
        $prevCursor = $offset > 0 ? $this->encodeCursor(max(0, $offset - $limit)) : null;
        
        return [
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
                'prev_cursor' => $prevCursor,
                'current_page' => (int) floor($offset / $limit) + 1,
                'total_pages' => (int) ceil($total / $limit),
            ],
            '_meta' => [
                'token_optimized' => true,
                'response_size' => $this->calculateResponseSize($data),
            ],
        ];
    }
    
    /**
     * Create paginated response with cursor-based pagination.
     */
    public function cursorPaginate(array $data, ?string $cursor = null, int $limit = 10): array
    {
        $decodedCursor = $cursor ? $this->decodeCursor($cursor) : null;
        $hasMore = count($data) > $limit;
        
        if ($hasMore) {
            $data = array_slice($data, 0, $limit);
        }
        
        $lastItem = end($data);
        $nextCursor = $hasMore && $lastItem ? $this->encodeCursor($lastItem['id'] ?? null) : null;
        
        return [
            'data' => $data,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
                'cursor' => $cursor,
            ],
        ];
    }
    
    /**
     * Check if summary mode should be used.
     */
    public function shouldUseSummaryMode(int $count): bool
    {
        $threshold = $this->config['summary_threshold'] ?? 5;
        return $count > $threshold;
    }
    
    /**
     * Encode cursor for pagination.
     */
    public function encodeCursor($value): string
    {
        return base64_encode(json_encode([
            'value' => $value,
            'timestamp' => time(),
        ]));
    }
    
    /**
     * Decode cursor for pagination.
     */
    public function decodeCursor(string $cursor): ?array
    {
        try {
            $decoded = base64_decode($cursor);
            return json_decode($decoded, true);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Calculate response size for metadata.
     */
    protected function calculateResponseSize(array $data): array
    {
        $json = json_encode($data);
        $bytes = strlen($json);
        $tokens = (int) ($bytes / 4); // Rough estimate: 1 token ≈ 4 characters
        
        return [
            'bytes' => $bytes,
            'kb' => round($bytes / 1024, 2),
            'estimated_tokens' => $tokens,
        ];
    }
    
    /**
     * Split data into pages.
     */
    public function splitIntoPages(array $data, int $perPage): array
    {
        return array_chunk($data, $perPage);
    }
    
    /**
     * Get page from offset.
     */
    public function getPageFromOffset(int $offset, int $limit): int
    {
        return (int) floor($offset / $limit) + 1;
    }
    
    /**
     * Get offset from page.
     */
    public function getOffsetFromPage(int $page, int $limit): int
    {
        return ($page - 1) * $limit;
    }
    
    /**
     * Validate pagination parameters.
     */
    public function validateParameters(array $params): array
    {
        $validated = [];
        
        $validated['limit'] = $this->getLimit($params['limit'] ?? null);
        $validated['offset'] = max(0, (int) ($params['offset'] ?? 0));
        $validated['page'] = max(1, (int) ($params['page'] ?? 1));
        
        // Convert page to offset if page is provided
        if (isset($params['page']) && !isset($params['offset'])) {
            $validated['offset'] = $this->getOffsetFromPage($validated['page'], $validated['limit']);
        }
        
        return $validated;
    }
}
