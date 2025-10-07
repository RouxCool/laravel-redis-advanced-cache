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

    'enabled' => env('REDIS_ADVANCED_CACHE_ENABLED', true),

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
        'host' => env('REDIS_ADVANCED_CACHE_HOST', '127.0.0.1'),
        'port' => env('REDIS_ADVANCED_CACHE_PORT', 6379),
        'password' => env('REDIS_ADVANCED_CACHE_PASSWORD', null),
        'database' => env('REDIS_ADVANCED_CACHE_DB', 0),
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
        'prefix' => env('REDIS_ADVANCED_CACHE_KEY_PREFIX', 'cache_'),
        'name' => env('REDIS_ADVANCED_CACHE_APP_NAME', 'myapp'),
        'uuid' => env('REDIS_ADVANCED_CACHE_APP_UUID', 'uuid'),
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
