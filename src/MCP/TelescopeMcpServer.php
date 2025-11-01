<?php

declare(strict_types=1);

namespace Skylence\TelescopeMcp\MCP;

use Skylence\TelescopeMcp\Tools\AbstractTool;

final class TelescopeMcpServer
{
    public const VERSION = '1.3.3'; // Fixed capabilities response to follow MCP protocol spec

    /**
     * Registered tools.
     *
     * @var array<string, AbstractTool>|null
     */
    private ?array $tools = null;

    /**
     * Create a new TelescopeMcpServer instance.
     */
    public function __construct()
    {
        // Tools will be lazily loaded on first access
    }

    /**
     * Register all available tools.
     */
    private function registerTools(): void
    {
        if ($this->tools !== null) {
            return;
        }

        $this->tools = [];

        $toolClasses = [
            \Skylence\TelescopeMcp\Tools\VersionTool::class,
            \Skylence\TelescopeMcp\Tools\OverviewTool::class,
            \Skylence\TelescopeMcp\Tools\RequestsTool::class,
            \Skylence\TelescopeMcp\Tools\QueriesTool::class,
            \Skylence\TelescopeMcp\Tools\ExceptionsTool::class,
        ];

        foreach ($toolClasses as $class) {
            if (class_exists($class)) {
                try {
                    $tool = app($class);
                    $this->registerTool($tool);
                } catch (\Exception $e) {
                    // Tool not available, skip
                }
            }
        }
    }

    /**
     * Register a single tool.
     */
    private function registerTool(AbstractTool $tool): void
    {
        $this->tools[$tool->getShortName()] = $tool;
    }

    /**
     * Get the server manifest.
     */
    public function getManifest(): array
    {
        return [
            'name' => 'telescope-mcp',
            'version' => self::VERSION,
            'description' => 'Token-optimized Laravel Telescope MCP server for AI assistants',
            'tools' => $this->getToolsAsObject(),
            'resources' => $this->getResourcesAsObject(),
            'prompts' => $this->getPromptsAsObject(),
            'metadata' => $this->getMetadata(),
        ];
    }

    /**
     * Get all registered tools schemas as array (for tools/list JSON-RPC method).
     */
    public function getTools(): array
    {
        return $this->getToolsAsArray();
    }

    /**
     * Get tools as an array (for tools/list JSON-RPC method).
     */
    private function getToolsAsArray(): array
    {
        $this->registerTools();

        $tools = [];
        foreach ($this->tools as $tool) {
            $schema = $tool->getSchema();
            $tools[] = [
                'name' => $schema['name'],
                'description' => $schema['description'],
                'inputSchema' => $schema['inputSchema'] ?? [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ];
        }

        return $tools;
    }

    /**
     * Get tools as an object keyed by tool name (for manifest capabilities).
     */
    private function getToolsAsObject(): array
    {
        $this->registerTools();

        $tools = [];
        foreach ($this->tools as $tool) {
            $schema = $tool->getSchema();
            $tools[$tool->getShortName()] = [
                'name' => $schema['name'],
                'description' => $schema['description'],
                'inputSchema' => $schema['inputSchema'] ?? [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ];
        }

        return $tools;
    }

    /**
     * Get available resources as an object keyed by URI (for MCP protocol).
     */
    private function getResourcesAsObject(): array
    {
        return [
            'telescope-mcp://overview' => [
                'uri' => 'telescope-mcp://overview',
                'name' => 'Telescope Overview',
                'description' => 'Complete system overview and health status from Laravel Telescope',
                'mimeType' => 'application/json',
            ],
        ];
    }

    /**
     * Get available prompts as an object keyed by prompt name (for MCP protocol).
     */
    private function getPromptsAsObject(): array
    {
        return [
            'analyze-performance' => [
                'name' => 'analyze-performance',
                'description' => 'Analyze application performance using Telescope data',
                'arguments' => [
                    [
                        'name' => 'timeframe',
                        'description' => 'Time period to analyze (e.g., "1 hour", "24 hours")',
                        'required' => false,
                    ],
                ],
            ],
            'debug-errors' => [
                'name' => 'debug-errors',
                'description' => 'Debug recent errors and exceptions',
                'arguments' => [
                    [
                        'name' => 'limit',
                        'description' => 'Number of errors to analyze',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get server metadata.
     */
    private function getMetadata(): array
    {
        return [
            'author' => 'Skylence',
            'repository' => 'https://github.com/skylence-be/laravel-telescope-telemetry-mcp',
            'license' => 'MIT',
            'tags' => [
                'laravel',
                'telescope',
                'mcp',
                'model-context-protocol',
                'monitoring',
                'debugging',
                'observability',
                'token-optimized',
                'ai',
                'telemetry',
            ],
        ];
    }

    /**
     * Execute a tool by name.
     */
    public function executeTool(string $toolName, array $params = []): array
    {
        $this->registerTools();

        if (! isset($this->tools[$toolName])) {
            throw new \InvalidArgumentException("Tool '{$toolName}' not found");
        }

        return $this->tools[$toolName]->execute($params);
    }

    /**
     * Check if a tool exists.
     */
    public function hasTool(string $toolName): bool
    {
        $this->registerTools();

        return isset($this->tools[$toolName]);
    }
}
