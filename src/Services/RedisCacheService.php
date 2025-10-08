<?php

namespace RedisAdvancedCache\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RedisAdvancedCache\Utils\RedisCacheUtils;

class RedisCacheService
{
    protected ?\Illuminate\Contracts\Redis\Factory $redis = null;
    protected string $prefix;
    protected bool $enabled;
    protected int $scanCount;
    protected int $ttl;
    protected string $host;
    protected int $port;
    protected ?string $password;
    protected int $db;

    /**
     * Class constructor.
     *
     * Initializes Redis cache configuration and establishes a connection
     * using environment variables or configuration file values.
     */
    public function __construct()
    {
        $this->enabled = (bool) config('redis_advanced_cache.enabled', true);
        $this->prefix = config('redis_advanced_cache.key_identifier.prefix', 'cache_');
        $this->scanCount = (int) config('redis_advanced_cache.options.cache_flush_scan_count', 300);
        $this->ttl = (int) config('redis_advanced_cache.options.ttl', 86400);
        $this->pattern = (int) config('redis_advanced_cache.pattern', 'default');

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
     * @throws \RedisException If Redis connection or authentication fails.
     * @return void
     */
    private function initRedis(): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->redis = new \Redis();
            $this->redis->connect($this->host, $this->port);
            if ($this->password) {
                $this->redis->auth($this->password);
            }
            $this->redis->select($this->db);
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }

    /**
     * Get the current Redis connection instance.
     *
     * @return \Redis|null The Redis connection instance or null if disabled/unavailable.
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
     * @return void
     * @throws \RedisException If Redis interaction fails.
     */
    public function delete(string $pattern): void
    {
        if (!$this->enabled || !$this->redis) {
            return;
        }

        try {
            $cursor = null;
            do {
                $response = $this->redis->scan($cursor, [
                    'match' => "*$pattern*",
                    'count' => $this->scanCount,
                ]);

                if ($response === false) {
                    break;
                }

                [$cursor, $results] = $response;
                $results = array_map(fn ($key) => Str::replace($this->prefix, '', (string) $key), $results);
                if (!empty($results)) {
                    $this->redis->del($results);
                }
            } while ($cursor !== 0 && $cursor !== null);
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }


    /**
     * Flush all Redis cache keys.
     *
     * When `$onlyPrefixed` is true, only keys beginning with the configured
     * prefix will be deleted. Otherwise, all keys in the current Redis
     * database are removed.
     *
     * @param bool $onlyPrefixed Whether to restrict deletion to keys starting with the configured prefix.
     * @return void
     * @throws \RedisException If Redis interaction fails.
     */
    public function flushAll(bool $onlyPrefixed = true): void
    {
        if (!$this->enabled || !$this->redis) {
            return;
        }

        try {
            $cursor = null;
            $pattern = $onlyPrefixed ? "{$this->prefix}*" : "*";

            do {
                $response = $this->redis->scan($cursor, [
                    'match' => $pattern,
                    'count' => $this->scanCount,
                ]);

                if ($response === false) {
                    break;
                }

                [$cursor, $keys] = $response;
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
            } while ($cursor !== 0 && $cursor !== null);
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }

    /**
     * Listen to database write operations and automatically invalidate
     * related Redis cache entries.
     *
     * This method hooks into Laravel's DB::listen() and watches for
     * INSERT, UPDATE, or DELETE queries. Detected tables are used to
     * clear associated cache entries.
     *
     * @return void
     */
    public function listenToWriteQueries(): void
    {
        if (!$this->enabled || !$this->redis) {
            return;
        }

        try {
            DB::listen(function ($query) {
                if ($table = RedisCacheUtils::getAffectedTables($query->sql)) {
                    foreach ($table as $t) {
                        $this->delete(":$t:");
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }
}
