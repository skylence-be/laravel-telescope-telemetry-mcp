<?php

namespace Skylence\TelescopeMcp\Tests\Feature;

use Skylence\TelescopeMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class McpControllerTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_mcp_initialize_endpoint()
    {
        $response = $this->postJson('/telescope-telemetry', [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [],
            'id' => 1,
        ]);
        
        $response->assertStatus(200)
            ->assertJson([
                'jsonrpc' => '2.0',
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'serverInfo' => [
                        'name' => 'laravel-telescope-telemetry',
                        'version' => '1.0.0',
                    ],
                ],
                'id' => 1,
            ]);
    }
    
    public function test_mcp_list_tools_endpoint()
    {
        $response = $this->postJson('/telescope-telemetry', [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'params' => [],
            'id' => 2,
        ]);
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'jsonrpc',
                'result' => [
                    'tools' => [
                        '*' => [
                            'name',
                            'description',
                            'inputSchema',
                        ],
                    ],
                ],
                'id',
            ]);
    }
    
    public function test_direct_tools_list_endpoint()
    {
        $response = $this->getJson('/telescope-telemetry/tools');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'tools' => [
                    '*' => [
                        'name',
                        'description',
                        'inputSchema',
                    ],
                ],
                'count',
                'categories',
            ]);
    }
    
    public function test_mcp_ping_endpoint()
    {
        $response = $this->postJson('/telescope-telemetry', [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'params' => [],
            'id' => 3,
        ]);
        
        $response->assertStatus(200)
            ->assertJson([
                'jsonrpc' => '2.0',
                'result' => [
                    'status' => 'pong',
                ],
                'id' => 3,
            ]);
    }
    
    public function test_mcp_unknown_method_returns_error()
    {
        $response = $this->postJson('/telescope-telemetry', [
            'jsonrpc' => '2.0',
            'method' => 'unknown_method',
            'params' => [],
            'id' => 4,
        ]);
        
        $response->assertStatus(400)
            ->assertJson([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32601,
                    'message' => 'Unknown method: unknown_method',
                ],
                'id' => 4,
            ]);
    }
    
    public function test_overview_dashboard_endpoint()
    {
        $response = $this->getJson('/telescope-telemetry/overview');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'mode',
                'summary',
                'stats',
                '_meta',
            ]);
    }
    
    public function test_overview_health_endpoint()
    {
        $response = $this->getJson('/telescope-telemetry/overview/health');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'mode',
                'summary' => [
                    'health_score',
                    'status',
                    'metrics',
                ],
            ]);
    }
}
