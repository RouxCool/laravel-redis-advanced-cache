<?php

namespace RedisAdvancedCache\Providers;

use Illuminate\Support\ServiceProvider;
use RedisAdvancedCache\Services\RedisCacheService;

class RedisCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/redis_advanced_cache.php', 'redis_advanced_cache');

        $this->app->singleton('RedisCache', function () {
            return new RedisCacheService();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/redis_advanced_cache.php' => config_path('redis_advanced_cache.php'),
        ], 'config');

        if (config('redis_advanced_cache.debug')) {
            \Log::info('=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=');
            \Log::info('');
            \Log::info('         [Redis Advanced Cache]');
            \Log::info('');
        }

        if (config('redis_advanced_cache.enabled') && config('redis_advanced_cache.listen_queries')) {
            try {
                app('RedisCache')->listenToWriteQueries();
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
