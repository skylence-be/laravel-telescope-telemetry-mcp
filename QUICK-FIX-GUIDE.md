# Quick Fix Guide: Reducing Token Consumption

## 🚨 Current Problem
The `laravel-telescope-mcp` package requests up to 100 entries by default, consuming 50-100K tokens per query - overwhelming Claude's context window.

---

## 🔥 Immediate Solutions (Choose One)

### Option 1: Use TelescopeMCP (Python) - Fastest Solution
```bash
# Already has efficient pagination (10 entries default)
cd C:\Users\jonas\dev2\github\TelescopeMCP
uv sync
uv run python telescope_mcp_server.py
```

### Option 2: Fork & Patch laravel-telescope-mcp
```php
// In AbstractTool.php, change the default limit:
$limit = isset($params['limit']) ? min((int)$params['limit'], 25) : 10;  // Was 100, now 10

// In each tool's listX() method:
$options->limit(10);  // Was 50-100, now 10
```

### Option 3: Configure Limits in Your Requests
When using the current package, always specify a small limit:
```
@laravel-telescope-mcp requests --limit 10
@laravel-telescope-mcp exceptions --limit 5
@laravel-telescope-mcp queries --limit 10
```

---

## 🛠️ Quick Patches for laravel-telescope-mcp

### Patch 1: Global Limit Override
Create `app/Overrides/TelescopeMcpOverride.php`:
```php
<?php

namespace App\Overrides;

trait TelescopeMcpOverride
{
    protected function getLimit(array $params): int
    {
        $requested = isset($params['limit']) ? (int)$params['limit'] : 10;
        return min($requested, 25); // Hard cap at 25
    }
}
```

### Patch 2: Config Override
In `config/telescope-mcp.php`:
```php
return [
    'mcp' => [
        'enabled' => true,
        'defaults' => [
            'limit' => 10,      // Override default
            'max_limit' => 25,  // Maximum allowed
        ],
    ],
];
```

### Patch 3: Middleware Token Limiter
```php
// app/Http/Middleware/McpTokenLimiter.php
class McpTokenLimiter
{
    public function handle($request, Closure $next)
    {
        // Force limit parameter
        if ($request->has('params')) {
            $params = $request->input('params');
            if (!isset($params['limit']) || $params['limit'] > 25) {
                $params['limit'] = 10;
                $request->merge(['params' => $params]);
            }
        }
        
        return $next($request);
    }
}
```

---

## 📊 Token Usage Comparison

| Configuration | Entries | Approx. Tokens | Claude Impact |
|--------------|---------|----------------|---------------|
| Original Default | 100 | 50-100K | ❌ Overwhelming |
| Recommended | 10 | 5-10K | ✅ Manageable |
| Summary Mode | N/A | 1-2K | ✅ Optimal |
| Single Entry | 1 | 0.5-2K | ✅ Minimal |

---

## 🚀 Recommended Package Structure

```
laravel-telescope-telemetry-mcp/
├── src/
│   ├── Tools/
│   │   ├── Summary/          # Token-efficient summary tools
│   │   │   ├── OverviewTool.php      # < 1K tokens
│   │   │   ├── StatsTool.php         # < 2K tokens
│   │   │   └── HealthCheckTool.php   # < 500 tokens
│   │   ├── Paginated/        # Standard paginated tools
│   │   │   ├── RequestsTool.php      # 10 entries default
│   │   │   ├── QueriesMcpTool.php    # With analysis
│   │   │   └── ExceptionsTool.php    # With stack summaries
│   │   └── Detailed/         # Full detail tools
│   │       ├── RequestDetailTool.php  # Single entry
│   │       └── QueryAnalysisTool.php  # Deep analysis
│   ├── Optimization/
│   │   ├── TokenCounter.php          # Track token usage
│   │   ├── ResponseOptimizer.php     # Compress responses
│   │   └── PaginationManager.php     # Smart pagination
│   └── Config/
│       └── telescope-telemetry.php   # Optimized defaults
```

---

## ⚡ Quick Implementation Checklist

For immediate token reduction in existing package:

1. **File**: `src/MCP/Tools/RequestsTool.php`
   - [ ] Line ~89: Change `$limit = ... ? min((int)$params['limit'], 100) : 50;`
   - [ ] To: `$limit = ... ? min((int)$params['limit'], 25) : 10;`

2. **File**: `src/MCP/Tools/ExceptionsTool.php`
   - [ ] Line ~84: Change `$limit = ... ? min((int)$params['limit'], 100) : 50;`
   - [ ] To: `$limit = ... ? min((int)$params['limit'], 25) : 10;`

3. **File**: `src/MCP/Tools/QueriesTool.php`
   - [ ] Line ~91: Change `$limit = ... ? min((int)$params['limit'], 100) : 50;`
   - [ ] To: `$limit = ... ? min((int)$params['limit'], 25) : 10;`

4. **File**: `src/MCP/Tools/AbstractTool.php`
   - [ ] Add method:
   ```php
   protected function getDefaultLimit(): int
   {
       return config('telescope-mcp.defaults.limit', 10);
   }
   ```

5. **File**: `config/telescope-mcp.php`
   - [ ] Add:
   ```php
   'defaults' => [
       'limit' => env('TELESCOPE_MCP_DEFAULT_LIMIT', 10),
       'max_limit' => env('TELESCOPE_MCP_MAX_LIMIT', 25),
   ],
   ```

---

## 📈 Expected Results

After implementing these changes:

- **Before**: 1-2 queries exhaust Claude's context
- **After**: 10-20 queries fit comfortably
- **Token reduction**: 80-90%
- **Performance improvement**: 5-10x faster responses
- **Memory usage**: 90% reduction

---

## 🎯 Long-term Solution

Build the new `laravel-telescope-telemetry-mcp` package with:

1. **Smart Defaults**: 10 entries, expandable on demand
2. **Progressive Disclosure**: Summary → List → Details
3. **Token Budget**: Track and warn about token usage
4. **Efficient Formats**: Compressed JSON, field filtering
5. **Caching**: Reduce repeated queries
6. **Streaming**: For large datasets
7. **Analysis Built-in**: N+1 detection, performance insights

---

## 🔗 Resources

- [Original Package Issue](https://github.com/lucianotonet/laravel-telescope-mcp/issues)
- [MCP Protocol Docs](https://modelcontextprotocol.io)
- [Token Counting Guide](https://platform.openai.com/tokenizer)
- [Laravel Telescope Docs](https://laravel.com/docs/telescope)