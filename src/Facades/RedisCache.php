<?php

namespace RedisAdvancedCache\Facades;

use Illuminate\Support\Facades\Facade;

class RedisCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'RedisCache';
    }
}
