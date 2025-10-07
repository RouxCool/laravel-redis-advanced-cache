<?php

namespace RedisAdvancedCache\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function getRedis(): ?\Redis
    {
        return $this->redis;
    }

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

    public function listenToWriteQueries(): void
    {
        if (!$this->enabled || !$this->redis) {
            return;
        }

        try {
            DB::listen(function ($query) {
                if ($table = $this->getAffectedTables($query->sql)) {
                    foreach ($table as $t) {
                        $this->delete(":$t:");
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }

    private function getAffectedTables(string $sql): array
    {
        if (!($operation = \RedisAdvancedCache\Utils\RedisCacheUtils::detectWriteOperation($sql))) {
            return [];
        }

        $relations = \RedisAdvancedCache\Utils\RedisCacheUtils::extractRelationsFromSQL($sql);
        $mainTable = \RedisAdvancedCache\Utils\RedisCacheUtils::getMainTable($sql);

        if ($mainTable) {
            $relations[] = $mainTable;
        }

        return array_unique($relations);
    }
}
