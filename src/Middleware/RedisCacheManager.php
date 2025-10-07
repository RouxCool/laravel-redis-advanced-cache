<?php

namespace RedisAdvancedCache\Middleware;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RedisAdvancedCache\Services\RedisCacheService;
use RedisAdvancedCache\Utils\RedisCacheUtils;

class RedisCacheManager
{
    protected ?\Illuminate\Contracts\Redis\Factory $redis = null;
    protected bool $enabled;
    protected string $prefix;
    protected string $appName;
    protected string $appUuid;
    protected string|int $userId;
    protected int $ttl;

    public function __construct()
    {
        $this->enabled = (bool) config('redis_advanced_cache.enabled', true);
        $this->prefix = config('redis_advanced_cache.key.identifier.prefix', 'cache_');
        $this->appName = config('redis_advanced_cache.key.identifier.name', 'myapp');
        $this->appUuid = config('redis_advanced_cache.key.identifier.uuid', 'uuid');
        $this->ttl = (int) config('redis_advanced_cache.options.ttl', 86400);
        $this->userId = auth()->check() ? auth()->id() : 'guest';

        $this->initRedis();
    }

    private function initRedis(): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->redis = Cache::store('redis')->getRedis();
            $this->redis->select((int) config('redis_advanced_cache.connection.database', 1));
        } catch (\Throwable $e) {
            $this->redis = null;
            Log::error('Redis Cache Manager failed to connect to Redis: '.$e->getMessage());
        }
    }

    public function handle(Request $request, \Closure $next)
    {
        if (!$this->enabled || !$this->redis) {
            return $next($request);
        }

        try {
            $cachable = false;
            $path = null;

            if (RedisCacheUtils::isRouteManagedByRestApi($request)) {
                $cachable = RedisCacheUtils::isCacheableRestApi($request);
            } elseif (RedisCacheUtils::isRouteManagedByOrion($request)) {
                $cachable = RedisCacheUtils::isCacheableOrion($request);
            }

            if ($cachable && $path = RedisCacheUtils::resolveMainTable($request)) {
                $noCache = $request->input('cache.noCache') ?? $request->query('noCache') ?? false;
                $updateCache = $request->input('cache.updateCache') ?? $request->query('updateCache') ?? false;

                $keyCache = RedisCacheUtils::generateCacheKey(
                    $path,
                    $request->method(),
                    $this->userId || null,
                    $request->input(),
                    $request->query()
                );

                if ($updateCache && is_array($updateCache)) {
                    foreach ($updateCache as $updateCacheKey) {
                        (new RedisCacheService())->delete(':'.$updateCacheKey.':');
                    }
                }

                if ($this->redis->exists($keyCache) && !$noCache) {
                    $cached = json_decode($this->redis->get($keyCache), true);
                    $cached['cache']['cached'] = true;

                    return response()->json($cached);
                }

                $response = $next($request);
                if ($response->getStatusCode() === 200) {
                    $content = json_decode($response->getContent(), true);
                    if ($content) {
                        $content['cache']['dateStored'] = Carbon::now()->toDateTimeString();
                        $content['cache']['pattern'] = config('redis_advanced_cache.pattern');
                        $content['cache']['key']['cache'] = $this->prefix.$keyCache;

                        $this->redis->setex($keyCache, $this->ttl, json_encode($content));

                        return response()->json($content);
                    }
                }

                return $response;
            }
        } catch (\Throwable $e) {
            // silently fail
        }

        return $next($request);
    }
}
