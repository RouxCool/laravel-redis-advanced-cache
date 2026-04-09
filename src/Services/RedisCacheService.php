<?php

namespace RedisAdvancedCache\Services;

use Illuminate\Support\Facades\DB;
use RedisAdvancedCache\Utils\RedisCacheUtils;

class RedisCacheService
{
    protected $redis = null;
    protected string $prefix;
    protected string $pattern;
    protected bool $debug;
    protected bool $enabled;
    protected int $scanCount;
    protected int $ttl;
    protected string $host;
    protected int $port;
    protected ?string $username;
    protected ?string $password;
    protected int $db;
    protected string $scheme;
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
        $this->enabled = (bool) config('redis-advanced-cache.enabled', true);
        $this->debug = (bool) config('redis-advanced-cache.debug', false);
        $this->prefix = config('redis-advanced-cache.key_identifier.prefix', 'cache_');
        $this->scanCount = (int) config('redis-advanced-cache.options.cache_flush_scan_count', 300);
        $this->ttl = (int) config('redis-advanced-cache.options.ttl', 86400);
        $this->pattern = config('redis-advanced-cache.pattern', 'default');
        $this->listenEnabled = (bool) config('redis-advanced-cache.listen_queries', true);

        $conn = config('redis-advanced-cache.connection');
        $this->host = $conn['host'] ?? '127.0.0.1';
        $this->port = $conn['port'] ?? 6379;
        $this->username = $conn['username'] ?? null;
        $this->password = $conn['password'] ?? null;
        $this->db = $conn['database'] ?? 1;
        $this->scheme = $conn['scheme'] ?? 'tcp';

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
            RedisCacheUtils::logWarning('[RedisCacheService] ❗ Redis cache is disabled.');
            return;
        }

        try {
            if (class_exists(\Redis::class)) {
                $this->redis = new \Redis();
                $this->redis->pconnect($this->host, $this->port);

                if ($this->username) {
                    $this->redis->auth([$this->username, (string) $this->password]);
                } elseif ($this->password) {
                    $this->redis->auth($this->password);
                }

                $this->redis->select($this->db);
                RedisCacheUtils::logDebug('[RedisCacheService] ✅ Redis connection established successfully with phpredis.');
                return;
            }

            if (class_exists(\Predis\Client::class)) {
                $parameters = [
                    'scheme' => $this->scheme,
                    'host' => $this->host,
                    'port' => $this->port,
                    'database' => $this->db,
                ];

                if ($this->username) {
                    $parameters['username'] = $this->username;
                }

                if ($this->password) {
                    $parameters['password'] = $this->password;
                }

                $this->redis = new \Predis\Client($parameters);
                $this->redis->connect();
                $this->redis->ping();

                RedisCacheUtils::logDebug('[RedisCacheService] ✅ Redis connection established successfully with predis.');
                return;
            }

            throw new \RuntimeException('Neither the phpredis extension nor Predis is available.');
        } catch (\Throwable $e) {
            $this->redis = null;
            RedisCacheUtils::logError('[RedisCacheService] ❌ Redis connection failed: '.$e->getMessage());
        }
    }

    private function isPredisClient(): bool
    {
        return $this->redis instanceof \Predis\Client;
    }

    private function scanKeys(?int &$cursor, string $pattern): array|false
    {
        if ($this->isPredisClient()) {
            [$nextCursor, $keys] = $this->redis->scan((string) ($cursor ?? 0), [
                'MATCH' => $pattern,
                'COUNT' => $this->scanCount,
            ]);

            $cursor = (int) $nextCursor;

            return array_map(fn ($key) => (string) $key, $keys ?? []);
        }

        $keys = $this->redis->scan($cursor, $pattern, $this->scanCount);

        if ($keys === false) {
            return false;
        }

        return array_map(fn ($key) => (string) $key, $keys);
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
    public function getRedis(): mixed
    {
        return $this->redis;
    }

    /**
     * Retrieve cached data from Redis based on a key or request.
     *
     * If the key is not explicitly provided, it will be generated automatically
     * using RedisCacheUtils::generateCacheKey() based on the request context.
     *
     * This method can also return a list of keys matching the given pattern
     * (useful for debugging or admin inspection).
     *
     * @param \Illuminate\Http\Request|null $request  The current HTTP request (optional).
     * @param string|null $key  The Redis key or partial key pattern.
     * @return array|null  Returns an associative array of key => value, or null if not found.
     */
    /**
     * Retrieve cached data from Redis based on a key or request.
     *
     * If the key is not explicitly provided, it will be automatically resolved
     * using RedisCacheUtils::resolveMainTable() from the request context.
     *
     * This version uses the Redis "KEYS" command for direct retrieval — faster
     * for debugging or administrative queries, but not recommended for very large datasets.
     *
     * @param \Illuminate\Http\Request|null $request
     * @param string|null $key
     * @return array|null  Returns an associative array of key => value, or null if not found.
     */
    public function get(?\Illuminate\Http\Request $request = null, ?string $key = null): ?array
    {
        if (!$this->enabled || !$this->redis) {
            RedisCacheUtils::logWarning('[RedisCacheService] ❗ Cannot get cache key — Redis disabled or disconnected.');
            return null;
        }

        try {
            if (!$key && $request) {
                $key = RedisCacheUtils::resolveMainTable($request);
                if (!$key) {
                    RedisCacheUtils::logWarning("[RedisCacheService] ❗ Resolve model not found → " . $request->route()?->getActionName());
                    return null;
                }

                RedisCacheUtils::logDebug("[RedisCacheService] 🧩 Search pattern cache key: {$key}");
            }

            $keys = $this->redis->keys("*{$key}*");
            $keys = array_map(fn($cacheKey) => (string) $cacheKey, $keys);

            if (empty($keys)) {
                RedisCacheUtils::logWarning("[RedisCacheService] ⚠️ No cache entries found for key pattern: {$key}");
                return null;
            }

            RedisCacheUtils::logDebug("[RedisCacheService] ✅ Found " . count($keys) . " cache keys for pattern: {$key}");
            RedisCacheUtils::logDebug("[RedisCacheService] ✅ Found keys : " . json_encode($keys));
            return $keys;

        } catch (\Throwable $e) {
            RedisCacheUtils::logError('[RedisCacheService] ❌ Error retrieving cache keys for pattern ' . $key . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Store a value in Redis with a given key and optional TTL.
     *
     * If the value n'est pas une chaîne, il sera automatiquement encodé en JSON.
     * Si `$ttl` n'est pas fourni, la durée par défaut du cache sera utilisée.
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl
     * @return bool True si l’écriture a réussi, false sinon.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->enabled || !$this->redis) {
            RedisCacheUtils::logWarning('[RedisCacheService] ❗ Cannot set cache key — Redis disabled or disconnected.');
            return false;
        }

        try {
            $ttl ??= $this->ttl;
            $storedValue = is_string($value) ? $value : json_encode($value);

            $this->redis->setex($key, $ttl, $storedValue);

            RedisCacheUtils::logDebug("[RedisCacheService] ✅ Set cache key: {$key} (TTL: {$ttl}s)");

            return true;
        } catch (\Throwable $e) {
            $this->redis = null;
            RedisCacheUtils::logError("[RedisCacheService] ❌ Failed to set cache key {$key}: " . $e->getMessage());
            return false;
        }
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
        if (!$this->enabled || !$this->redis) {
            RedisCacheUtils::logWarning('[RedisCacheService] ❗ Write query listening is disabled or disconnected.');
            return;
        }

        try {
            $cursor = null;
            do {
                $results = $this->scanKeys($cursor, "*$pattern*");

                if ($results === false) break;

                if (!empty($results)) {
                    $this->redis->del($results);
                    RedisCacheUtils::logDebug('[RedisCacheService] ✅ Deleted cache keys: ' . implode(', ', $results));
                }
            } while ($cursor !== 0 && $cursor !== null);
        } catch (\Throwable $e) {
            $this->redis = null;
            RedisCacheUtils::logError('[RedisCacheService] ❌ Error deleting keys with pattern ' . $pattern . ': ' . $e->getMessage());
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
        if (!$this->enabled || !$this->redis) {
            RedisCacheUtils::logWarning('[RedisCacheService] ❗ Write query listening is disabled or disconnected.');
            return;
        }

        try {
            $cursor = null;
            $pattern = $onlyPrefixed ? "{$this->prefix}*" : '*';

            do {
                $keys = $this->scanKeys($cursor, $pattern);

                if ($keys === false) break;

                if (!empty($keys)) {
                    $this->redis->del($keys);
                    RedisCacheUtils::logDebug('[RedisCacheService] ✅ Flushed cache keys: ' . implode(', ', $keys));
                }
            } while ($cursor !== 0 && $cursor !== null);

            RedisCacheUtils::logDebug('[RedisCacheService] 🧹 Redis cache flush completed for pattern: ' . $pattern);
        } catch (\Throwable $e) {
            $this->redis = null;
            RedisCacheUtils::logError('[RedisCacheService] ❌ Error flushing Redis cache: ' . $e->getMessage());
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
            RedisCacheUtils::logWarning('[RedisCacheService] ❗ Write query listening is disabled or disconnected.');
            return;
        }

        try {
            DB::listen(function ($query) {
                if (RedisCacheUtils::detectWriteOperation($query->sql)) {
                    $flushables = RedisCacheUtils::getFlushableTablesFromSql($query->sql);
                    $mainTable = RedisCacheUtils::getMainTable($query->sql);
                    if ($mainTable) {
                        $this->delete(":$mainTable:");
                        RedisCacheUtils::logDebug('[RedisCacheService] ✅ Cache invalidated main table key: *:'.$mainTable.':*');
                    }
                    foreach ($flushables as $flushKey) {
                        $this->delete(":$flushKey:");
                        RedisCacheUtils::logDebug('[RedisCacheService] ✅ Cache invalidated relations keys: *:'.$flushKey.':*');
                    }
                }
            });

            RedisCacheUtils::logDebug('[RedisCacheService] ❓ Listening to database write queries for cache invalidation.');
        } catch (\Throwable $e) {
            $this->redis = null;
            RedisCacheUtils::logError('[RedisCacheService] ❌ Error listening to write queries: '.$e->getMessage());
        }
    }
}
