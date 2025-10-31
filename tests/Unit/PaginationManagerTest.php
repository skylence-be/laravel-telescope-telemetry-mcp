<?php

namespace Skylence\TelescopeMcp\Tests\Unit;

use Skylence\TelescopeMcp\Tests\TestCase;
use Skylence\TelescopeMcp\Services\PaginationManager;

class PaginationManagerTest extends TestCase
{
    protected PaginationManager $paginationManager;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->paginationManager = new PaginationManager([
            'default' => 10,
            'maximum' => 25,
            'summary_threshold' => 5,
        ]);
    }
    
    public function test_get_limit_returns_default_when_null()
    {
        $limit = $this->paginationManager->getLimit(null);
        
        $this->assertEquals(10, $limit);
    }
    
    public function test_get_limit_respects_maximum()
    {
        $limit = $this->paginationManager->getLimit(50);
        
        $this->assertEquals(25, $limit);
    }
    
    public function test_get_limit_returns_requested_when_valid()
    {
        $limit = $this->paginationManager->getLimit(15);
        
        $this->assertEquals(15, $limit);
    }
    
    public function test_paginate_includes_metadata()
    {
        $data = ['item1', 'item2', 'item3'];
        $result = $this->paginationManager->paginate($data, 10, 3, 0);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('_meta', $result);
        
        $this->assertEquals($data, $result['data']);
        $this->assertEquals(10, $result['pagination']['total']);
        $this->assertEquals(3, $result['pagination']['limit']);
        $this->assertTrue($result['pagination']['has_more']);
    }
    
    public function test_should_use_summary_mode()
    {
        $this->assertFalse($this->paginationManager->shouldUseSummaryMode(3));
        $this->assertTrue($this->paginationManager->shouldUseSummaryMode(10));
    }
    
    public function test_cursor_encoding_and_decoding()
    {
        $value = 'test-value-123';
        $encoded = $this->paginationManager->encodeCursor($value);
        $decoded = $this->paginationManager->decodeCursor($encoded);
        
        $this->assertIsString($encoded);
        $this->assertArrayHasKey('value', $decoded);
        $this->assertEquals($value, $decoded['value']);
    }
    
    public function test_split_into_pages()
    {
        $data = range(1, 10);
        $pages = $this->paginationManager->splitIntoPages($data, 3);
        
        $this->assertCount(4, $pages); // 3, 3, 3, 1
        $this->assertCount(3, $pages[0]);
        $this->assertCount(1, $pages[3]);
    }
    
    public function test_page_offset_conversion()
    {
        $offset = $this->paginationManager->getOffsetFromPage(3, 10);
        $this->assertEquals(20, $offset);
        
        $page = $this->paginationManager->getPageFromOffset(20, 10);
        $this->assertEquals(3, $page);
    }
    
    public function test_validate_parameters()
    {
        $params = [
            'limit' => 100,
            'offset' => -5,
            'page' => 0,
        ];
        
        $validated = $this->paginationManager->validateParameters($params);
        
        $this->assertEquals(25, $validated['limit']); // Capped at maximum
        $this->assertEquals(0, $validated['offset']); // Minimum 0
        $this->assertEquals(1, $validated['page']); // Minimum 1
    }
    
    public function test_cursor_paginate()
    {
        $data = array_map(fn($i) => ['id' => $i], range(1, 15));
        $result = $this->paginationManager->cursorPaginate($data, null, 10);
        
        $this->assertCount(10, $result['data']);
        $this->assertTrue($result['pagination']['has_more']);
        $this->assertNotNull($result['pagination']['next_cursor']);
    }
}
