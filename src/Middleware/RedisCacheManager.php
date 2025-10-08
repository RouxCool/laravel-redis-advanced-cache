<?php

namespace RedisAdvancedCache\Middleware;

use Carbon\Carbon;
use Illuminate\Http\Request;
use RedisAdvancedCache\Services\RedisCacheService;
use RedisAdvancedCache\Utils\RedisCacheUtils;

class RedisCacheManager
{
    protected ?\Redis $redis = null;
    protected bool $enabled;
    protected bool $debug;
    protected string $prefix;
    protected string $appName;
    protected string $appUuid;
    protected string|int $userId;
    protected int $ttl;
    protected array $whitelist;
    protected array $blacklist;

    public function __construct()
    {
        $this->enabled = (bool) config('redis_advanced_cache.enabled', true);
        $this->debug = (bool) config('redis_advanced_cache.debug', false);
        $this->prefix = config('redis_advanced_cache.key_identifier.prefix', 'cache_');
        $this->appName = config('redis_advanced_cache.key_identifier.name', 'myapp');
        $this->appUuid = config('redis_advanced_cache.key_identifier.uuid', 'uuid');
        $this->ttl = (int) config('redis_advanced_cache.options.ttl', 86400);
        $this->userId = auth()->check() ? auth()->id() : 'guest';

        $this->whitelist = config('redis_advanced_cache.whitelists', []);
        $this->blacklist = config('redis_advanced_cache.blacklists', []);

        if ($this->debug) {
            \Log::debug('========================================');
            \Log::debug('           RedisCacheManager');
            \Log::debug('');
        }

        $this->redis = (new RedisCacheService())->getRedis();

        if ($this->redis && $this->debug) {
            \Log::debug('[RedisCacheManager] ✅ Redis connection retrieved from RedisCacheService.');
        } elseif ($this->debug) {
            \Log::warning('[RedisCacheManager] ❌ Redis instance unavailable (disabled or connection failed).');
        }
    }

    public function handle(Request $request, \Closure $next)
    {
        $path = $request->path();
        if ($this->debug) \Log::debug('[RedisCacheManager] ❓ Route target : ' . $path);
        if ($this->enabled && $this->redis) {
            try {
                if (($this->blacklist['enabled'] ?? false) && !empty($this->blacklist['routes'])) {
                    foreach ($this->blacklist['routes'] as $pattern) {
                        if (RedisCacheUtils::matchPattern($pattern, $path)) {
                            if ($this->debug) \Log::debug('[RedisCacheManager] ❗ Route blacklisted → '.$path);
                            if ($this->debug) \Log::debug('[RedisCacheManager] ❗ Cancel caching');
                            return $next($request);
                        }
                    }
                }

                $forceCache = false;
                if (($this->whitelist['enabled'] ?? false) && !empty($this->whitelist['routes'])) {
                    foreach ($this->whitelist['routes'] as $pattern) {
                        if (RedisCacheUtils::matchPattern($pattern, $path)) {
                            if ($this->debug) \Log::debug('[RedisCacheManager] ✅ Route whitelisted → '.$path);
                            $forceCache = true;
                            break;
                        }
                    }
                }

                $cachable = false;
                $tablePath = null;

                if (RedisCacheUtils::isRouteManagedByRestApi($request)) {
                    $cachable = RedisCacheUtils::isCacheableRestApi($request);
                    if ($this->debug) \Log::debug('[RedisCacheManager] Route managed by REST API → cachable='.$cachable);
                } elseif (RedisCacheUtils::isRouteManagedByOrion($request)) {
                    $cachable = RedisCacheUtils::isCacheableOrion($request);
                    if ($this->debug) \Log::debug('[RedisCacheManager] Route managed by Orion API → cachable='.$cachable);
                }

                if ($forceCache) {
                    $cachable = true;
                    if ($this->debug) \Log::debug('[RedisCacheManager] Force caching enabled for route → '.$path);
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
                            (new RedisCacheService())->delete(':'.$updateCacheKey.':');
                            if ($this->debug) \Log::debug('[RedisCacheManager] Cache updated for key → '.$updateCacheKey);
                        }
                    }

                    if ($this->redis->exists($keyCache) && !$noCache) {
                        $cached = json_decode($this->redis->get($keyCache), true);
                        $cached['cache']['cached'] = true;
                        if ($this->debug) \Log::debug('[RedisCacheManager] Returning cached response → '.$keyCache);

                        return response()->json($cached);
                    }

                    $response = $next($request);
                    if ($response->getStatusCode() === 200) {
                        $content = json_decode($response->getContent(), true);
                        if ($content) {
                            $content['cache']['dateStored'] = Carbon::now()->toDateTimeString();
                            $content['cache']['pattern'] = config('redis_advanced_cache.pattern');
                            $content['cache']['key']['cache'] = $this->prefix.$keyCache;
                            $content['cache']['key']['localstorage'] = $this->prefix.$keyCache;

                            $this->redis->setex($keyCache, $this->ttl, json_encode($content));

                            if ($this->debug) \Log::debug('[RedisCacheManager] Response cached successfully → '.$keyCache);

                            return response()->json($content);
                        }
                    }

                    return $response;
                }
            } catch (\Throwable $e) {
                if ($this->debug) \Log::error('[RedisCacheManager] ❌ Error handling cache for request '.$request->path().' → '.$e->getMessage());
            }
        }

        if ($this->debug) \Log::debug('[RedisCacheManager] ❗ Cancel caching');
        return $next($request);
    }
}
