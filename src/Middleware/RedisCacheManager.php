<?php

namespace RedisAdvancedCache\Middleware;

use App\Utils\Cache\RedisCacheUtils;
use Carbon\Carbon;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RedisCacheManager
{
    protected ?Factory $redis;
    protected string $pattern;
    protected string $appName;
    protected string $appUuid;
    protected bool $enabled;
    protected int|string $userId;

    public function __construct()
    {
        $this->enabled = (bool) config('database.redis.advanced_cache.enabled');
        $this->pattern = config('database.redis.advanced_cache.pattern');
        $this->prefix = config('database.redis.options.prefix');
        $this->appName = config('database.redis.advanced_cache.name');
        $this->appUuid = config('database.redis.advanced_cache.uuid');
        $this->userId = auth()->check() ? auth()->id() : '';
        $this->initRedis();
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

    public function handle(Request $request, \Closure $next)
    {
        if ($this->enabled && $this->redis) {
            try {
                if (RedisCacheUtils::isRouteManagedByRestApi($request)) {
                    $cachable = RedisCacheUtils::isCacheableRestApi($request);
                } elseif (RedisCacheUtils::isRouteManagedByOrion($request)) {
                    $cachable = RedisCacheUtils::isCacheableOrion($request);
                }

                if (isset($cachable) && $cachable && $path = RedisCacheUtils::resolveMainTable($request)) {
                    $noCache = $request->input('cache.noCache') ?? $request->query('noCache') ?? false;
                    $updateCache = $request->input('cache.updateCache') ?? $request->query('updateCache') ?? false;
                    $keyCache = ":$this->appUuid:$this->appName:$path:".$request->method().":$this->userId:".md5(json_encode($request->input())).':'.md5(json_encode($request->query()));
                    $keyLS = ":$this->appUuid:$this->appName:$path:".$request->method().":$this->userId:".md5(json_encode($request->input())).':'.md5(json_encode($request->query()));
                    $keyWS = ":$this->appUuid:$this->appName:$path:".$request->method().":".md5(json_encode($request->input())).':'.md5(json_encode($request->query()));

                    if ($updateCache && is_array($updateCache)) {
                        foreach ($updateCache as $updateCacheKey) {
                            \RedisCache::delete(':'.$updateCacheKey.':');
                        }
                    }

                    if (empty($cachable) || !$cachable) {
                        \RedisCache::delete(':'.$path.':');

                        return $next($request);
                    }

                    if ($this->redis && $this->redis->exists($keyCache) && !$noCache) {
                        $cached = json_decode($this->redis->get($keyCache), true);
                        $cached['cache']['cached'] = true;

                        return response()->json($cached);
                    }

                    $response = $next($request);
                    if ($response->getStatusCode() === 200) {
                        $content = json_decode($response->getContent(), true);
                        $content['cache']['dateStored'] = Carbon::now()->toDateTimeString();
                        $content['cache']['pattern'] = $this->pattern;
                        $content['cache']['key']['cache'] = $this->prefix . $keyCache;
                        $content['cache']['key']['ls'] = md5($this->prefix . $keyLS);
                        $content['cache']['key']['ws'] = md5($this->prefix . $keyWS);
                        if ($content) {
                            $this->redis->setex($keyCache, 86400, json_encode($content));
                            return response()->json($content);
                        }
                    }

                    return $response;
                }

                return $next($request);
            } catch (\Throwable $e) {
                return $next($request);
            }
        }

        return $next($request);
    }
}
