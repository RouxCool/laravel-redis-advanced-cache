<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Redis Advanced Cache Enable
    |--------------------------------------------------------------------------
    |
    | This option enables or disables the Redis advanced cache system globally.
    | When set to "false", all caching logic is bypassed and no data will be
    | stored or retrieved from Redis.
    |
    */

    'enabled' => env('REDIS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to your Redis instance.
    | You can set host, port, password, database index, and scheme.
    |
    */

    'connection' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', 'null'),
        'database' => env('REDIS_DB', 1),
        'scheme' => env('REDIS_ADVANCED_CACHE_SCHEME', 'tcp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Pattern Identifier
    |--------------------------------------------------------------------------
    |
    | Defines the cache pattern or naming convention used for stored keys.
    | You can use this to separate cache data by application version,
    | environment, or pattern grouping.
    |
    | Default key pattern:
    | @PREFIX:@UUID:@NAME:$PATH:$METHOD:$USER_ID:$BODY_INPUT:$QUERY_INPUT
    |
    | Explanation:
    | - @PREFIX, @UUID, @NAME : these are static identifiers defined in the config
    |   section 'key.identifier'. They help distinguish cache keys between
    |   different applications, deployments, or environments.
    |
    | - $PATH, $METHOD, $USER_ID : dynamic values representing the request path,
    |   HTTP method, and the ID of the currently authenticated user (if any).
    |
    | - $BODY_INPUT : the serialized POST request body. This corresponds to the
    |   data sent in the request body (typically in JSON or form-data), not in
    |   the URL.
    |
    | - $QUERY_INPUT : the serialized GET query string. This corresponds to the
    |   parameters in the URL, e.g., in "http://url.com?param1=value1&param2=value2",
    |   $QUERY_INPUT would contain "param1=value1&param2=value2".
    |
    | Combining these static and dynamic parts ensures each cache key is
    | unique and properly scoped to the specific request and user.
    |
    */

    'pattern' => env('REDIS_ADVANCED_CACHE_PATTERN', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Key Configuration
    |--------------------------------------------------------------------------
    |
    | This section contains the configuration used to generate Redis cache keys.
    | - 'prefix', 'name', and 'uuid' help identify your application instance.
    |
    */

    'key_identifier' => [
        'prefix' => env('REDIS_PREFIX', 'XefiApp_local_'),
        'name' => env('APP_NAME', '-'),
        'uuid' => env('APP_UUID', '-'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Caching Rules
    |--------------------------------------------------------------------------
    |
    | This section defines fine-grained control over which routes can be
    | cached or excluded from caching.
    |
    | You can explicitly allow (whitelist) or exclude (blacklist) specific
    | routes or route patterns using wildcards (*).
    |
    | - Whitelisted routes will always be considered cacheable, even if other
    |   cache rules would normally exclude them.
    |
    | - Blacklisted routes will never be cached, regardless of other rules.
    |
    | Notes:
    |   • You can use '*' to match all routes (e.g., to enable global caching).
    |   • If a route matches both the whitelist and the blacklist, the blacklist
    |     always takes precedence.
    |
    | Examples:
    |   'api/v1/public/*'   → allows or excludes all sub-routes under /api/v1/public/
    |   'api/v1/admin/*'    → targets all admin routes
    |   'api/v1/secret'     → targets a specific endpoint only
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Listen to Database Write Queries
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will automatically listen to database write
    | operations (INSERT, UPDATE, DELETE) and invalidate related cache entries.
    | Disable this if you prefer to handle cache invalidation manually.
    |
    */

    'listen_queries' => env('REDIS_ADVANCED_CACHE_LISTEN_QUERIES', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Flush Options
    |--------------------------------------------------------------------------
    |
    | This section defines how Redis should handle cache invalidation when
    | detecting SQL joins. It allows fine-grained control over which table's
    | cache should be flushed when a write operation affects joined tables.
    |
    | Options:
    | - 'right_table' (bool) : Flush cache related to the right-hand table in a JOIN.
    | - 'left_table'  (bool) : Flush cache related to the left-hand table in a JOIN.
    | - 'on_left'     (bool) : Flush cache keys that match the left-hand column used in the JOIN.
    | - 'on_right'    (bool) : Flush cache keys that match the right-hand column used in the JOIN.
    |
    | This enables precise cache invalidation for complex SQL queries involving
    | multiple tables without flushing unrelated cache entries.
    |
    */

    'flush' => [
        'right_table' => env('REDIS_ADVANCED_CACHE_FLUSH_RIGHT_TABLE', true),
        'left_table' => env('REDIS_ADVANCED_CACHE_FLUSH_LEFT_TABLE', false),
        'on_left' => env('REDIS_ADVANCED_CACHE_FLUSH_ON_LEFT', false),
        'on_right' => env('REDIS_ADVANCED_CACHE_FLUSH_ON_RIGHT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Features
    |--------------------------------------------------------------------------
    |
    | This section defines which Redis caching features are enabled for
    | different APIs or subsystems of your application. Each key represents
    | a specific API or service, and the boolean value determines whether
    | caching is active for that particular API.
    |
    | Example usage:
    | - 'orion'   : enable caching for the Orion API routes.
    | - 'rest'    : enable caching for the REST API routes.
    | - 'others'  : enable caching for all other routes not explicitly listed
    |               above. This acts as a fallback for any remaining endpoints.
    |
    */

    'apis' => [
        'orion' => env('REDIS_ADVANCED_CACHE_API_ORION', true),
        'rest' => env('REDIS_ADVANCED_CACHE_API_REST', true),
        'others' => env('REDIS_ADVANCED_CACHE_API_OTHERS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Options
    |--------------------------------------------------------------------------
    |
    | This section allows you to configure additional cache behaviors.
    |
    | - 'cache_authenticated_only': if true, only cache requests for authenticated users.
    |   Requests from guests or unauthenticated sessions will bypass the cache.
    |   Default: true. Controlled via environment variable REDIS_ADVANCED_CACHE_AUTH_ONLY.
    |
    | - 'cache_flush_scan_count': the number of keys to scan at a time when
    |   flushing the Redis cache. Higher values may speed up cache invalidation
    |   but can increase memory usage. Controlled via environment variable
    |   REDIS_ADVANCED_CACHE_FLUSH_SCAN_COUNT. Default: 300.
    |
    */

    'options' => [
        'cache_authenticated_only' => env('REDIS_ADVANCED_CACHE_AUTH_ONLY', true),
        'cache_flush_scan_count' => env('REDIS_ADVANCED_CACHE_FLUSH_SCAN_COUNT', 300),
        'ttl' => env('REDIS_ADVANCED_CACHE_TTL', 86400),
    ],
];
