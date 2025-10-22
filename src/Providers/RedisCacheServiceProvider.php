<?php

namespace RedisAdvancedCache\Providers;

use Illuminate\Support\ServiceProvider;
use RedisAdvancedCache\Services\RedisCacheService;

class RedisCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/redis-advanced-cache.php', 'redis-advanced-cache');

        $this->app->singleton('RedisCache', function () {
            return RedisCacheService::getInstance();
        });
    }

    public function boot(): void
    {
        $config = config('redis-advanced-cache');

        $this->publishes([
            __DIR__ . '/../../config/redis-advanced-cache.php' => config_path('redis-advanced-cache.php'),
        ], 'config');

        if (isset($config['debug']) && $config['debug'] === true) {
            \Log::info('=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=~=');
            \Log::info('');
            \Log::info('         [Redis Advanced Cache]');
            \Log::info('');
        }
    }
}
