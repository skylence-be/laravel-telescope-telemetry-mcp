<?php

declare(strict_types=1);

namespace Skylence\TelescopeMcp\Tools;

use Skylence\TelescopeMcp\Services\PaginationManager;
use Skylence\TelescopeMcp\Services\ResponseFormatter;

final class VersionTool extends AbstractTool
{
    protected string $entryType = '';

    public function getShortName(): string
    {
        return 'version';
    }

    public function getSchema(): array
    {
        return [
            'name' => $this->getShortName(),
            'description' => 'Get the MCP server version and build information',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ],
        ];
    }

    public function execute(array $arguments = []): array
    {
        return $this->formatter->format([
            'version' => \Skylence\TelescopeMcp\MCP\TelescopeMcpServer::VERSION,
            'build_date' => date('Y-m-d H:i:s'),
            'changes' => [
                'v1.3.6: Use short names in capabilities like simple-mcp',
                'v1.3.5: Return tools directly in capabilities for Claude Code compatibility',
                'v1.3.4: Changed capabilities.tools to empty object for Claude Code compatibility',
                'v1.3.3: Fixed capabilities response to return empty object per MCP protocol spec',
                'v1.3.2: Fixed authentication to use config() instead of env() for config caching compatibility',
                'v1.3.2: Changed insecure default (allow any token) to secure default (deny all) when token not set',
                'v1.3.1: Fixed lazy loading of tools to resolve race condition with ServiceProvider',
                'v1.3.0: Completely removed CacheManager and all caching for real-time monitoring',
                'v1.2.0: Removed cache configuration from config file',
                'v1.1.0: Added period filtering (5m, 15m, 1h, 6h, 24h, 7d, 14d, 21d, 30d, 3M, 6M, 12M)',
                'v1.1.0: Increased fetch limit to 10,000 when period is specified',
                'v1.1.0: Fixed OverviewTool to use period filtering',
                'v1.1.0: Fixed created_at timestamp display',
                'v1.1.0: Added VersionTool',
            ],
        ], 'standard');
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
