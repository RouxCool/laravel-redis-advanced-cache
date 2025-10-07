<?php

namespace RedisAdvancedCache\Services;

use App\Utils\Cache\RedisCacheUtils;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RedisCacheService
{
    protected ?Factory $redis;
    protected string $prefix;
    protected bool $enabled;
    protected int $count;

    public function __construct()
    {
        $this->prefix = config('database.redis.options.prefix');
        $this->enabled = (bool) config('database.redis.advanced_cache.enabled');
        $this->count = 300;
        self::initRedis();
    }

    private function initRedis(): void
    {
        if ($this->enabled) {
            try {
                $this->redis = Cache::store('redis')->getRedis();
                $this->redis->select(1);
            } catch (\Throwable $e) {
                $this->redis = null;
            }
        }
    }

    public function delete(string $pattern): void
    {
        if ($this->enabled && isset($this->redis)) {
            try {
                $cursor = null;
                do {
                    $response = $this->redis->scan($cursor, [
                        'match' => "*$pattern*",
                        'count' => $this->count,
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
    }

    public function listenToWriteQueries(): void
    {
        if ($this->enabled && isset($this->redis)) {
            try {
                DB::listen(function ($query) {
                    if (RedisCacheUtils::detectWriteOperation($query->sql) && RedisCacheUtils::filterSQL($query->sql)) {
                        $relations = RedisCacheUtils::extractRelationsFromSQL($query->sql);
                        $mainTable = RedisCacheUtils::getMainTable($query->sql);
                        $mainTable = Str::replace('-', '_', $mainTable);

                        if (is_array($relations)) {
                            foreach ($relations as $table) {
                                $this->delete(":$table:");
                            }
                        }

                        if ($mainTable) {
                            $this->delete(":$mainTable:");
                        }
                    }
                });
            } catch (\Throwable $e) {
                $this->redis = null;
            }
        }
    }
}
