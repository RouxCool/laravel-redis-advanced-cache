<?php

namespace RedisAdvancedCache\Middleware;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    protected array $whitelist;
    protected array $blacklist;

    /**
     * Class constructor.
     *
     * Initializes Redis cache configuration and establishes a connection
     * using environment variables or configuration file values.
     */
    public function __construct()
    {
        $this->enabled = (bool) config('redis_advanced_cache.enabled', true);
        $this->prefix = config('redis_advanced_cache.key.identifier.prefix', 'cache_');
        $this->appName = config('redis_advanced_cache.key.identifier.name', 'myapp');
        $this->appUuid = config('redis_advanced_cache.key.identifier.uuid', 'uuid');
        $this->ttl = (int) config('redis_advanced_cache.options.ttl', 86400);
        $this->userId = auth()->check() ? auth()->id() : 'guest';

        $this->whitelist = config('redis_advanced_cache.whitelists', []);
        $this->blacklist = config('redis_advanced_cache.blacklists', []);

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
        if (!$this->enabled) return;

        try {
            $this->redis = Cache::store('redis')->getRedis();
            $this->redis->select((int) config('redis_advanced_cache.connection.database', 1));
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }

    /**
     * Handle the request and apply caching logic.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return \Illuminate\Http\Response
     */
    public function handle(Request $request, \Closure $next)
    {
        if (!$this->enabled || !$this->redis) {
            return $next($request);
        }

        try {
            $path = $request->path();

            if (($this->blacklist['enabled'] ?? false) && !empty($this->blacklist['routes'])) {
                foreach ($this->blacklist['routes'] as $pattern) {
                    if (RedisCacheUtils::matchPattern($pattern, $path)) {
                        \Log::info('RedisCacheManager: Route blacklisted → ' . $path);
                        return $next($request);
                    }
                }
            }

            $forceCache = false;
            if (($this->whitelist['enabled'] ?? false) && !empty($this->whitelist['routes'])) {
                foreach ($this->whitelist['routes'] as $pattern) {
                    if (RedisCacheUtils::matchPattern($pattern, $path)) {
                        \Log::info('RedisCacheManager: Route whitelisted → ' . $path);
                        $forceCache = true;
                        break;
                    }
                }
            }

            $cachable = false;
            $tablePath = null;

            if (RedisCacheUtils::isRouteManagedByRestApi($request)) {
                $cachable = RedisCacheUtils::isCacheableRestApi($request);
            } elseif (RedisCacheUtils::isRouteManagedByOrion($request)) {
                $cachable = RedisCacheUtils::isCacheableOrion($request);
            }

            if ($forceCache) {
                $cachable = true;
            }

            if ($cachable && $tablePath = RedisCacheUtils::resolveMainTable($request)) {
                $noCache = $request->input('cache.noCache') ?? $request->query('noCache') ?? false;
                $updateCache = $request->input('cache.updateCache') ?? $request->query('updateCache') ?? false;

                $keyCache = RedisCacheUtils::generateCacheKey(
                    $tablePath,
                    $request->method(),
                    $this->userId ?? null,
                    $request->input(),
                    $request->query()
                );

                if ($updateCache && is_array($updateCache)) {
                    foreach ($updateCache as $updateCacheKey) {
                        (new RedisCacheService())->delete(':' . $updateCacheKey . ':');
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
                        $content['cache']['key']['cache'] = $this->prefix . $keyCache;
                        $content['cache']['key']['localstorage'] = $this->prefix . $keyCache;

                        $this->redis->setex($keyCache, $this->ttl, json_encode($content));

                        return response()->json($content);
                    }
                }

                return $response;
            }

        } catch (\Throwable $e) {
            // silently fail
            \Log::error('RedisCacheManager error: ' . $e->getMessage());
        }

        return $next($request);
    }
}
