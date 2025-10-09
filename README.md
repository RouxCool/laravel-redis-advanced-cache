# ⚡ Redis Advanced Cache for Laravel

A Redis-based caching system for Laravel, designed to automatically cache API responses, manage query invalidation, and prevent cache collisions between environments.

---

## 🚀 Features

- **Automatic API Caching:** Cache REST & Orion API responses automatically.  
- **Smart Cache Invalidation:** Clears relevant cache entries when database write queries occur (INSERT, UPDATE, DELETE).  
- **Granular Route Control:** Whitelist or blacklist specific routes using wildcards (*).  
- **Unique Cache Keys:** Generate keys using app name, UUID, user ID, request method, path, body, and query parameters.  
- **Multi-Environment Isolation:** Avoid conflicts across environments using prefixes and UUIDs.  
- **Flexible Configuration:** Adjust TTLs, scan batch size, authentication policies, and more.

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

    // Enable or disable Redis advanced cache globally
    'enabled' => env('REDIS_ENABLED', true),

    // Redis connection configuration
    'connection' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => env('REDIS_DB', 1),
        'scheme' => env('REDIS_ADVANCED_CACHE_SCHEME', 'tcp'),
    ],

    // Cache key pattern
    'pattern' => env('REDIS_ADVANCED_CACHE_PATTERN', 'default'),

    // Key identifiers
    'key_identifier' => [
        'prefix' => env('REDIS_PREFIX', 'MyApp_local_'),
        'name' => env('APP_NAME', 'myapp'),
        'uuid' => env('APP_UUID', 'uuid'),
    ],

    // Route whitelists (always cacheable)
    'whitelists' => [
        'enabled' => env('REDIS_ADVANCED_CACHE_WHITELIST', false),
        'routes' => [
            '*',
        ],
    ],

    // Route blacklists (never cacheable)
    'blacklists' => [
        'enabled' => env('REDIS_ADVANCED_CACHE_BLACKLIST', true),
        'routes' => [
            'api/auth/login',
        ],
    ],

    // Listen to database write queries for automatic cache invalidation
    'listen_queries' => env('REDIS_ADVANCED_CACHE_LISTEN_QUERIES', true),

    // API-specific cache toggles
    'apis' => [
        'orion'  => env('REDIS_ADVANCED_CACHE_API_ORION', true),
        'rest'   => env('REDIS_ADVANCED_CACHE_API_REST', true),
        'others' => env('REDIS_ADVANCED_CACHE_API_OTHERS', false),
    ],

    // Flush queries toggles
    'flush' => [
        'right_table' => env('REDIS_ADVANCED_CACHE_FLUSH_RIGHT_TABLE', true),
        'left_table' => env('REDIS_ADVANCED_CACHE_FLUSH_LEFT_TABLE', false),
        'on_left' => env('REDIS_ADVANCED_CACHE_FLUSH_ON_LEFT', false),
        'on_right' => env('REDIS_ADVANCED_CACHE_FLUSH_ON_RIGHT', false),
    ],

    // Advanced cache options
    'options' => [
        'cache_authenticated_only' => env('REDIS_ADVANCED_CACHE_AUTH_ONLY', true),
        'cache_flush_scan_count'   => env('REDIS_ADVANCED_CACHE_FLUSH_SCAN_COUNT', 300),
        'ttl'                      => env('REDIS_ADVANCED_CACHE_TTL', 86400),
    ],
