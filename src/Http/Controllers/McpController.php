<?php

namespace LaravelTelescope\Telemetry\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelTelescope\Telemetry\Services\ResponseFormatter;
use LaravelTelescope\Telemetry\Services\PaginationManager;

class McpController extends Controller
{
    protected ResponseFormatter $formatter;
    protected PaginationManager $pagination;
    protected array $tools = [];
    
    public function __construct(
        ResponseFormatter $formatter,
        PaginationManager $pagination
    ) {
        $this->formatter = $formatter;
        $this->pagination = $pagination;
        $this->registerTools();
    }
    
    /**
     * Get the server manifest.
     */
    public function manifest(Request $request): JsonResponse
    {
        // Handle GET requests for manifest
        if ($request->method() === 'GET') {
            $initData = $this->initialize([]);

            return response()->json([
                'jsonrpc' => '2.0',
                'result' => $initData,
                'id' => null,
            ]);
        }

        // For POST requests, treat as JSON-RPC call
        return $this->handle($request);
    }

    /**
     * Handle MCP JSON-RPC request.
     */
    public function handle(Request $request): JsonResponse
    {
        $method = $request->input('method');
        $params = $request->input('params', []);
        $id = $request->input('id');
        
        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'tools/list' => $this->listTools(),
                'tools/call' => $this->callTool($params),
                'ping' => ['status' => 'pong'],
                default => throw new \Exception("Unknown method: {$method}", -32601),
            };
            
            return response()->json([
                'jsonrpc' => '2.0',
                'result' => $result,
                'id' => $id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => $e->getCode() ?: -32603,
                    'message' => $e->getMessage(),
                ],
                'id' => $id,
            ], 400);
        }
    }
    
    /**
     * Initialize MCP connection.
     */
    protected function initialize(array $params): array
    {
        $tools = [];

        foreach ($this->tools as $name => $tool) {
            $tools[$name] = [
                'name' => $name,
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return [
            'protocolVersion' => '2024-11-05',
            'serverInfo' => [
                'name' => 'laravel-telescope-telemetry',
                'version' => '1.0.0',
            ],
            'capabilities' => [
                'tools' => $tools,
                'prompts' => [],
                'resources' => [],
            ],
            'instructions' => $this->getInstructions(),
        ];
    }
    
    /**
     * List available tools.
     */
    public function listTools(): JsonResponse|array
    {
        $tools = [];
        
        foreach ($this->tools as $name => $tool) {
            $tools[] = [
                'name' => $name,
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }
        
        // If called directly via HTTP
        if (request()->isMethod('GET')) {
            return response()->json([
                'tools' => $tools,
                'count' => count($tools),
                'categories' => $this->categorizeTools($tools),
            ]);
        }
        
        return ['tools' => $tools];
    }
    
    /**
     * Call a specific tool.
     */
    protected function callTool(array $params): array
    {
        $toolName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        
        if (!$toolName) {
            throw new \Exception('Tool name is required', -32602);
        }
        
        if (!isset($this->tools[$toolName])) {
            throw new \Exception("Tool not found: {$toolName}", -32602);
        }
        
        $tool = $this->tools[$toolName];
        
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($tool->execute($arguments), JSON_PRETTY_PRINT),
                ],
            ],
            'isError' => false,
        ];
    }
    
    /**
     * Register all available tools.
     */
    protected function registerTools(): void
    {
        $toolClasses = [
            'telescope.overview' => \LaravelTelescope\Telemetry\Tools\OverviewTool::class,
            'telescope.requests' => \LaravelTelescope\Telemetry\Tools\RequestsTool::class,
            'telescope.queries' => \LaravelTelescope\Telemetry\Tools\QueriesTool::class,
            'telescope.exceptions' => \LaravelTelescope\Telemetry\Tools\ExceptionsTool::class,
            'telescope.jobs' => \LaravelTelescope\Telemetry\Tools\JobsTool::class,
            'telescope.cache' => \LaravelTelescope\Telemetry\Tools\CacheTool::class,
            'telescope.logs' => \LaravelTelescope\Telemetry\Tools\LogsTool::class,
            'telescope.events' => \LaravelTelescope\Telemetry\Tools\EventsTool::class,
            'telescope.mail' => \LaravelTelescope\Telemetry\Tools\MailTool::class,
            'telescope.models' => \LaravelTelescope\Telemetry\Tools\ModelsTool::class,
            'telescope.redis' => \LaravelTelescope\Telemetry\Tools\RedisTool::class,
            'telescope.commands' => \LaravelTelescope\Telemetry\Tools\CommandsTool::class,
            'telescope.schedule' => \LaravelTelescope\Telemetry\Tools\ScheduleTool::class,
            'telescope.notifications' => \LaravelTelescope\Telemetry\Tools\NotificationsTool::class,
            'telescope.gates' => \LaravelTelescope\Telemetry\Tools\GatesTool::class,
            'telescope.views' => \LaravelTelescope\Telemetry\Tools\ViewsTool::class,
            'telescope.http_client' => \LaravelTelescope\Telemetry\Tools\HttpClientTool::class,
            'telescope.dumps' => \LaravelTelescope\Telemetry\Tools\DumpsTool::class,
            'telescope.batches' => \LaravelTelescope\Telemetry\Tools\BatchesTool::class,
            
            // Analysis tools
            'telescope.analysis.performance' => \LaravelTelescope\Telemetry\Tools\Analysis\PerformanceAnalysisTool::class,
            'telescope.analysis.bottlenecks' => \LaravelTelescope\Telemetry\Tools\Analysis\BottleneckAnalysisTool::class,
            'telescope.analysis.health' => \LaravelTelescope\Telemetry\Tools\Analysis\HealthCheckTool::class,
            'telescope.analysis.suggestions' => \LaravelTelescope\Telemetry\Tools\Analysis\SuggestionsTool::class,
        ];
        
        foreach ($toolClasses as $name => $class) {
            if (class_exists($class)) {
                try {
                    $this->tools[$name] = app($class);
                } catch (\Exception $e) {
                    // Tool not available, skip
                }
            }
        }
    }
    
    /**
     * Categorize tools for better organization.
     */
    protected function categorizeTools(array $tools): array
    {
        $categories = [
            'overview' => [],
            'monitoring' => [],
            'database' => [],
            'performance' => [],
            'errors' => [],
            'communication' => [],
            'analysis' => [],
        ];
        
        foreach ($tools as $tool) {
            $name = $tool['name'];
            
            if (str_contains($name, 'overview')) {
                $categories['overview'][] = $name;
            } elseif (str_contains($name, 'analysis')) {
                $categories['analysis'][] = $name;
            } elseif (in_array($name, ['telescope.requests', 'telescope.jobs', 'telescope.commands'])) {
                $categories['performance'][] = $name;
            } elseif (in_array($name, ['telescope.queries', 'telescope.models', 'telescope.redis'])) {
                $categories['database'][] = $name;
            } elseif (in_array($name, ['telescope.exceptions', 'telescope.logs', 'telescope.dumps'])) {
                $categories['errors'][] = $name;
            } elseif (in_array($name, ['telescope.mail', 'telescope.notifications', 'telescope.events'])) {
                $categories['communication'][] = $name;
            } else {
                $categories['monitoring'][] = $name;
            }
        }
        
        return array_filter($categories);
    }
    
    /**
     * Get MCP server instructions.
     */
    protected function getInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        This is a token-optimized Laravel Telescope MCP server.
        
        Key features:
        - Responses are optimized for AI token consumption
        - Default limit is 10 entries (max 25) to prevent token overflow
        - Use summary mode for overview before requesting details
        - Progressive disclosure: summary → list → detail
        
        Recommended workflow:
        1. Start with telescope.overview for system health
        2. Use telescope.analysis.* tools for automated insights
        3. Drill down into specific areas only when needed
        4. Use pagination for large datasets
        
        Query parameters:
        - limit: Number of results (default 10, max 25)
        - mode: Response mode (summary|standard|detailed)
        - fields: Comma-separated fields to include
        - page: Page number for pagination
        
        All responses include token usage estimates.
        INSTRUCTIONS;
    }
}
