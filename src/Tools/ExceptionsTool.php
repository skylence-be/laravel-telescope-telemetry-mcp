<?php

declare(strict_types=1);

namespace Skylence\TelescopeMcp\Tools;

final class ExceptionsTool extends AbstractTool
{
    protected string $entryType = 'exception';

    public function getShortName(): string
    {
        return 'exceptions';
    }

    public function getSchema(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Analyze application exceptions with stack traces and occurrence patterns',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['summary', 'list', 'detail', 'stats', 'recent', 'grouped'],
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
                    'class' => [
                        'type' => 'string',
                        'description' => 'Filter by exception class',
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
            'recent' => $this->getRecentExceptions($arguments),
            'grouped' => $this->getGroupedExceptions($arguments),
            default => parent::execute($arguments),
        };
    }

    /**
     * Get recent exceptions with limited stack traces.
     */
    protected function getRecentExceptions(array $arguments): array
    {
        $limit = $this->pagination->getLimit($arguments['limit'] ?? 5);
        $entries = $this->normalizeEntries(
            array_slice($this->getEntries($arguments), 0, $limit)
        );

        $exceptions = array_map(function ($exception) {
            return [
                'id' => $exception['id'],
                'class' => $exception['content']['class'] ?? 'Unknown',
                'message' => $exception['content']['message'] ?? '',
                'file' => $exception['content']['file'] ?? '',
                'line' => $exception['content']['line'] ?? 0,
                'occurred_at' => $exception['created_at'] ?? '',
                'stack_preview' => $this->getStackPreview($exception['content']['trace'] ?? []),
            ];
        }, $entries);

        return $this->formatter->format([
            'recent_exceptions' => $exceptions,
            'total' => count($exceptions),
            'period' => $arguments['period'] ?? 'recent',
        ], 'standard');
    }

    /**
     * Get exceptions grouped by class.
     */
    protected function getGroupedExceptions(array $arguments): array
    {
        $entries = $this->normalizeEntries($this->getEntries($arguments));
        $grouped = [];

        foreach ($entries as $exception) {
            $class = $exception['content']['class'] ?? 'Unknown';

            if (! isset($grouped[$class])) {
                $grouped[$class] = [
                    'class' => $class,
                    'count' => 0,
                    'first_seen' => $exception['created_at'],
                    'last_seen' => $exception['created_at'],
                    'messages' => [],
                    'locations' => [],
                ];
            }

            $grouped[$class]['count']++;
            $grouped[$class]['last_seen'] = $exception['created_at'];

            $message = $exception['content']['message'] ?? '';
            if (! in_array($message, $grouped[$class]['messages']) && count($grouped[$class]['messages']) < 3) {
                $grouped[$class]['messages'][] = substr($message, 0, 100);
            }

            $location = ($exception['content']['file'] ?? '').':'.($exception['content']['line'] ?? '');
            if (! in_array($location, $grouped[$class]['locations']) && count($grouped[$class]['locations']) < 3) {
                $grouped[$class]['locations'][] = $location;
            }
        }

        // Sort by count
        uasort($grouped, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $this->formatter->format([
            'grouped_exceptions' => array_slice($grouped, 0, 10, true),
            'total_groups' => count($grouped),
            'total_exceptions' => count($entries),
        ], 'standard');
    }

    /**
     * Override summary for exception-specific insights.
     */
    public function summary(array $arguments = []): array
    {
        $entries = $this->normalizeEntries($this->getEntries($arguments));

        if (empty($entries)) {
            return $this->formatter->formatSummary([
                'message' => 'No exceptions recorded',
                'status' => 'healthy',
            ]);
        }

        $grouped = $this->groupExceptionsByClass($entries);
        $topExceptions = array_slice($grouped, 0, 3, true);

        return $this->formatter->formatSummary([
            'total_exceptions' => count($entries),
            'unique_exceptions' => count($grouped),
            'top_exceptions' => array_map(function ($class, $count) {
                return "{$class} ({$count}x)";
            }, array_keys($topExceptions), $topExceptions),
            'health_impact' => $this->assessHealthImpact(count($entries)),
        ]);
    }

    /**
     * Get stack trace preview (first 3 frames).
     */
    protected function getStackPreview(array $trace): string
    {
        if (empty($trace)) {
            return 'No stack trace available';
        }

        $preview = array_slice($trace, 0, 3);
        $frames = [];

        foreach ($preview as $frame) {
            $class = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            $line = $frame['line'] ?? 0;

            $frames[] = "{$class}::{$function}:{$line}";
        }

        return implode(' → ', $frames);
    }

    /**
     * Group exceptions by class.
     */
    protected function groupExceptionsByClass(array $exceptions): array
    {
        $grouped = [];

        foreach ($exceptions as $exception) {
            $class = $exception['content']['class'] ?? 'Unknown';
            $grouped[$class] = ($grouped[$class] ?? 0) + 1;
        }

        arsort($grouped);

        return $grouped;
    }

    /**
     * Assess health impact based on exception count.
     */
    protected function assessHealthImpact(int $count): string
    {
        if ($count === 0) {
            return 'none';
        }
        if ($count < 5) {
            return 'minimal';
        }
        if ($count < 20) {
            return 'moderate';
        }
        if ($count < 50) {
            return 'significant';
        }

        return 'critical';
    }

    protected function getListFields(): array
    {
        return [
            'id',
            'content.class',
            'content.message',
            'content.file',
            'content.line',
            'created_at',
        ];
    }

    protected function getSearchableFields(): array
    {
        return [
            'class',
            'message',
            'file',
        ];
    }

    /**
     * Override detail to include limited stack trace.
     */
    public function detail(string $id, array $arguments = []): array
    {
        $entry = $this->storage->find($id);

        if (! $entry) {
            return $this->formatter->formatError('Exception not found', 404);
        }

        $data = $entry->toArray();

        // Limit stack trace to 5 frames for token efficiency
        if (isset($data['content']['trace'])) {
            $data['content']['trace'] = array_slice($data['content']['trace'], 0, 5);
        }

        return $this->formatter->formatDetail($data);
    }
}
