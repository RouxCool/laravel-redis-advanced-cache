<?php

namespace RedisAdvancedCache\Middleware;

use Carbon\Carbon;
use Illuminate\Http\Request;
use RedisAdvancedCache\Services\RedisCacheService;
use RedisAdvancedCache\Utils\RedisCacheUtils;

class RedisCacheManager
{
    protected RedisCacheService $cacheService;
    protected ?\Redis $redis;
    protected bool $enabled;
    protected bool $debug;
    protected string $prefix;
    protected string $pattern;
    protected string|int $userId;
    protected int $ttl;
    protected array $whitelist;
    protected array $blacklist;

    /**
     * RedisCacheManager constructor.
     *
     * @param RedisCacheService $cacheService
     */
    public function __construct(RedisCacheService $cacheService)
    {
        if ($this->debug) {
            \Log::debug('========================================');
            \Log::debug('           RedisCacheManager');
            \Log::debug('');
        }

        $this->cacheService = $cacheService;
        $this->redis = $this->cacheService->getRedis();
        $this->enabled = (bool) config('redis_advanced_cache.enabled', true);
        $this->debug = (bool) config('redis_advanced_cache.debug', false);
        $this->prefix = config('redis_advanced_cache.key_identifier.prefix', 'cache_');
        $this->pattern = config('redis_advanced_cache.pattern', 'default');
        $this->ttl = (int) config('redis_advanced_cache.options.ttl', 86400);
        $this->userId = auth()->check() ? auth()->id() : 'guest';

        $this->whitelist = config('redis_advanced_cache.whitelists', []);
        $this->blacklist = config('redis_advanced_cache.blacklists', []);
    }

    /**
     * Check if a route is blacklisted.
     *
     * @param string $path
     * @return bool
     */
    protected function isBlacklisted(string $path): bool
    {
        if (!($this->blacklist['enabled'] ?? false)) return false;

        foreach ($this->blacklist['routes'] ?? [] as $pattern) {
            if (RedisCacheUtils::matchPattern($pattern, $path)) return true;
        }
        return false;
    }

    /**
     * Check if a route is whitelisted.
     *
     * @param string $path
     * @return bool
     */
    protected function isWhitelisted(string $path): bool
    {
        if (!($this->whitelist['enabled'] ?? false)) return false;

        foreach ($this->whitelist['routes'] ?? [] as $pattern) {
            if (RedisCacheUtils::matchPattern($pattern, $path)) return true;
        }
        return false;
    }

    /**
     * Determine if the request is cacheable.
     *
     * @param Request $request
     * @return bool
     */
    protected function isCachable(Request $request): bool
    {
        if (RedisCacheUtils::isRouteManagedByRestApi($request)) {
            return RedisCacheUtils::isCacheableRestApi($request);
        }
        if (RedisCacheUtils::isRouteManagedByOrion($request)) {
            return RedisCacheUtils::isCacheableOrion($request);
        }
        return false;
    }

    /**
     * Update specific cache keys if requested in the request.
     *
     * @param Request $request
     * @return void
     */
    protected function updateCacheKeys(Request $request): void
    {
        $updateCache = $request->input('cache.updateCache') ?? $request->query('updateCache');
        if (!is_array($updateCache)) return;

        foreach ($updateCache as $key) {
            $this->cacheService->delete(":$key:");
            $this->logDebug("[RedisCacheManager] ✅ Cache updated for key → $key");
        }
    }

    /**
     * Store response data in Redis cache.
     *
     * @param string $keyCache
     * @param mixed $response
     * @return void
     * @throws \Exception
     */
    protected function storeResponseInCache(string $keyCache, $response): void
    {
        $content = json_decode($response->getContent(), true);
        if (!$content) return;

        $content['cache']['pattern'] = $this->pattern === 'default'
            ? '@PREFIX:@UUID:@NAME:$PATH:$METHOD:$USER_ID:$BODY_INPUT:$QUERY_INPUT'
            : $this->pattern;
        $content['cache']['date_stored'] = Carbon::now()->toDateTimeString();
        $content['cache']['key']['cache'] = $this->prefix.$keyCache;
        $content['cache']['key']['localstorage'] = $this->prefix.$keyCache;

        $this->redis->setex($keyCache, $this->ttl, json_encode($content));
        $this->logDebug("[RedisCacheManager] ✅ Response cached successfully → $keyCache");
    }

    /**
     * Log a debug message if debug mode is enabled.
     *
     * @param string $message
     * @return void
     */
    protected function logDebug(string $message): void
    {
        if ($this->debug) \Log::debug($message);
    }

    /**
     * Log an error message if debug mode is enabled.
     *
     * @param string $message
     * @return void
     */
    protected function logError(string $message): void
    {
        if ($this->debug) \Log::error($message);
    }

    /**
     * Handle the incoming request and apply caching logic.
     *
     * @param Request $request
     * @param \Closure $next
     * @return mixed
     * @throws \Exception
     */
    public function handle(Request $request, \Closure $next)
    {
        $path = $request->path();
        $this->logDebug('[RedisCacheManager] ❓ Route target : ' . $path);
        if (!$this->enabled || !$this->redis) {
            $this->logDebug('[RedisCacheManager] ❗ Skipping cache for '.$path);
            return $next($request);
        }

        try {
            if ($this->isBlacklisted($path)) {
                $this->logDebug("[RedisCacheManager] ❗ Route blacklisted → $path");
                return $next($request);
            }

            $forceCache = $this->isWhitelisted($path);
            if ($forceCache) {
                $this->logDebug("[RedisCacheManager] ✅ Route whitelisted → $path");
            }

            $cachable = $this->isCachable($request) || $forceCache;
            if (!$cachable) return $next($request);

            $tablePath = RedisCacheUtils::resolveMainTable($request);
            if (!$tablePath) return $next($request);

            $keyCache = RedisCacheUtils::generateCacheKey(
                $tablePath,
                $request->method(),
                $this->userId,
                $request->input(),
                $request->query()
            );

            $this->updateCacheKeys($request);

            if ($this->redis->exists($keyCache) && !$request->input('cache.noCache')) {
                $cached = json_decode($this->redis->get($keyCache), true);
                $this->logDebug("[RedisCacheManager] ✅ Returning cached response → $keyCache");
                return response()->json($cached);
            }

            $response = $next($request);
            if ($response->getStatusCode() === 200) {
                $this->storeResponseInCache($keyCache, $response);
            }

            return $response;

        } catch (\Throwable $e) {
            $this->logError("[RedisCacheManager] ❌ Error handling cache for $path → " . $e->getMessage());
            return $next($request);
        }
    }
}
