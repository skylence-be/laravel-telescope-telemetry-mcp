<?php

declare(strict_types=1);

namespace Skylence\TelescopeMcp\Tools;

use Illuminate\Support\Facades\App;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Skylence\TelescopeMcp\Contracts\ToolInterface;
use Skylence\TelescopeMcp\Services\CacheManager;
use Skylence\TelescopeMcp\Services\PaginationManager;
use Skylence\TelescopeMcp\Services\ResponseFormatter;

abstract class AbstractTool implements ToolInterface
{
    protected array $config;
    protected PaginationManager $pagination;
    protected ResponseFormatter $formatter;
    protected CacheManager $cache;
    protected EntriesRepository $storage;

    /**
     * The Telescope entry type this tool handles.
     */
    protected string $entryType = '';

    public function __construct(
        array $config,
        PaginationManager $pagination,
        ResponseFormatter $formatter,
        CacheManager $cache
    ) {
        $this->config = $config;
        $this->pagination = $pagination;
        $this->formatter = $formatter;
        $this->cache = $cache;
        $this->storage = App::make(EntriesRepository::class);
    }

    /**
     * Get the tool's short name (used as identifier).
     */
    abstract public function getShortName(): string;

    /**
     * Get the tool's full name.
     */
    public function getName(): string
    {
        return 'mcp__telescope-mcp__'.$this->getShortName();
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array{name: string, description: string, inputSchema: array}
     */
    abstract public function getSchema(): array;

    /**
     * Execute the tool with given arguments.
     */
    public function execute(array $arguments = []): array
    {
        $action = $arguments['action'] ?? 'list';

        return match ($action) {
            'summary' => $this->summary($arguments),
            'list' => $this->list($arguments),
            'detail' => $this->detail($arguments['id'] ?? '', $arguments),
            'stats' => $this->stats($arguments),
            'search' => $this->search($arguments),
            default => $this->list($arguments),
        };
    }

    /**
     * Get summary view of the data.
     */
    public function summary(array $arguments = []): array
    {
        $cacheKey = $this->getCacheKey('summary', $arguments);

        return $this->cache->remember($cacheKey, function () use ($arguments) {
            $entries = $this->normalizeEntries($this->getEntries($arguments));

            return $this->formatter->formatSummary([
                'total' => count($entries),
                'type' => $this->entryType,
                'period' => $arguments['period'] ?? '5m',
                'stats' => $this->calculateStats($entries),
            ]);
        });
    }

    /**
     * Get list view of the data.
     */
    public function list(array $arguments = []): array
    {
        $limit = $this->pagination->getLimit($arguments['limit'] ?? null);
        $offset = $arguments['offset'] ?? 0;

        $cacheKey = $this->getCacheKey('list', $arguments);

        return $this->cache->remember($cacheKey, function () use ($limit, $offset, $arguments) {
            $entries = $this->normalizeEntries($this->getEntries($arguments));
            $paginatedEntries = array_slice($entries, $offset, $limit);

            $formatted = $this->formatter->formatList(
                $paginatedEntries,
                $this->getListFields()
            );

            return $this->pagination->paginate(
                $formatted,
                count($entries),
                $limit,
                $offset
            );
        });
    }

    /**
     * Get detailed view of a single item.
     */
    public function detail(string $id, array $arguments = []): array
    {
        $cacheKey = $this->getCacheKey('detail', ['id' => $id]);

        return $this->cache->remember($cacheKey, function () use ($id) {
            $entry = $this->storage->find($id);

            if (! $entry) {
                return [
                    'error' => 'Entry not found',
                    'id' => $id,
                ];
            }

            return $this->formatter->formatDetail($entry->toArray());
        });
    }

    /**
     * Get statistics about the data.
     */
    public function stats(array $arguments = []): array
    {
        $cacheKey = $this->getCacheKey('stats', $arguments);

        return $this->cache->remember($cacheKey, function () use ($arguments) {
            $entries = $this->normalizeEntries($this->getEntries($arguments));

            return $this->formatter->formatStats(
                $this->calculateStats($entries)
            );
        });
    }

    /**
     * Search entries.
     */
    public function search(array $arguments = []): array
    {
        $query = $arguments['query'] ?? '';
        $filters = $arguments['filters'] ?? [];
        $limit = $this->pagination->getLimit($arguments['limit'] ?? null);

        $entries = $this->searchEntries($query, $filters);

        return $this->pagination->paginate(
            $this->formatter->formatList($entries, $this->getListFields()),
            count($entries),
            $limit,
            0
        );
    }

    /**
     * Get entries from storage.
     */
    protected function getEntries(array $arguments = []): array
    {
        $queryOptions = (new EntryQueryOptions())
            ->limit($arguments['limit'] ?? 100);

        if (isset($arguments['tag'])) {
            $queryOptions->tag($arguments['tag']);
        }

        if (isset($arguments['family_hash'])) {
            $queryOptions->familyHash($arguments['family_hash']);
        }

        if (isset($arguments['before'])) {
            $queryOptions->beforeSequence($arguments['before']);
        }

        $entries = iterator_to_array($this->storage->get(
            $this->entryType,
            $queryOptions
        ));

        // Filter by period if specified
        if (isset($arguments['period'])) {
            $cutoffTime = $this->getPeriodCutoffTime($arguments['period']);
            $entries = array_filter($entries, function ($entry) use ($cutoffTime) {
                // Check for createdAt property (camelCase, not created_at)
                $createdAt = $entry->createdAt ?? null;
                if (! $createdAt) {
                    return false;
                }

                // createdAt is a Carbon object, use timestamp() method
                $entryTimestamp = method_exists($createdAt, 'timestamp')
                    ? $createdAt->timestamp
                    : strtotime((string) $createdAt);

                return $entryTimestamp >= $cutoffTime;
            });
            // Re-index array after filtering to avoid gaps in array keys
            $entries = array_values($entries);
        }

        return $entries;
    }

    /**
     * Get cutoff timestamp for period filter.
     */
    protected function getPeriodCutoffTime(string $period): int
    {
        $now = time();

        return match ($period) {
            '5m' => $now - (5 * 60),
            '15m' => $now - (15 * 60),
            '1h' => $now - (60 * 60),
            '6h' => $now - (6 * 60 * 60),
            '24h' => $now - (24 * 60 * 60),
            '7d' => $now - (7 * 24 * 60 * 60),
            '14d' => $now - (14 * 24 * 60 * 60),
            '21d' => $now - (21 * 24 * 60 * 60),
            '30d' => $now - (30 * 24 * 60 * 60),
            default => $now - (60 * 60), // Default to 1 hour
        };
    }

    /**
     * Search entries with query and filters.
     */
    protected function searchEntries(string $query, array $filters): array
    {
        $entries = $this->getEntries($filters);

        if (empty($query)) {
            return $entries;
        }

        return array_filter($entries, function ($entry) use ($query) {
            $searchableContent = $this->getSearchableContent($entry);

            return stripos($searchableContent, $query) !== false;
        });
    }

    /**
     * Get searchable content from an entry.
     */
    protected function getSearchableContent(array $entry): string
    {
        $fields = $this->getSearchableFields();
        $content = [];

        foreach ($fields as $field) {
            if (isset($entry['content'][$field])) {
                $value = $entry['content'][$field];
                $content[] = is_array($value) ? json_encode($value) : (string) $value;
            }
        }

        return implode(' ', $content);
    }

    /**
     * Calculate statistics for entries.
     */
    protected function calculateStats(array $entries): array
    {
        if (empty($entries)) {
            return [
                'count' => 0,
                'avg_duration' => 0,
                'min_duration' => 0,
                'max_duration' => 0,
            ];
        }

        $durations = array_map(function ($entry) {
            return $entry['content']['duration'] ?? 0;
        }, $entries);

        return [
            'count' => count($entries),
            'avg_duration' => array_sum($durations) / count($durations),
            'min_duration' => min($durations),
            'max_duration' => max($durations),
            'p50' => $this->percentile($durations, 50),
            'p95' => $this->percentile($durations, 95),
            'p99' => $this->percentile($durations, 99),
        ];
    }

    /**
     * Calculate percentile value.
     */
    protected function percentile(array $values, int $percentile): float
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $index = ceil(($percentile / 100) * count($values)) - 1;

        return $values[$index] ?? 0;
    }

    /**
     * Get cache key for the operation.
     */
    protected function getCacheKey(string $operation, array $arguments): string
    {
        $key = sprintf(
            'telescope_telemetry_%s_%s_%s',
            $this->entryType,
            $operation,
            md5(json_encode($arguments))
        );

        return $key;
    }

    /**
     * Get fields to include in list view.
     */
    abstract protected function getListFields(): array;

    /**
     * Get searchable fields.
     */
    abstract protected function getSearchableFields(): array;

    /**
     * Normalize entry to array format.
     */
    protected function normalizeEntry(mixed $entry): array
    {
        if (is_array($entry)) {
            return $entry;
        }

        // Handle EntryResult objects from Telescope
        if (is_object($entry)) {
            $content = isset($entry->content) && is_array($entry->content) ? $entry->content : [];
            $id = $entry->id ?? null;
            $createdAt = $entry->created_at ?? null;

            return [
                'id' => $id,
                'content' => $content,
                'created_at' => $createdAt,
            ];
        }

        return ['id' => null, 'content' => [], 'created_at' => null];
    }

    /**
     * Normalize entries array.
     */
    protected function normalizeEntries(array $entries): array
    {
        return array_map(fn ($e) => $this->normalizeEntry($e), $entries);
    }

    /**
     * Format a successful response.
     */
    protected function formatResponse(string $text, mixed $data = null): array
    {
        $response = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return $response;
    }

    /**
     * Format an error response.
     */
    protected function formatError(string $message): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => "Error: {$message}",
                ],
            ],
            'isError' => true,
        ];
    }
}
