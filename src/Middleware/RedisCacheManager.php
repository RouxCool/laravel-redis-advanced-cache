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

        $this->whitelist = config('redis_advanced_cache.routes.whitelists', []);
        $this->blacklist = config('redis_advanced_cache.routes.blacklists', []);

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

    public function handle(Request $request, \Closure $next)
    {
        if (!$this->enabled || !$this->redis) return $next($request);

        try {
            $path = $request->path();

            // Check blacklist first
            foreach ($this->blacklist as $pattern) {
                if (RedisCacheUtils::matchPattern($pattern, $path)) {
                    \Log::info(1);
                    return $next($request);
                }
            }

            // Check whitelist
            $forceCache = false;
            foreach ($this->whitelist as $pattern) {
                if (RedisCacheUtils::matchPattern($pattern, $path)) {
                    \Log::info(2);
                    $forceCache = true;
                    break;
                }
            }

            // Determine if route is normally cachable
            $cachable = false;
            $tablePath = null;

            if (RedisCacheUtils::isRouteManagedByRestApi($request)) {
                $cachable = RedisCacheUtils::isCacheableRestApi($request);
            } elseif (RedisCacheUtils::isRouteManagedByOrion($request)) {
                $cachable = RedisCacheUtils::isCacheableOrion($request);
            }

            // Force caching if whitelisted
            if ($forceCache) {
                $cachable = true;
            }
            \Log::info(3);

            if ($cachable && $tablePath = RedisCacheUtils::resolveMainTable($request)) {
                \Log::info(4);
                $noCache = $request->input('cache.noCache') ?? $request->query('noCache') ?? false;
                \Log::info(4.1);
                $updateCache = $request->input('cache.updateCache') ?? $request->query('updateCache') ?? false;
                \Log::info(4.2);

                $keyCache = RedisCacheUtils::generateCacheKey(
                    $tablePath,
                    $request->method(),
                    $this->userId ?? null,
                    $request->input(),
                    $request->query()
                );
                \Log::info(4.3);

                if ($updateCache && is_array($updateCache)) {
                    foreach ($updateCache as $updateCacheKey) {
                        (new RedisCacheService())->delete(':'.$updateCacheKey.':');
                    }
                }

                if ($this->redis->exists($keyCache) && !$noCache) {
                    \Log::info(5);
                    $cached = json_decode($this->redis->get($keyCache), true);
                    $cached['cache']['cached'] = true;

                    return response()->json($cached);
                }

                $response = $next($request);
                if ($response->getStatusCode() === 200) {
                    $content = json_decode($response->getContent(), true);
                    if ($content) {
                        \Log::info(6);
                        $content['cache']['dateStored'] = Carbon::now()->toDateTimeString();
                        $content['cache']['pattern'] = config('redis_advanced_cache.pattern');
                        $content['cache']['key']['cache'] = $this->prefix.$keyCache;
                        $content['cache']['key']['localstorage'] = $this->prefix.$keyCache;

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
