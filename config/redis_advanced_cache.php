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
    | Cache Pattern Identifier
    |--------------------------------------------------------------------------
    |
    | Defines the cache pattern or naming convention used for stored keys.
    | You can use this to separate cache data by application version,
    | environment, or pattern grouping.
    |
    */

    'pattern' => env('REDIS_ADVANCED_CACHE_PATTERN', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | The name of your application. This is used as part of the Redis key
    | prefix to help identify which app the cache data belongs to.
    |
    */

    'name' => env('REDIS_ADVANCED_CACHE_APP_NAME', 'myapp'),

    /*
    |--------------------------------------------------------------------------
    | Application UUID
    |--------------------------------------------------------------------------
    |
    | A unique identifier for your application instance. It is included in
    | the cache key to prevent collisions between different deployments or
    | projects that may share the same Redis instance.
    |
    */

    'uuid' => env('REDIS_ADVANCED_CACHE_APP_UUID', 'uuid'),

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

];
