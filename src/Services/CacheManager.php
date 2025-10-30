<?php

namespace LaravelTelescope\Telemetry\Services;

use Illuminate\Contracts\Cache\Repository;

class CacheManager
{
    protected array $config;
    protected Repository $cache;
    
    public function __construct(array $config, Repository $cache)
    {
        $this->config = $config;
        $this->cache = $cache;
    }
    
    /**
     * Check if caching is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }
    
    /**
     * Remember value in cache.
     */
    public function remember(string $key, \Closure $callback, ?int $ttl = null)
    {
        if (!$this->isEnabled()) {
            return $callback();
        }
        
        $ttl = $ttl ?? $this->getDefaultTtl();
        
        return $this->cache->remember($this->prefixKey($key), $ttl, $callback);
    }
    
    /**
     * Get value from cache.
     */
    public function get(string $key, $default = null)
    {
        if (!$this->isEnabled()) {
            return $default;
        }
        
        return $this->cache->get($this->prefixKey($key), $default);
    }
    
    /**
     * Store value in cache.
     */
    public function put(string $key, $value, ?int $ttl = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        
        $ttl = $ttl ?? $this->getDefaultTtl();
        
        return $this->cache->put($this->prefixKey($key), $value, $ttl);
    }
    
    /**
     * Remove value from cache.
     */
    public function forget(string $key): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        
        return $this->cache->forget($this->prefixKey($key));
    }
    
    /**
     * Clear all cache with prefix.
     */
    public function flush(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        
        // Clear all keys with our prefix
        $prefix = $this->config['prefix'] ?? 'telescope_telemetry';
        
        // If using Redis, we can use pattern deletion
        if ($this->cache->getStore() instanceof \Illuminate\Cache\RedisStore) {
            $connection = $this->cache->getStore()->connection();
            $keys = $connection->keys($prefix . '*');
            
            if (!empty($keys)) {
                $connection->del($keys);
            }
            
            return true;
        }
        
        // For other cache drivers, flush everything (less ideal)
        return $this->cache->flush();
    }
    
    /**
     * Get cache statistics.
     */
    public function getStats(): array
    {
        if (!$this->isEnabled()) {
            return [
                'enabled' => false,
                'driver' => 'none',
                'hits' => 0,
                'misses' => 0,
            ];
        }
        
        $stats = [
            'enabled' => true,
            'driver' => $this->config['driver'] ?? 'default',
            'prefix' => $this->config['prefix'] ?? 'telescope_telemetry',
            'ttl' => $this->config['ttl'] ?? [],
        ];
        
        // Add Redis-specific stats if available
        if ($this->cache->getStore() instanceof \Illuminate\Cache\RedisStore) {
            try {
                $info = $this->cache->getStore()->connection()->info();
                $stats['redis_memory'] = $info['used_memory_human'] ?? 'unknown';
                $stats['redis_keys'] = $info['db0']['keys'] ?? 0;
            } catch (\Exception $e) {
                // Redis stats not available
            }
        }
        
        return $stats;
    }
    
    /**
     * Get TTL for specific cache type.
     */
    public function getTtl(string $type): int
    {
        $ttls = $this->config['ttl'] ?? [];
        
        return $ttls[$type] ?? $this->getDefaultTtl();
    }
    
    /**
     * Get default TTL.
     */
    protected function getDefaultTtl(): int
    {
        return $this->config['ttl']['default'] ?? 300;
    }
    
    /**
     * Prefix cache key.
     */
    protected function prefixKey(string $key): string
    {
        $prefix = $this->config['prefix'] ?? 'telescope_telemetry';
        
        return "{$prefix}:{$key}";
    }
    
    /**
     * Warm cache with commonly accessed data.
     */
    public function warmCache(array $tools): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        
        foreach ($tools as $tool) {
            // Warm summary cache for each tool
            $key = "summary:{$tool}:default";
            $this->remember($key, function () use ($tool) {
                // This would call the actual tool's summary method
                return ['warmed' => true, 'tool' => $tool];
            }, $this->getTtl('summary'));
        }
    }
    
    /**
     * Invalidate cache for specific pattern.
     */
    public function invalidatePattern(string $pattern): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }
        
        $prefix = $this->config['prefix'] ?? 'telescope_telemetry';
        $fullPattern = "{$prefix}:{$pattern}";
        
        // Redis-specific pattern deletion
        if ($this->cache->getStore() instanceof \Illuminate\Cache\RedisStore) {
            $connection = $this->cache->getStore()->connection();
            $keys = $connection->keys($fullPattern);
            
            if (!empty($keys)) {
                $connection->del($keys);
                return count($keys);
            }
        }
        
        return 0;
    }
    
    /**
     * Get cache hit rate.
     */
    public function getHitRate(): array
    {
        // This would need to be tracked separately, possibly using Redis counters
        // For now, return placeholder data
        return [
            'hits' => $this->get('stats:hits', 0),
            'misses' => $this->get('stats:misses', 0),
            'hit_rate' => 0,
        ];
    }
    
    /**
     * Track cache hit.
     */
    public function trackHit(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        
        $hits = $this->get('stats:hits', 0);
        $this->put('stats:hits', $hits + 1, 86400);
    }
    
    /**
     * Track cache miss.
     */
    public function trackMiss(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        
        $misses = $this->get('stats:misses', 0);
        $this->put('stats:misses', $misses + 1, 86400);
    }
}
