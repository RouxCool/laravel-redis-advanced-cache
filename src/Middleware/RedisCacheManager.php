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
    protected bool $listenEnabled;
    protected string $prefix;
    protected string $pattern;
    protected string|int $userId;
    protected int $ttl;
    protected array $whitelist;
    protected array $blacklist;

    /**
     * RedisCacheManager constructor.
     *
     * Initializes Redis cache configuration and establishes a connection
     * using environment variables or configuration file values.
     *
     * @param RedisCacheService $cacheService
     */
    public function __construct()
    {
        $this->cacheService = RedisCacheService::getInstance();
        $this->redis = $this->cacheService->getRedis();

        $this->enabled = (bool) config('redis-advanced-cache.enabled', true);
        $this->debug = (bool) config('redis-advanced-cache.debug', false);
        $this->listenEnabled = (bool) config('redis-advanced-cache.listen_queries', true);
        $this->prefix = config('redis-advanced-cache.key_identifier.prefix', 'cache_');
        $this->pattern = config('redis-advanced-cache.pattern', 'default');
        $this->ttl = (int) config('redis-advanced-cache.options.ttl', 86400);
        $this->userId = auth()->check() ? auth()->id() : 'guest';
        $this->whitelist = config('redis-advanced-cache.whitelists', []);
        $this->blacklist = config('redis-advanced-cache.blacklists', []);
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
        $content['cache']['key']['cache'] = $keyCache;
        $content['cache']['key']['localstorage'] = md5($keyCache);

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
            if (RedisCacheUtils::isBlacklisted($path)) {
                $this->logDebug("[RedisCacheManager] ❗ Route blacklisted → $path");
                return $next($request);
            }

            $forceCache = RedisCacheUtils::isWhitelisted($path);
            if ($forceCache) {
                $this->logDebug("[RedisCacheManager] ✅ Route whitelisted → $path");
            }

            if ($this->listenEnabled) {
                $this->cacheService->listenToWriteQueries();
            } else {
                $this->logDebug("[RedisCacheManager] ❗ Write query listening is disabled.");
            }

            $cachable = RedisCacheUtils::isCachable($request) || $forceCache;
            if (!$cachable) {
                $this->logDebug("[RedisCacheManager] ❗ Route isn't cachable → " . $path);
                return $next($request);
            }

            $tablePath = RedisCacheUtils::resolveMainTable($request);
            if (!$tablePath) {
                $this->logDebug("[RedisCacheManager] ❗ Resolve model not found → " . $request->route()?->getActionName());
                return $next($request);
            }

            $keyCache = RedisCacheUtils::generateCacheKey([
                'path' => $tablePath,
                'method' => $request->method(),
                'user_id' => $this->userId,
                'body_input' => $request->input(),
                'query_input' => $request->query(),
            ]);

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
