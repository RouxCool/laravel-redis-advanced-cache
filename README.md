# ⚡ Redis Advanced Cache for Laravel

A Redis-based caching system for Laravel, designed to automatically cache API responses, manage query invalidation, and prevent cache collisions between environments.

Author : ``JORDAN Charly``
---

# 🚀 Features

- 🔹 **Global Redis Cache Enabled:** the advanced cache system is enabled by default (REDIS_ENABLED=true) and automatically intercepts API requests.
- 🔹 **Debug Mode Support:** detailed debugging can be toggled via REDIS_ADVANCED_CACHE_DEBUG=true.
- 🔹 **Custom Redis Connection:** fully configurable host, port, password, database index, and scheme through environment variables.
- 🔹 **Unique & Isolated Cache Keys:** each key is generated using the prefix (MyApp_local_), app name, UUID, request path, HTTP method, user ID, body, and query parameters — ensuring no collisions between environments.
- 🔹 **Smart Route Handling:**
    - ✅ Whitelist → all routes (*) are eligible for caching.
    - 🚫 Blacklist → specific routes like api/auth/login are never cached.
- 🔹 **Automatic Cache Invalidation:** database write operations (INSERT, UPDATE, DELETE) automatically clear related cache entries.
- 🔹 **Fine-Grained SQL Join Control:** when a JOIN query is detected, cache for the right-hand table is flushed automatically (flush.right_table=true).
- 🔹 **Multi-API Compatibility:**
    - Orion → ✅ enabled
    - REST → ✅ enabled
    - Other APIs → ❌ disabled
- 🔹 **Authenticated Requests Only:** only authenticated users’ requests are cached (cache_authenticated_only=true).
- 🔹 **Custom Cache Lifetime:** default TTL is set to 24 hours (86400 seconds).
- 🔹 **Performance-Tuned Flushing:** cache invalidation uses Redis SCAN in batches of 300 keys, balancing speed and memory efficiency.
- 🔹 **Automatic Model Resolution:** By default, the resolver automatically infers the correct Eloquent model — and therefore the main table name — based on the controller name handling the request.
- 🔹 **Header cache settings:**

---

# 📦 Installation

Install the package via Composer :

```bash
composer require rouxcool/laravel-redis-advanced-cache
```

publish configuration :
```
php artisan vendor:publish --tag=config --provider="RedisAdvancedCache\Providers\RedisCacheServiceProvider"
```

Link in **api.php** file
```
use RedisAdvancedCache\Middleware\RedisCacheManager;

Route::middleware(RedisCacheManager::class)->group(function () {
    <!-- Routes -->
});
```

or **app/Http/Kernel.php** file
```
'api' => [
    // ...
    \RedisAdvancedCache\Middleware\RedisCacheManager::class,
],
```

# 🧹 Cache Control — Disable & Flush

The Redis Advanced Cache system can be fully disabled or manually refreshed and flushed — directly from API queries, configuration, or Artisan commands.

## 🔻 **Disable Caching via URL or Request Body**

You can bypass caching on specific routes by sending a noCache parameter in the URL or request body.

- 🔹 **Disable cache for a request (QUERY parameter):**
```
https://website.com/api/users?noCache=1
```
- 🔹 **Disable cache for a request (JSON input):**
```
{
    "cache": {
        "noCache": true
    }
}
```

## ♻️ Force Cache Refresh for Specific Keys

You can force Redis to refresh specific cache entries using the updateCache parameter.
This is useful when you want to invalidate a subset of cached routes without flushing everything.

- 🔹 **Flush cache keys (JSON parameter):**
```
https://website.com/api/users?updateCache=users,posts,services
```
- 🔹 **Flush cache keys (JSON input):**
```
{
    "cache": {
        "updateCache": [
            "users",
            "posts",
            "articles"
        ]
    }
}
```

# 🧠 SDK Response When a Key Is Cached

When the SDK performs a request and the response is stored in cache, it returns a JSON object containing both the request data and cache metadata.
```
{
  "data": [
    // ...
  ],
  "cache": {
    "pattern": "@PREFIX:@UUID:@NAME:$PATH:$METHOD:$USER_ID:$BODY_INPUT:$QUERY_INPUT",
    "date_stored": "2025-01-01 00:00:00",
    "key": {
        "cache": "MyApp_local_:my_app_uuid:my_app_id:users:POST:9d2af22a-749f-4486-99d5-9ff40651c0f4:ad31168f10a1b0eea70fdc893202366e:dd458db91733e6de79974ad7235ccac2",
      "localstorage": "a99784671d053d496f6d6c6956d87189"
    }
  }
}
```

# ⚙️ Manual Cache Control

In addition to automatic API caching, the package also allows manual control of Redis cache entries via the RedisCacheService.
This is particularly useful when you need to store, invalidate, or flush specific cache keys programmatically.

## Available Methods:

🔹 ``get(?Request $request = null, ?string $key = null): ?array``
Return array keys from request keys or custom key.
```
use RedisAdvancedCache\Services\RedisCacheService;

$cache = app(RedisCacheService::class);

$resultsKeys = $cache->get($request);
$resultsKeys = $cache->get(null, 'users');
```
🔹 ``set(string $key, mixed $value, ?int $ttl = null): bool``
Store a custom value in Redis with an optional TTL (time-to-live).
```
use RedisAdvancedCache\Services\RedisCacheService;

$cache = app(RedisCacheService::class);

$cache->set('custom:user:data', ['id' => 12, 'name' => 'John'], 3600);
$cache->set('custom:token', 'abc123');
```
🔹 ``delete(string $key): bool``
Manually remove a specific cache entry.
```
use RedisAdvancedCache\Services\RedisCacheService;

$cache = app(RedisCacheService::class);
$cache->delete('custom:user:data');
```
🔹 ``flushAll(bool $onlyPrefixed = true): void``
Completely clear cached data.
By default, this only deletes keys starting with your app prefix (for example: MyApp_local_).
```
use RedisAdvancedCache\Services\RedisCacheService;

$cache = app(RedisCacheService::class);
$cache->flushAll(true);
$cache->flushAll(false);
```

# 🧩 Configuration

The configuration file allows you to control all aspects of caching. Example ``config/redis-advanced-cache.php``:

General configuration:
```
'enabled' => env('REDIS_ENABLED', true),
'debug' => env('REDIS_ADVANCED_CACHE_DEBUG', false),
```
Establish a connection:
```
'connection' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD', null),
    'database' => env('REDIS_DB', 1),
    'scheme' => env('REDIS_ADVANCED_CACHE_SCHEME', 'tcp'),
],
```
Edit pattern cache: 
_Default pattern is ``@PREFIX:@UUID:@NAME:$PATH:$METHOD:$USER_ID:$BODY_INPUT:$QUERY_INPUT``_
```
'pattern' => env('REDIS_ADVANCED_CACHE_PATTERN', 'default'),
```
Edit static key identifier:
```
'key_identifier' => [
    'prefix' => env('REDIS_PREFIX', 'MyApp_local_'),
    'name' => env('APP_NAME', 'myapp'),
    'uuid' => env('APP_UUID', 'uuid'),
],
```
Routes whitelists/blacklists:
```
'whitelists' => [
    'enabled' => env('REDIS_ADVANCED_CACHE_WHITELIST', false),
    'routes' => [
        '*',
    ],
],

'blacklists' => [
    'enabled' => env('REDIS_ADVANCED_CACHE_BLACKLIST', true),
    'routes' => [
        'api/auth/login',
    ],
],
```
Listen to database write queries for automatic cache invalidation:
```
'listen_queries' => env('REDIS_ADVANCED_CACHE_LISTEN_QUERIES', true),
```
Controller to Model Mapping
```
'controller_model_mapping' => [
    'Namespace\Controllers' => 'Namespace\Models'
],
```
API-specific cache toggles
```
'apis' => [
    'orion'  => env('REDIS_ADVANCED_CACHE_API_ORION', true),
    'rest'   => env('REDIS_ADVANCED_CACHE_API_REST', true),
    'others' => env('REDIS_ADVANCED_CACHE_API_OTHERS', false),
],
```
Flush queries toggles
```
'flush' => [
    'right_table' => env('REDIS_ADVANCED_CACHE_FLUSH_RIGHT_TABLE', true),
    'left_table' => env('REDIS_ADVANCED_CACHE_FLUSH_LEFT_TABLE', false),
    'on_left' => env('REDIS_ADVANCED_CACHE_FLUSH_ON_LEFT', false),
    'on_right' => env('REDIS_ADVANCED_CACHE_FLUSH_ON_RIGHT', false),
],
```
Advanced cache options:
```
'options' => [
    'cache_authenticated_only' => env('REDIS_ADVANCED_CACHE_AUTH_ONLY', true),
    'cache_flush_scan_count'   => env('REDIS_ADVANCED_CACHE_FLUSH_SCAN_COUNT', 300),
    'ttl'                      => env('REDIS_ADVANCED_CACHE_TTL', 86400),
],
```