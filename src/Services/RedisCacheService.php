<?php

namespace RedisAdvancedCache\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RedisAdvancedCache\Utils\RedisCacheUtils;

class RedisCacheService
{
    protected ?\Redis $redis = null;
    protected string $prefix;
    protected bool $debug;
    protected bool $enabled;
    protected int $scanCount;
    protected int $ttl;
    protected string $host;
    protected int $port;
    protected ?string $password;
    protected int $db;
    protected bool $listenEnabled;
    private static ?self $instance = null;

    /**
     * RedisCacheService constructor.
     *
     * Initializes Redis cache configuration and establishes a connection
     * using environment variables or configuration file values.
     */
    public function __construct()
    {
        $this->enabled = (bool) config('redis_advanced_cache.enabled', true);
        $this->debug = (bool) config('redis_advanced_cache.debug', false);
        $this->prefix = config('redis_advanced_cache.key_identifier.prefix', 'cache_');
        $this->scanCount = (int) config('redis_advanced_cache.options.cache_flush_scan_count', 300);
        $this->ttl = (int) config('redis_advanced_cache.options.ttl', 86400);
        $this->pattern = config('redis_advanced_cache.pattern', 'default');
        $this->listenEnabled = (bool) config('redis_advanced_cache.listen_queries', true);

        $conn = config('redis_advanced_cache.connection');
        $this->host = $conn['host'] ?? '127.0.0.1';
        $this->port = $conn['port'] ?? 6379;
        $this->password = $conn['password'] ?? null;
        $this->db = $conn['database'] ?? 1;

        $this->initRedis();
    }

    /**
     * Initialize a connection to the Redis server.
     *
     * Establishes a connection, authenticates (if required), and selects
     * the appropriate database. If the connection fails, Redis is disabled.
     *
     * @throws \RedisException if Redis connection or authentication fails
     */
    private function initRedis(): void
    {
        if (!$this->enabled) {
            if ($this->debug) \Log::debug('[RedisCacheService] ❗ Redis cache is disabled.');
            return;
        }

        try {
            $this->redis = new \Redis();
            $this->redis->pconnect($this->host, $this->port);
            if ($this->password) $this->redis->auth($this->password);
            $this->redis->select($this->db);

            if ($this->debug) \Log::debug('[RedisCacheService] ✅ Redis connection established successfully.');
        } catch (\Throwable $e) {
            $this->redis = null;
            if ($this->debug) \Log::error('[RedisCacheService] ❌ Redis connection failed: '.$e->getMessage());
        }
    }

    /**
     * Get the singleton instance of the RedisCacheService.
     *
     * Ensures that only one instance of the service exists throughout the application
     * lifecycle. If the instance does not exist yet, it will be created automatically.
     *
     * @return self Returns the unique instance of the RedisCacheService.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Get the current Redis connection instance.
     *
     * @return \Redis|null the Redis connection instance or null if disabled/unavailable
     */
    public function getRedis(): ?\Redis
    {
        return $this->redis;
    }

    /**
     * Delete all Redis keys matching a given pattern.
     *
     * This method uses the SCAN command to progressively find and delete
     * keys, avoiding blocking Redis. Only keys containing the given pattern
     * will be removed.
     *
     * @param string $pattern The pattern to match (e.g. ':users:', ':articles:').
     *
     * @throws \RedisException if Redis interaction fails
     */
    public function delete(string $pattern): void
    {
        if (!$this->enabled || !$this->redis) return;

        try {
            $cursor = null;
            do {
                $response = $this->redis->scan($cursor, [
                    'match' => "*$pattern*",
                    'count' => $this->scanCount,
                ]);

                if ($response === false) break;

                [$cursor, $results] = $response;
                $results = array_map(fn($key) => Str::replace($this->prefix, '', (string)$key), $results);

                if (!empty($results)) {
                    $this->redis->del($results);
                    if ($this->debug) \Log::debug('[RedisCacheService] ✅ Deleted cache keys: '.implode(', ', $results));
                }
            } while ($cursor !== 0 && $cursor !== null);
        } catch (\Throwable $e) {
            $this->redis = null;
            if ($this->debug) \Log::error('[RedisCacheService] ❌ Error deleting keys with pattern '.$pattern.': '.$e->getMessage());
        }
    }

    /**
     * Flush all Redis cache keys.
     *
     * When `$onlyPrefixed` is true, only keys beginning with the configured
     * prefix will be deleted. Otherwise, all keys in the current Redis
     * database are removed.
     *
     * @param bool $onlyPrefixed whether to restrict deletion to keys starting with the configured prefix
     *
     * @throws \RedisException if Redis interaction fails
     */
    public function flushAll(bool $onlyPrefixed = true): void
    {
        if (!$this->enabled || !$this->redis) return;

        try {
            $cursor = null;
            $pattern = $onlyPrefixed ? "{$this->prefix}*" : '*';

            do {
                $response = $this->redis->scan($cursor, [
                    'match' => $pattern,
                    'count' => $this->scanCount,
                ]);

                if ($response === false) break;

                [$cursor, $keys] = $response;
                if (!empty($keys)) {
                    $this->redis->del($keys);
                    if ($this->debug) \Log::debug('[RedisCacheService] ✅ Flushed cache keys: '.implode(', ', $keys));
                }
            } while ($cursor !== 0 && $cursor !== null);
        } catch (\Throwable $e) {
            $this->redis = null;
            if ($this->debug) \Log::error('[RedisCacheService] ❌ Error flushing Redis cache: '.$e->getMessage());
        }
    }

    /**
     * Listen to database write operations and automatically invalidate
     * related Redis cache entries.
     *
     * This method hooks into Laravel's DB::listen() and watches for
     * INSERT, UPDATE, or DELETE queries. Detected tables are used to
     * clear associated cache entries.
     */
    public function listenToWriteQueries(): void
    {
        if (!$this->enabled || !$this->redis || !$this->listenEnabled) {
            if ($this->debug) \Log::warning('[RedisCacheService] ❗ Write query listening is disabled.');
            return;
        }

        try {
            DB::listen(function ($query) {
                if ($operation = RedisCacheUtils::detectWriteOperation($query->sql)) {
                    $flushables = RedisCacheUtils::getFlushableTablesFromSql($query->sql);
                    foreach ($flushables as $flushKey) {
                        $this->delete(":$flushKey:");
                        if ($this->debug) \Log::debug('[RedisCacheService] ✅ Cache invalidated for table: '.$flushKey.' (SQL: '.$query->sql.')');
                    }
                }
            });

            if ($this->debug) \Log::debug('[RedisCacheService] ❓ Listening to database write queries for cache invalidation.');
        } catch (\Throwable $e) {
            $this->redis = null;
            if ($this->debug) \Log::error('[RedisCacheService] ❌ Error listening to write queries: '.$e->getMessage());
        }
    }
}
