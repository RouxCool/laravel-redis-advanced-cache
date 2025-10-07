# Redis Advanced Cache for Laravel

A powerful Redis-based caching system for Laravel applications, designed to automatically cache API responses, manage query invalidation, and prevent cache collisions between environments.

---

## 🚀 Features

- Automatic caching of API responses (REST & Orion controllers)
- Automatic cache invalidation on database write operations (INSERT, UPDATE, DELETE)
- Unique cache key generation using app name and UUID
- Configurable cache patterns per environment
- Simple facade and middleware for easy integration

---

## 📦 Installation

Install the package via Composer:

```bash
composer require your-username/laravel-redis-advanced-cache
php artisan vendor:publish --tag=config --provider="RedisAdvancedCache\Providers\RedisCacheServiceProvider"
