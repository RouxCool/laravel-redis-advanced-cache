# ⚡ Redis Advanced Cache for Laravel

A Redis-based caching system for Laravel, designed to automatically cache API responses, manage query invalidation, and prevent cache collisions between environments.

---

## 🚀 Features

- 🔹 **Global Redis Cache Enabled:** the advanced cache system is enabled by default (REDIS_ENABLED=true) and automatically intercepts API requests.
- 🔹 **Debug Mode Support:** detailed debugging can be toggled via REDIS_ADVANCED_CACHE_DEBUG=true.
- 🔹 **Custom Redis Connection:** fully configurable host, port, password, database index, and scheme through environment variables.
- 🔹 **Unique & Isolated Cache Keys:** each key is generated using the prefix (XefiApp_local_), app name, UUID, request path, HTTP method, user ID, body, and query parameters — ensuring no collisions between environments.
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
- 🔹**Performance-Tuned Flushing:** cache invalidation uses Redis SCAN in batches of 300 keys, balancing speed and memory efficiency.

---

## 📦 Installation

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

## 🧩 Configuration

The configuration file allows you to control all aspects of caching. Example ``config/redis-advanced-cache.php``:
```
    <!-- Enable or disable Redis advanced cache globally -->
    'enabled' => env('REDIS_ENABLED', true),

    <!-- Enable or disable debug mode (Laravel Log) -->
    'debug' => env('REDIS_ADVANCED_CACHE_DEBUG', false),

    <!-- Redis connection configuration -->
    'connection' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => env('REDIS_DB', 1),
        'scheme' => env('REDIS_ADVANCED_CACHE_SCHEME', 'tcp'),
    ],

    <!-- Cache key pattern -->
    'pattern' => env('REDIS_ADVANCED_CACHE_PATTERN', 'default'),

    <!-- Key identifiers -->
    'key_identifier' => [
        'prefix' => env('REDIS_PREFIX', 'MyApp_local_'),
        'name' => env('APP_NAME', 'myapp'),
        'uuid' => env('APP_UUID', 'uuid'),
    ],

    <!-- Route whitelists (always cacheable) -->
    'whitelists' => [
        'enabled' => env('REDIS_ADVANCED_CACHE_WHITELIST', false),
        'routes' => [
            '*',
        ],
    ],

    <!-- Route blacklists (never cacheable) -->
    'blacklists' => [
        'enabled' => env('REDIS_ADVANCED_CACHE_BLACKLIST', true),
        'routes' => [
            'api/auth/login',
        ],
    ],

    <!-- Listen to database write queries for automatic cache invalidation -->
    'listen_queries' => env('REDIS_ADVANCED_CACHE_LISTEN_QUERIES', true),

    <!-- API-specific cache toggles -->
    'apis' => [
        'orion'  => env('REDIS_ADVANCED_CACHE_API_ORION', true),
        'rest'   => env('REDIS_ADVANCED_CACHE_API_REST', true),
        'others' => env('REDIS_ADVANCED_CACHE_API_OTHERS', false),
    ],

    <!-- Flush queries toggles -->
    'flush' => [
        'right_table' => env('REDIS_ADVANCED_CACHE_FLUSH_RIGHT_TABLE', true),
        'left_table' => env('REDIS_ADVANCED_CACHE_FLUSH_LEFT_TABLE', false),
        'on_left' => env('REDIS_ADVANCED_CACHE_FLUSH_ON_LEFT', false),
        'on_right' => env('REDIS_ADVANCED_CACHE_FLUSH_ON_RIGHT', false),
    ],

    <!-- Advanced cache options -->
    'options' => [
        'cache_authenticated_only' => env('REDIS_ADVANCED_CACHE_AUTH_ONLY', true),
        'cache_flush_scan_count'   => env('REDIS_ADVANCED_CACHE_FLUSH_SCAN_COUNT', 300),
        'ttl'                      => env('REDIS_ADVANCED_CACHE_TTL', 86400),
    ],
```