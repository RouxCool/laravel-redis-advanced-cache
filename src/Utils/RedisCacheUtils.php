<?php

namespace RedisAdvancedCache\Utils;

use App\Rest\Controller as RestBaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Orion\Http\Controllers\Controller as OrionBaseController;

class RedisCacheUtils
{
    /**
     * Determine if the request is cacheable.
     *
     * @param Request $request
     * @return bool
     */
    public static function isCachable(Request $request): bool
    {
        if (self::isRouteManagedByRestApi($request)) {
            return self::isCacheableRestApi($request);
        }
        if (self::isRouteManagedByOrion($request)) {
            return self::isCacheableOrion($request);
        }
        return false;
    }

    // ================================
    // REST API Cache Checks
    // ================================

    /**
     * Determine if a REST API request is cacheable.
     */
    public static function isCacheableRestApi(Request $request): bool
    {
        return auth()->check()
            && !self::isWriteOperationRestAPI($request)
            && config('redis_advanced_cache.apis.rest');
    }

    /**
     * Check if a REST API request is a write operation.
     * Only 'search' is considered a read operation here.
     */
    private static function isWriteOperationRestAPI(Request $request): bool
    {
        $segments = $request->route()?->getName() ?? '';
        $parts = explode('.', $segments);
        $end = end($parts);

        return !in_array($end, ['search']);
    }

    /**
     * Check if the current route is managed by a REST controller.
     */
    public static function isRouteManagedByRestApi(Request $request): bool
    {
        if (!class_exists(RestBaseController::class)) {
            return false;
        }

        $route = $request->route();
        if (!$route) {
            return false;
        }

        $controller = $route->getController();

        return $controller instanceof RestBaseController;
    }

    // ================================
    // Orion API Cache Checks
    // ================================

    /**
     * Determine if an Orion API request is cacheable.
     */
    public static function isCacheableOrion(Request $request): bool
    {
        return auth()->check()
            && !self::isWriteOperationOrion($request)
            && config('redis_advanced_cache.apis.orion');
    }

    /**
     * Check if an Orion API request is a write operation.
     * 'index', 'search', and 'show' are considered read operations.
     */
    private static function isWriteOperationOrion(Request $request): bool
    {
        $segments = $request->route()?->getName() ?? '';
        $parts = explode('.', $segments);
        $end = end($parts);

        return !in_array($end, ['index', 'search', 'show']);
    }

    /**
     * Check if the current route is managed by an Orion controller.
     */
    public static function isRouteManagedByOrion(Request $request): bool
    {
        if (!class_exists(OrionBaseController::class)) {
            return false;
        }

        $route = $request->route();
        if (!$route) {
            return false;
        }

        $controller = $route->getController();

        return $controller instanceof OrionBaseController;
    }

    // ================================
    // SQL Analysis for Relations and Tables
    // ================================

    /**
     * Extract related tables from a SQL query by analyzing JOIN clauses.
     *
     * @param string $sql
     */
    public static function extractRelationsFromSQL($sql): array
    {
        $joins = self::parseJoinsFromSql($sql);
        $relations = [];

        foreach ($joins as $join) {
            $table = $join['right_table'];

            if (preg_match('/_user$|^pivot|^model_has|^article_viewer|^media$/', $table)) {
                continue;
            }

            $tableParts = explode('.', $table);
            $relation = end($tableParts);

            if (!in_array($relation, $relations)) {
                $relations[] = $relation;
            }
        }

        return $relations;
    }

    /**
     * Parse JOIN clauses from a SQL query and return flushable tables
     * based on the configuration options: right_table, left_table, on_left, on_right.
     *
     * @param string $sql the SQL query to analyze
     *
     * @return array<string> list of tables or columns that should be flushed
     */
    public static function getFlushableTablesFromSql(string $sql): array
    {
        $joins = self::parseJoinsFromSql($sql);
        $flushConfig = config('redis_advanced_cache.flush', []);
        $flushables = [];

        foreach ($joins as $join) {
            if (($flushConfig['right_table'] ?? false) && !empty($join['right_table'])) {
                $flushables[] = $join['right_table'];
            }
            if (($flushConfig['left_table'] ?? false) && !empty($join['left_table'])) {
                $flushables[] = $join['left_table'];
            }
            if (($flushConfig['on_left'] ?? false) && !empty($join['on_left'])) {
                $flushables[] = $join['on_left'];
            }
            if (($flushConfig['on_right'] ?? false) && !empty($join['on_right'])) {
                $flushables[] = $join['on_right'];
            }
        }

        return array_unique($flushables);
    }

    /**
     * Parse JOIN clauses from a SQL query.
     *
     * @param string $sql the SQL query to analyze
     *
     * @return array<int, array<string, string>> Each join contains keys: operation, type, right_table, left_table, on_left, on_right
     */
    public static function parseJoinsFromSql(string $sql): array
    {
        $pattern = '/(JOIN|LEFT\s+JOIN|RIGHT\s+JOIN|INNER\s+JOIN|OUTER\s+JOIN)\s+([`"\[\]\w.-]+)\s+ON\s+([`"\[\]\w.-]+)\s*=\s*([`"\[\]\w.-]+)/ix';

        preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);

        $results = [];
        foreach ($matches as $match) {
            $results[] = [
                'operation' => self::detectWriteOperation($sql),
                'type' => strtoupper(trim($match[1])),
                'right_table' => trim($match[2], '`[]"'),
                'left_table' => explode('.', trim($match[3], '`[]"'))[0],
                'on_left' => trim($match[3], '`[]"'),
                'on_right' => trim($match[4], '`[]"'),
            ];
        }

        return $results;
    }

    /**
     * Detect if a SQL query is a write operation (INSERT, UPDATE, DELETE).
     */
    public static function detectWriteOperation(string $sql): ?string
    {
        $sql = ltrim($sql);

        if (stripos($sql, 'insert') === 0) {
            return 'INSERT';
        }
        if (stripos($sql, 'update') === 0) {
            return 'UPDATE';
        }
        if (stripos($sql, 'delete') === 0) {
            return 'DELETE';
        }

        return null;
    }

    /**
     * Extract the main table from a SQL query.
     */
    public static function getMainTable(string $sql): ?string
    {
        $sql = ltrim(strtolower($sql));

        if (preg_match('/^insert\s+into\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^update\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^delete\s+from\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Resolve the main table name from a Request based on its controller.
     */
    public static function resolveMainTable(Request $request): ?string
    {
        $action = $request->route()?->getActionName();

        if (!$action || !str_contains($action, '@')) {
            return null;
        }

        [$controller] = explode('@', $action);
        $controllerBaseName = class_basename($controller);
        $modelNameBase = Str::replaceLast('Controller', '', $controllerBaseName);

        $candidates = [
            Str::plural($modelNameBase),
            Str::singular($modelNameBase),
        ];

        foreach ($candidates as $modelName) {
            $modelClass = 'App\\Models\\'.$modelName;
            if (class_exists($modelClass)) {
                $model = new $modelClass();
                if (method_exists($model, 'getTable')) {
                    return $model->getTable();
                }
            }
        }

        return null;
    }

    // ================================
    // Whitelist/Blacklist
    // ================================

    /**
     * Check if a route is whitelisted.
     *
     * @param string $path
     * @return bool
     */
    public static function isWhitelisted(string $path): bool
    {
        $whitelist = config('redis_advanced_cache.whitelists', []);
        if (!($whitelist['enabled'] ?? false)) return false;

        foreach ($whitelist['routes'] ?? [] as $pattern) {
            if (RedisCacheUtils::matchPattern($pattern, $path)) return true;
        }
        return false;
    }

    /**
     * Check if a route is blacklisted.
     *
     * @param string $path
     * @return bool
     */
    public static function isBlacklisted(string $path): bool
    {
        $blacklist = config('redis_advanced_cache.blacklists', []);
        if (!($blacklist['enabled'] ?? false)) return false;

        foreach ($blacklist['routes'] ?? [] as $pattern) {
            if (RedisCacheUtils::matchPattern($pattern, $path)) return true;
        }
        return false;
    }

    // ================================
    // Miscs
    // ================================

    /**
     * Generate a unique cache key based on request parameters and configuration.
     */
    public static function generateCacheKey(string $path, string $method, int|string|null $userId = null, array $postBody = [], array $queryInput = []): string
    {
        $pattern = config('redis_advanced_cache.pattern');
        $identifier = config('redis_advanced_cache.key_identifier');

        if (!$pattern || $pattern === 'default') {
            $pattern = '@PREFIX:@UUID:@NAME:$PATH:$METHOD:$USER_ID:$BODY_INPUT:$QUERY_INPUT';
        }
        $key = str_replace(
            ['@PREFIX', '@UUID', '@NAME'],
            [$identifier['prefix'] ?? 'cache_', $identifier['uuid'] ?? 'uuid', $identifier['name'] ?? 'myapp'],
            $pattern
        );

        return str_replace(
            ['$PATH', '$METHOD', '$USER_ID', '$BODY_INPUT', '$QUERY_INPUT'],
            [
                $path,
                strtoupper($method),
                $userId ?? 'guest',
                !empty($postBody) ? md5(json_encode($postBody)) : '-',
                !empty($queryInput) ? md5(http_build_query($queryInput)) : '-',
            ],
            $key
        );
    }

    /**
     * Match a route path against a pattern.
     * Supports wildcard '*' at the end of the pattern.
     */
    public static function matchPattern(string $pattern, string $path): bool
    {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);

        return preg_match('/^'.$pattern.'$/', $path) === 1;
    }

    /**
     * Extract affected table names from a SQL query string.
     *
     * Detects whether the SQL statement is a write operation (INSERT, UPDATE, DELETE)
     * and extracts all tables that might be affected. These names are then
     * used for cache invalidation.
     *
     * @param string $sql the SQL query to analyze
     *
     * @return array<string> list of table names affected by the query
     */
    public static function getAffectedTables(string $sql): array
    {
        $operation = self::detectWriteOperation($sql);

        if (!$operation) {
            return [];
        }

        $relations = self::extractRelationsFromSQL($sql);
        $mainTable = self::getMainTable($sql);

        if ($mainTable) {
            $relations[] = $mainTable;
        }

        return array_unique($relations);
    }
}
